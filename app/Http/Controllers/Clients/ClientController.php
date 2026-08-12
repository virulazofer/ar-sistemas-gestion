<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CommercialCharge;
use App\Models\Document;
use App\Rules\Cuit;
use App\Services\AuditLogger;
use App\Services\Clients\CcRegularizationService;
use App\Services\Clients\ClientCcTimelineService;
use App\Services\Clients\ClientCodeService;
use App\Services\Clients\ClientLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function __construct(
        private readonly ClientLedgerService $ledger,
        private readonly ClientCodeService $codes,
        private readonly CcRegularizationService $regularization,
        private readonly ClientCcTimelineService $timeline,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->get('q', ''));

        $clients = Client::query()
            ->when($q !== '', function ($query) use ($q) {
                $code = ltrim($q, '0');
                $query->where(function ($inner) use ($q, $code) {
                    $inner->where('name', 'like', "%{$q}%")
                        ->orWhere('business_name', 'like', "%{$q}%")
                        ->orWhere('cuit', 'like', "%{$q}%")
                        ->orWhere('dni', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                    if ($code !== '' && ctype_digit($code)) {
                        $inner->orWhere('code', (int) $code);
                    }
                    if (ctype_digit($q)) {
                        $inner->orWhere('code', (int) $q);
                    }
                });
            })
            ->orderBy('code')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('clients.index', compact('clients', 'q'));
    }

    public function create(): View
    {
        return view('clients.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        if (empty($data['code'])) {
            $data['code'] = $this->codes->allocateNext();
        }
        $client = Client::query()->create($data);
        $this->audit->log('client_created', $client, null, $client->toArray(), 'Cliente creado');

        return redirect()->route('clients.show', $client)->with('status', 'Cliente creado.');
    }

    public function show(Client $client, Request $request): View
    {
        $balances = $this->ledger->balances($client);

        $filter = (string) $request->get('cc_filter', ClientCcTimelineService::FILTER_ALL);
        $timeline = $this->timeline->paginate($client, $filter, 50);

        $openCharges = CommercialCharge::query()
            ->open()
            ->where('client_id', $client->id)
            ->orderBy('charged_on')
            ->get();

        $client->load('documents');

        return view('clients.show', [
            'client' => $client,
            'balances' => $balances,
            'timeline' => $timeline['items'],
            'timelineTotal' => $timeline['total'],
            'ccFilter' => $timeline['filter'],
            'ccFilters' => $timeline['filters'],
            'openCharges' => $openCharges,
        ]);
    }

    public function edit(Client $client): View
    {
        return view('clients.edit', compact('client'));
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $data = $this->validated($request, $client->id);
        try {
            $data['code'] = $this->codes->assertEditable(
                $client->code,
                $data['code'] ?? $client->code,
                $request->user()->can('clients.edit_code')
            );
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['code' => $e->getMessage()]);
        }
        $old = $client->toArray();
        $client->update($data);
        $this->audit->log('client_updated', $client, $old, $client->fresh()->toArray(), 'Cliente actualizado');

        return redirect()->route('clients.show', $client)->with('status', 'Cliente actualizado.');
    }

    public function regularize(Request $request, Client $client): RedirectResponse
    {
        // Defensa en profundidad: además de permission:clients.regularize, solo Administrador.
        if (! $request->user()?->hasRole('Administrador')) {
            abort(403, 'Regularizar CC es exclusivo de Administradores.');
        }

        $data = $request->validate([
            'currency_code' => ['required', Rule::in(['ARS', 'USD'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'sign' => ['required', Rule::in(['-1', '1'])],
            'reason' => ['required', 'string', 'max:1000'],
            'regularization_kind' => ['required', 'string', 'max:40'],
            'entry_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'related_ledger_entry_id' => ['nullable', 'exists:client_ledger_entries,id'],
        ]);
        $data['sign'] = (int) $data['sign'];

        try {
            $this->regularization->regularize($client, $data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('clients.show', $client)->with('status', 'Regularización de CC registrada (movimiento auditado).');
    }

    public function storeDocument(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $data['file'];
        $path = $file->store('documents/clients/'.$client->id, 'local');

        $doc = Document::query()->create([
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'documentable_type' => Client::class,
            'documentable_id' => $client->id,
            'uploaded_by' => $request->user()->id,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->audit->log('document_uploaded', $doc, null, [
            'client_id' => $client->id,
            'path' => $path,
        ], 'Documento asociado a cliente');

        return back()->with('status', 'Documento adjuntado.');
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $partyType = (string) $request->input('party_type', 'particular');

        $rules = [
            'name' => ['required', 'string', 'max:180'],
            'party_type' => ['required', Rule::enum(\App\Enums\PartyType::class)],
            'business_name' => [
                Rule::requiredIf($partyType === \App\Enums\PartyType::Empresa->value),
                'nullable',
                'string',
                'max:180',
            ],
            'cuit' => [
                Rule::requiredIf($partyType === \App\Enums\PartyType::Empresa->value),
                'nullable',
                'string',
                'max:20',
                new Cuit,
            ],
            'dni' => [
                Rule::requiredIf($partyType === \App\Enums\PartyType::Particular->value),
                'nullable',
                'string',
                'max:20',
                'regex:/^\d{7,8}$/',
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:180'],
            'address' => ['nullable', 'string', 'max:255'],
            'tax_condition' => ['required', Rule::enum(\App\Enums\TaxCondition::class)],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];

        if ($ignoreId !== null && $request->user()?->can('clients.edit_code')) {
            $rules['code'] = ['nullable', 'integer', 'min:1', Rule::unique('clients', 'code')->ignore($ignoreId)];
        } else {
            $rules['code'] = ['nullable', 'integer', 'min:1'];
        }

        $data = $request->validate($rules);
        $data['cuit'] = Cuit::normalize($data['cuit'] ?? null);

        return $data;
    }
}
