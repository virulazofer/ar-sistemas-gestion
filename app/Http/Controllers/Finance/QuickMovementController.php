<?php

namespace App\Http\Controllers\Finance;

use App\Enums\CommercialChargeType;
use App\Enums\DocumentalStatus;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Client;
use App\Models\FinancialAccount;
use App\Services\Commercial\ReceiptService;
use App\Services\Finance\ExchangeRateService;
use App\Services\Finance\MovementService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class QuickMovementController extends Controller
{
    public const OPTION_ON_ACCOUNT = 'on_account';

    public const OPTION_CREATE_CHARGE = 'create_charge';

    public const OPTION_INCOME_ONLY = 'income_only';

    public const OPTION_CANCEL = 'cancel';

    public function __construct(
        private readonly MovementService $movements,
        private readonly ExchangeRateService $rates,
        private readonly ReceiptService $receipts,
    ) {}

    public function create(Request $request): View
    {
        $accounts = FinancialAccount::query()->with('currency')->active()->orderBy('name')->get();
        $categories = Category::query()->with(['subcategories' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->active()
            ->orderBy('sort_order')
            ->get();
        $clients = Client::query()->active()->orderBy('code')->orderBy('name')->get();

        $rateInfo = null;
        try {
            $rateInfo = $this->rates->latestOfficialSell(trySync: false);
        } catch (\Throwable) {
            // UI muestra aviso
        }

        $preselectClient = (int) $request->get('client_id', old('client_id', 0));
        $accountId = (int) $request->get('financial_account_id', old('financial_account_id', 0));
        $account = $accountId ? $accounts->firstWhere('id', $accountId) : $accounts->first();
        $currencyHint = $account?->currency?->code ?? 'ARS';
        $openCharges = collect();
        $openDebt = '0.00';

        if ($preselectClient > 0) {
            $client = Client::query()->find($preselectClient);
            if ($client) {
                $openCharges = $this->receipts->openChargesFor($client, $currencyHint);
                $openDebt = $this->receipts->openDebtTotal($client, $currencyHint);
            }
        }

        return view('finance.quick', [
            'accounts' => $accounts,
            'categories' => $categories,
            'clients' => $clients,
            'rateInfo' => $rateInfo,
            'decision' => session('quick_income_decision'),
            'openCharges' => $openCharges,
            'openDebt' => $openDebt,
            'currencyHint' => $currencyHint,
            'preselectClient' => $preselectClient,
            'types' => CommercialChargeType::cases(),
            'documentalStatuses' => DocumentalStatus::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $type = $request->input('type');

        if ($type === 'transfer') {
            $data = $request->validate([
                'scope' => ['required', Rule::in(['personal', 'professional'])],
                'from_account_id' => ['required', 'exists:financial_accounts,id'],
                'to_account_id' => ['required', 'exists:financial_accounts,id', 'different:from_account_id'],
                'amount' => ['required', 'numeric', 'gt:0'],
                'movement_date' => ['required', 'date'],
                'description' => ['nullable', 'string', 'max:255'],
            ]);

            try {
                $pair = $this->movements->createTransfer([
                    'from_account_id' => (int) $data['from_account_id'],
                    'to_account_id' => (int) $data['to_account_id'],
                    'amount' => $data['amount'],
                    'scope' => $data['scope'],
                    'movement_date' => $data['movement_date'],
                    'description' => $data['description'] ?? 'Transferencia',
                ]);
            } catch (\Throwable $e) {
                return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
            }

            $from = FinancialAccount::query()->find($data['from_account_id']);
            $to = FinancialAccount::query()->find($data['to_account_id']);

            return redirect()->route('movements.quick')->with(
                'status',
                sprintf(
                    'Transferencia %s → %s · %s',
                    $from?->name ?? 'origen',
                    $to?->name ?? 'destino',
                    number_format((float) $data['amount'], 2, ',', '.')
                )
            );
        }

        $data = $request->validate([
            'type' => ['required', Rule::in(['income', 'expense'])],
            'scope' => ['required', Rule::in(['personal', 'professional'])],
            'financial_account_id' => ['required', 'exists:financial_accounts,id'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'subcategory_id' => ['nullable', 'exists:subcategories,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'movement_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'apply_to_cc' => ['nullable', 'boolean'],
            'insufficient_option' => ['nullable', Rule::in([
                self::OPTION_ON_ACCOUNT,
                self::OPTION_CREATE_CHARGE,
                self::OPTION_INCOME_ONLY,
                self::OPTION_CANCEL,
            ])],
            'missing_charge.charge_type' => ['nullable', Rule::enum(CommercialChargeType::class)],
            'missing_charge.concept' => ['nullable', 'string', 'max:255'],
            'missing_charge.charged_on' => ['nullable', 'date'],
            'missing_charge.scope' => ['nullable', Rule::in(['professional', 'personal'])],
            'missing_charge.documental_status' => ['nullable', Rule::enum(DocumentalStatus::class)],
        ]);

        $account = FinancialAccount::query()->with('currency')->findOrFail($data['financial_account_id']);
        $applyToCc = $request->boolean('apply_to_cc') && ($data['type'] === 'income') && ! empty($data['client_id']);

        if ($applyToCc) {
            $option = $data['insufficient_option'] ?? null;

            if ($option === self::OPTION_CANCEL) {
                return redirect()->route('movements.quick')->with('status', 'Operación cancelada. No se registraron cambios.');
            }

            if ($option === self::OPTION_INCOME_ONLY) {
                return $this->storeSimpleIncome($data, $account, confirmIncomeOnly: true);
            }

            try {
                $result = $this->receipts->create([
                    'client_id' => (int) $data['client_id'],
                    'financial_account_id' => (int) $data['financial_account_id'],
                    'amount' => $data['amount'],
                    'received_on' => $data['movement_date'],
                    'concept' => $data['description'] ?: ('Cobro cliente #'.$data['client_id']),
                    'application_mode' => 'auto',
                    'insufficient_option' => match ($option) {
                        self::OPTION_ON_ACCOUNT => ReceiptService::OPTION_ON_ACCOUNT,
                        self::OPTION_CREATE_CHARGE => ReceiptService::OPTION_CREATE_CHARGE,
                        default => null,
                    },
                    'missing_charge' => $data['missing_charge'] ?? null,
                ]);
            } catch (\Throwable $e) {
                return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
            }

            if (! empty($result['requires_decision'])) {
                return redirect()
                    ->route('movements.quick', [
                        'client_id' => $data['client_id'],
                        'financial_account_id' => $data['financial_account_id'],
                    ])
                    ->withInput()
                    ->with('quick_income_decision', [
                        'message' => $result['message'] ?? 'No hay deuda abierta suficiente en CC.',
                        'open_debt' => $result['open_debt'] ?? '0.00',
                        'amount' => Money::normalize($data['amount']),
                        'client_id' => $data['client_id'],
                        'apply_to_cc' => true,
                    ]);
            }

            $receipt = $result['receipt'];
            $label = $account->is_liability || $account->type?->value === 'credit_card'
                ? 'Cuenta acreditada (pasivo)'
                : 'Cuenta acreditada';

            return redirect()->route('movements.quick')->with(
                'status',
                sprintf(
                    'Cobro %s · 1 ingreso financiero · %s: %s · %s %s',
                    $receipt->number,
                    $label,
                    $account->name,
                    number_format((float) $receipt->amount, 2, ',', '.'),
                    $receipt->currency_code
                )
            );
        }

        return $this->storeSimpleIncome($data, $account, confirmIncomeOnly: false);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeSimpleIncome(array $data, FinancialAccount $account, bool $confirmIncomeOnly): RedirectResponse
    {
        try {
            $movement = $this->movements->createSimple([
                'type' => $data['type'],
                'scope' => $data['scope'],
                'financial_account_id' => (int) $data['financial_account_id'],
                'category_id' => $data['category_id'] ?? null,
                'subcategory_id' => $data['subcategory_id'] ?? null,
                'amount' => $data['amount'],
                'movement_date' => $data['movement_date'],
                'description' => $data['description'] ?? null,
                'client_id' => $data['client_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        $isIncome = $data['type'] === 'income';
        $isLiability = $account->is_liability || $account->type?->value === 'credit_card';
        if ($isIncome) {
            $acctLabel = $isLiability ? 'Cuenta acreditada (pasivo)' : 'Cuenta acreditada';
        } else {
            $acctLabel = $isLiability ? 'Cuenta debitada (pasivo tarjeta)' : 'Cuenta debitada';
        }

        $suffix = $confirmIncomeOnly ? ' · ingreso solo (sin inventar deuda CC)' : '';

        return redirect()->route('movements.quick')->with(
            'status',
            sprintf(
                '%s · %s: %s · %s %s%s',
                $isIncome ? 'Ingreso registrado' : 'Gasto registrado',
                $acctLabel,
                $account->name,
                number_format((float) $movement->amount, 2, ',', '.'),
                $account->currency?->code ?? '',
                $suffix
            )
        );
    }
}
