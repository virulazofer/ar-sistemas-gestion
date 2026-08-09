<?php

namespace App\Http\Controllers\Quotations;

use App\Enums\CommercialItemType;
use App\Enums\QuotationStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Document;
use App\Models\Equipment;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Quotations\QuotationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class QuotationController extends Controller
{
    public function __construct(private readonly QuotationService $quotations) {}

    public function index(): View
    {
        $quotations = Quotation::query()->with('client')->latest('id')->paginate(25);

        return view('quotations.index', compact('quotations'));
    }

    public function create(): View
    {
        return view('quotations.create', [
            'clients' => Client::query()->where('status', 'active')->orderBy('name')->get(),
            'products' => Product::query()->where('status', 'active')->orderBy('name')->get(),
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
            'quoted_on' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'terms' => ['nullable', 'string'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'salesperson_id' => ['nullable', 'exists:users,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_type' => ['required', Rule::in(array_column(CommercialItemType::cases(), 'value'))],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.product_id' => ['nullable', 'exists:products,id'],
            'items.*.equipment_id' => ['nullable', 'exists:equipments,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.estimated_unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $q = $this->quotations->create($data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['client_id' => $e->getMessage()]);
        }

        return redirect()->route('quotations.show', $q)->with('status', 'Presupuesto '.$q->number.' creado.');
    }

    public function show(Quotation $quotation): View
    {
        $this->quotations->markExpiredIfNeeded($quotation);
        $quotation->load(['client', 'items.product', 'items.equipment', 'salesperson', 'convertedSale', 'documents']);

        return view('quotations.show', compact('quotation'));
    }

    public function send(Quotation $quotation): RedirectResponse
    {
        try {
            $this->quotations->changeStatus($quotation, QuotationStatus::Sent);
        } catch (\Throwable $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', 'Presupuesto marcado como enviado.');
    }

    public function accept(Quotation $quotation): RedirectResponse
    {
        try {
            $this->quotations->changeStatus($quotation, QuotationStatus::Accepted);
        } catch (\Throwable $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', 'Presupuesto aceptado.');
    }

    public function convert(Quotation $quotation): RedirectResponse
    {
        try {
            $sale = $this->quotations->convert($quotation);
        } catch (\Throwable $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->route('sales.show', $sale)->with('status', 'Convertido a venta '.$sale->number.' (borrador).');
    }

    public function renew(Request $request, Quotation $quotation): RedirectResponse
    {
        $data = $request->validate(['valid_until' => ['required', 'date', 'after:today']]);
        try {
            $this->quotations->renew($quotation, $data['valid_until']);
        } catch (\Throwable $e) {
            return back()->withErrors(['valid_until' => $e->getMessage()]);
        }

        return back()->with('status', 'Presupuesto renovado.');
    }

    public function cancel(Request $request, Quotation $quotation): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->quotations->cancel($quotation, $data['reason']);
        } catch (\Throwable $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Presupuesto cancelado.');
    }

    public function storeDocument(Request $request, Quotation $quotation): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $file = $data['file'];
        $path = $file->store('documents/quotations/'.$quotation->id, 'local');
        Document::query()->create([
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'documentable_type' => Quotation::class,
            'documentable_id' => $quotation->id,
            'uploaded_by' => $request->user()->id,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('status', 'Documento adjuntado.');
    }
}
