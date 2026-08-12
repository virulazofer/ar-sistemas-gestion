<?php

namespace App\Http\Controllers\Suppliers;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Supplier;
use App\Rules\Cuit;
use App\Services\AuditLogger;
use App\Services\Suppliers\SupplierLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function __construct(
        private readonly SupplierLedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->get('q', ''));
        $suppliers = Supplier::query()
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', "%{$q}%")
                    ->orWhere('business_name', 'like', "%{$q}%")
                    ->orWhere('cuit', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            }))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('suppliers.index', compact('suppliers', 'q'));
    }

    public function create(): View
    {
        return view('suppliers.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $supplier = Supplier::query()->create($data);
        $this->audit->log('supplier_created', $supplier, null, $supplier->toArray(), 'Proveedor creado');

        return redirect()->route('suppliers.show', $supplier)->with('status', 'Proveedor creado.');
    }

    public function show(Supplier $supplier): View
    {
        $balances = $this->ledger->balances($supplier);
        $entries = $supplier->ledgerEntries()->with(['currency', 'financialMovement', 'purchase'])->latest('entry_date')->latest('id')->paginate(20);
        $purchases = $supplier->purchases()->with('currency')->latest('purchase_date')->limit(10)->get();
        $supplier->load('documents');

        return view('suppliers.show', compact('supplier', 'balances', 'entries', 'purchases'));
    }

    public function edit(Supplier $supplier): View
    {
        return view('suppliers.edit', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $this->validated($request);
        $old = $supplier->toArray();
        $supplier->update($data);
        $this->audit->log('supplier_updated', $supplier, $old, $supplier->fresh()->toArray(), 'Proveedor actualizado');

        return redirect()->route('suppliers.show', $supplier)->with('status', 'Proveedor actualizado.');
    }

    public function storeDocument(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $file = $data['file'];
        $path = $file->store('documents/suppliers/'.$supplier->id, 'local');
        Document::query()->create([
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'documentable_type' => Supplier::class,
            'documentable_id' => $supplier->id,
            'uploaded_by' => $request->user()->id,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('status', 'Documento adjuntado.');
    }

    private function validated(Request $request): array
    {
        $partyType = (string) $request->input('party_type', 'particular');

        // Proveedores: identificación por CUIT (no DNI). Particular puede existir, pero sin DNI.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'party_type' => ['required', Rule::enum(\App\Enums\PartyType::class)],
            'business_name' => [
                Rule::requiredIf($partyType === \App\Enums\PartyType::Empresa->value),
                'nullable',
                'string',
                'max:180',
            ],
            'cuit' => [
                'required',
                'string',
                'max:20',
                new Cuit,
            ],
            'tax_condition' => ['required', Rule::enum(\App\Enums\TaxCondition::class)],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:180'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $data['cuit'] = Cuit::normalize($data['cuit'] ?? null);
        $data['dni'] = null;

        return $data;
    }
}
