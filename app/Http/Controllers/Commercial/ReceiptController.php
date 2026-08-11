<?php

namespace App\Http\Controllers\Commercial;

use App\Enums\CommercialChargeType;
use App\Enums\DocumentalStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FinancialAccount;
use App\Models\Receipt;
use App\Services\Commercial\CommercialVoucherService;
use App\Services\Commercial\ReceiptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReceiptController extends Controller
{
    public function __construct(
        private readonly ReceiptService $receipts,
        private readonly CommercialVoucherService $vouchers,
    ) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->get('q', ''));
        $receipts = Receipt::query()
            ->with(['client', 'financialAccount'])
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('number', 'like', "%{$q}%")
                        ->orWhere('concept', 'like', "%{$q}%")
                        ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$q}%")->orWhere('code', 'like', '%'.ltrim($q, '0').'%'));
                });
            })
            ->latest('received_on')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('commercial.receipts.index', compact('receipts', 'q'));
    }

    public function create(Request $request): View
    {
        $clients = Client::query()->active()->orderBy('code')->orderBy('name')->get();
        $accounts = FinancialAccount::query()->with('currency')->active()->orderBy('name')->get();
        $preselect = (int) $request->get('client_id', 0);
        $openCharges = collect();
        $openDebt = '0.00';
        $currencyHint = 'ARS';

        if ($preselect > 0) {
            $client = Client::query()->find($preselect);
            if ($client) {
                $accountId = (int) $request->get('financial_account_id', 0);
                $account = $accountId ? $accounts->firstWhere('id', $accountId) : $accounts->first();
                $currencyHint = $account?->currency?->code ?? 'ARS';
                $openCharges = $this->receipts->openChargesFor($client, $currencyHint);
                $openDebt = $this->receipts->openDebtTotal($client, $currencyHint);
            }
        }

        return view('commercial.receipts.create', [
            'clients' => $clients,
            'accounts' => $accounts,
            'preselect' => $preselect,
            'openCharges' => $openCharges,
            'openDebt' => $openDebt,
            'currencyHint' => $currencyHint,
            'types' => CommercialChargeType::cases(),
            'documentalStatuses' => DocumentalStatus::cases(),
            'decision' => session('receipt_decision'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'financial_account_id' => ['required', 'exists:financial_accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'received_on' => ['required', 'date'],
            'concept' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'application_mode' => ['required', Rule::in(['auto', 'manual'])],
            'insufficient_option' => ['nullable', Rule::in([
                ReceiptService::OPTION_CREATE_CHARGE,
                ReceiptService::OPTION_ON_ACCOUNT,
                ReceiptService::OPTION_CANCEL,
            ])],
            'applications' => ['nullable', 'array'],
            'applications.*.commercial_charge_id' => ['required_with:applications', 'exists:commercial_charges,id'],
            'applications.*.amount' => ['required_with:applications', 'numeric', 'gt:0'],
            'missing_charge.charge_type' => ['nullable', Rule::enum(CommercialChargeType::class)],
            'missing_charge.concept' => ['nullable', 'string', 'max:255'],
            'missing_charge.charged_on' => ['nullable', 'date'],
            'missing_charge.scope' => ['nullable', Rule::in(['professional', 'personal'])],
            'missing_charge.documental_status' => ['nullable', Rule::enum(DocumentalStatus::class)],
        ]);

        if (($data['insufficient_option'] ?? null) === ReceiptService::OPTION_CANCEL) {
            return redirect()->route('receipts.create', ['client_id' => $data['client_id']])
                ->with('status', 'Cobro cancelado. No se registraron cambios.');
        }

        $data['applications'] = collect($data['applications'] ?? [])
            ->filter(fn ($row) => isset($row['amount']) && (float) $row['amount'] > 0)
            ->values()
            ->all();

        try {
            $result = $this->receipts->create($data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        if (! empty($result['requires_decision'])) {
            return redirect()
                ->route('receipts.create', [
                    'client_id' => $data['client_id'],
                    'financial_account_id' => $data['financial_account_id'],
                ])
                ->withInput()
                ->with('receipt_decision', [
                    'message' => $result['message'],
                    'open_debt' => $result['open_debt'],
                    'amount' => $data['amount'],
                ]);
        }

        return redirect()->route('receipts.show', $result['receipt'])
            ->with('status', 'Cobro registrado (finanzas + CC OUT + aplicaciones).');
    }

    public function show(Receipt $receipt): View
    {
        $receipt->load(['client', 'financialAccount', 'financialMovement', 'ledgerEntry', 'applications.charge', 'vouchers']);

        return view('commercial.receipts.show', compact('receipt'));
    }

    public function void(Request $request, Receipt $receipt): RedirectResponse
    {
        $data = $request->validate([
            'void_reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->receipts->void($receipt, $data['void_reason']);
        } catch (\Throwable $e) {
            return back()->withErrors(['void_reason' => $e->getMessage()]);
        }

        return redirect()->route('receipts.show', $receipt)->with('status', 'Cobro anulado (reversión).');
    }

    public function storeVoucher(Request $request, Receipt $receipt): RedirectResponse
    {
        $data = $request->validate([
            'voucher_type' => ['required', Rule::enum(\App\Enums\CommercialVoucherType::class)],
            'point_of_sale' => ['nullable', 'string', 'max:8'],
            'number' => ['nullable', 'string', 'max:40'],
            'issued_on' => ['nullable', 'date'],
            'amount' => ['nullable', 'numeric', 'gte:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->vouchers->associate($receipt, $data);

        return back()->with('status', 'Comprobante asociado al cobro.');
    }
}
