<?php

namespace App\Http\Controllers\Commercial;

use App\Enums\CommercialChargeType;
use App\Enums\DocumentalStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CommercialCharge;
use App\Services\Commercial\CommercialChargeService;
use App\Services\Commercial\CommercialVoucherService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CommercialChargeController extends Controller
{
    public function __construct(
        private readonly CommercialChargeService $charges,
        private readonly CommercialVoucherService $vouchers,
    ) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->get('q', ''));
        $status = (string) $request->get('status', '');

        $charges = CommercialCharge::query()
            ->with('client')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('number', 'like', "%{$q}%")
                        ->orWhere('concept', 'like', "%{$q}%")
                        ->orWhereHas('client', function ($c) use ($q) {
                            $c->where('name', 'like', "%{$q}%")
                                ->orWhere('code', 'like', ltrim($q, '0') !== '' ? ltrim($q, '0') : $q);
                        });
                });
            })
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest('charged_on')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('commercial.charges.index', compact('charges', 'q', 'status'));
    }

    public function create(Request $request): View
    {
        $clients = Client::query()->active()->orderBy('code')->orderBy('name')->get();
        $preselect = (int) $request->get('client_id', 0);

        return view('commercial.charges.create', [
            'clients' => $clients,
            'types' => CommercialChargeType::cases(),
            'documentalStatuses' => DocumentalStatus::cases(),
            'preselect' => $preselect,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'charge_type' => ['required', Rule::enum(CommercialChargeType::class)],
            'concept' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency_code' => ['required', Rule::in(['ARS', 'USD'])],
            'charged_on' => ['required', 'date'],
            'due_on' => ['nullable', 'date'],
            'scope' => ['required', Rule::in(['professional', 'personal'])],
            'documental_status' => ['required', Rule::enum(DocumentalStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'work_order_id' => ['nullable', 'exists:work_orders,id'],
            'apply_available_credit' => ['nullable', 'boolean'],
        ]);

        try {
            $charge = $this->charges->create([
                ...$data,
                'apply_available_credit' => $request->boolean('apply_available_credit', true),
            ]);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('charges.show', $charge)->with('status', 'Cargo comercial creado (CC IN).');
    }

    public function show(CommercialCharge $charge): View
    {
        $charge->load(['client', 'ledgerEntry', 'applications.receipt', 'vouchers', 'sale', 'subscription', 'workOrder']);

        return view('commercial.charges.show', compact('charge'));
    }

    public function void(Request $request, CommercialCharge $charge): RedirectResponse
    {
        $data = $request->validate([
            'void_reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->charges->void($charge, $data['void_reason']);
        } catch (\Throwable $e) {
            return back()->withErrors(['void_reason' => $e->getMessage()]);
        }

        return redirect()->route('charges.show', $charge)->with('status', 'Cargo anulado (reversión CC).');
    }

    public function storeVoucher(Request $request, CommercialCharge $charge): RedirectResponse
    {
        $data = $request->validate([
            'voucher_type' => ['required', Rule::enum(\App\Enums\CommercialVoucherType::class)],
            'point_of_sale' => ['nullable', 'string', 'max:8'],
            'number' => ['nullable', 'string', 'max:40'],
            'issued_on' => ['nullable', 'date'],
            'amount' => ['nullable', 'numeric', 'gte:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->vouchers->associate($charge, $data);

        return back()->with('status', 'Comprobante asociado sin duplicar el cargo.');
    }
}
