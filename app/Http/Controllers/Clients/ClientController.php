<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Document;
use App\Services\AuditLogger;
use App\Services\Clients\ClientLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function __construct(
        private readonly ClientLedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->get('q', ''));

        $clients = Client::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('name', 'like', "%{$q}%")
                        ->orWhere('business_name', 'like', "%{$q}%")
                        ->orWhere('cuit', 'like', "%{$q}%")
                        ->orWhere('dni', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                });
            })
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
        $client = Client::query()->create($data);
        $this->audit->log('client_created', $client, null, $client->toArray(), 'Cliente creado');

        return redirect()->route('clients.show', $client)->with('status', 'Cliente creado.');
    }

    public function show(Client $client): View
    {
        $balances = $this->ledger->balances($client);
        $entries = $client->ledgerEntries()
            ->with(['currency', 'financialMovement', 'user'])
            ->latest('entry_date')
            ->latest('id')
            ->paginate(25);
        $client->load('documents');

        return view('clients.show', compact('client', 'balances', 'entries'));
    }

    public function edit(Client $client): View
    {
        return view('clients.edit', compact('client'));
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $data = $this->validated($request, $client->id);
        $old = $client->toArray();
        $client->update($data);
        $this->audit->log('client_updated', $client, $old, $client->fresh()->toArray(), 'Cliente actualizado');

        return redirect()->route('clients.show', $client)->with('status', 'Cliente actualizado.');
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
        return $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'business_name' => ['nullable', 'string', 'max:180'],
            'cuit' => ['nullable', 'string', 'max:20'],
            'dni' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:180'],
            'address' => ['nullable', 'string', 'max:255'],
            'tax_condition' => ['nullable', 'string', 'max:64'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
