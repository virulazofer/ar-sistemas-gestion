<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\FinancialAccount;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\Purchases\PurchaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PurchaseController extends Controller
{
    public function __construct(
        private readonly PurchaseService $purchases,
    ) {}

    public function index(Request $request): View
    {
        $purchases = Purchase::query()
            ->with(['supplier', 'currency'])
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->latest('purchase_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('purchases.index', compact('purchases'));
    }

    public function create(Request $request): View
    {
        $suppliers = Supplier::query()->where('status', 'active')->orderBy('name')->get();
        $accounts = FinancialAccount::query()->with('currency')->active()->orderBy('name')->get();
        $products = Product::query()->where('type', 'physical')->where('status', 'active')->orderBy('name')->get();
        $preselectedSupplier = $request->integer('supplier_id') ?: null;

        return view('purchases.create', compact('suppliers', 'accounts', 'products', 'preselectedSupplier'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'purchase_date' => ['required', 'date'],
            'voucher_type' => ['nullable', 'string', 'max:32'],
            'voucher_letter' => ['nullable', 'string', 'max:4'],
            'voucher_number' => ['nullable', 'string', 'max:64'],
            'currency_code' => ['required', Rule::in(['ARS', 'USD'])],
            'payment_mode' => ['required', Rule::in(['cash', 'credit'])],
            'financial_account_id' => ['nullable', 'required_if:payment_mode,cash', 'exists:financial_accounts,id'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'other_taxes' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit' => ['nullable', 'string', 'max:32'],
            'items.*.unit_price' => ['required', 'numeric', 'gt:0'],
            'items.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.sku' => ['nullable', 'string', 'max:64'],
            'items.*.product_id' => ['nullable', 'exists:products,id'],
        ]);

        try {
            $purchase = $this->purchases->create($data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['items' => $e->getMessage()]);
        }

        return redirect()->route('purchases.show', $purchase)->with('status', 'Compra registrada.');
    }

    public function show(Purchase $purchase): View
    {
        $purchase->load([
            'supplier',
            'currency',
            'items',
            'financialMovement',
            'financialAccount',
            'obligationLedgerEntry',
            'documents',
            'user',
        ]);

        return view('purchases.show', compact('purchase'));
    }

    public function void(Request $request, Purchase $purchase): RedirectResponse
    {
        $data = $request->validate([
            'void_reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->purchases->void($purchase, $data['void_reason']);
        } catch (\Throwable $e) {
            return back()->withErrors(['void_reason' => $e->getMessage()]);
        }

        return redirect()->route('purchases.show', $purchase)->with('status', 'Compra anulada.');
    }

    public function storeDocument(Request $request, Purchase $purchase): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $file = $data['file'];
        $path = $file->store('documents/purchases/'.$purchase->id, 'local');
        Document::query()->create([
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'documentable_type' => Purchase::class,
            'documentable_id' => $purchase->id,
            'uploaded_by' => $request->user()->id,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('status', 'Documento adjuntado.');
    }
}
