<?php

namespace App\Http\Controllers\Sales;

use App\Enums\CommercialItemType;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Document;
use App\Models\Equipment;
use App\Models\FinancialAccount;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Sales\SaleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SaleController extends Controller
{
    public function __construct(private readonly SaleService $sales) {}

    public function index(): View
    {
        $sales = Sale::query()->with('client')->latest('id')->paginate(25);

        return view('sales.index', compact('sales'));
    }

    public function create(): View
    {
        return view('sales.create', [
            'clients' => Client::query()->where('status', 'active')->orderBy('name')->get(),
            'products' => Product::query()->where('type', 'physical')->where('status', 'active')->orderBy('name')->get(),
            'equipments' => Equipment::query()->orderBy('code')->get(),
            'users' => User::query()->orderBy('name')->get(),
            'itemTypes' => CommercialItemType::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'currency_code' => ['required', Rule::in(['ARS', 'USD'])],
            'sold_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'salesperson_id' => ['nullable', 'exists:users,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_type' => ['required', Rule::in(array_column(CommercialItemType::cases(), 'value'))],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.product_id' => ['nullable', 'exists:products,id'],
            'items.*.equipment_id' => ['nullable', 'exists:equipments,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $sale = $this->sales->create($data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['client_id' => $e->getMessage()]);
        }

        return redirect()->route('sales.show', $sale)->with('status', 'Venta '.$sale->number.' (borrador).');
    }

    public function show(Sale $sale): View
    {
        $sale->load(['client', 'items.product', 'items.equipment', 'quotation', 'chargeEntry', 'paymentEntry', 'documents']);

        return view('sales.show', [
            'sale' => $sale,
            'accounts' => FinancialAccount::query()->where('status', 'active')->with('currency')->orderBy('name')->get(),
        ]);
    }

    public function confirm(Request $request, Sale $sale): RedirectResponse
    {
        $data = $request->validate([
            'payment_mode' => ['required', Rule::in(['cash', 'credit'])],
            'financial_account_id' => ['nullable', 'exists:financial_accounts,id'],
        ]);

        try {
            $this->sales->confirm($sale, $data);
        } catch (\Throwable $e) {
            return back()->withErrors(['payment_mode' => $e->getMessage()]);
        }

        return back()->with('status', 'Venta confirmada.');
    }

    public function void(Request $request, Sale $sale): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->sales->void($sale, $data['reason']);
        } catch (\Throwable $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Venta anulada.');
    }

    public function storeDocument(Request $request, Sale $sale): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $file = $data['file'];
        $path = $file->store('documents/sales/'.$sale->id, 'local');
        Document::query()->create([
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'documentable_type' => Sale::class,
            'documentable_id' => $sale->id,
            'uploaded_by' => $request->user()->id,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('status', 'Documento adjuntado.');
    }
}
