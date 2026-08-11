<?php

namespace App\Http\Controllers\Finance;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Rules\CbuCvu;
use App\Rules\Cuit;
use App\Services\AuditLogger;
use App\Services\Finance\BalanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FinancialAccountController extends Controller
{
    public function __construct(
        private readonly BalanceService $balances,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $showInactive = $request->boolean('inactive');

        $accounts = FinancialAccount::query()
            ->with('currency')
            ->when(! $showInactive, fn ($q) => $q->where('status', 'active'))
            ->orderBy('name')
            ->get();

        foreach ($accounts as $account) {
            $account->setAttribute('computed_balance', $this->balances->computeAccountBalance($account->id));
        }

        return view('finance.accounts.index', compact('accounts', 'showInactive'));
    }

    public function create(): View
    {
        return view('finance.accounts.create', [
            'currencies' => Currency::query()->active()->orderBy('code')->get(),
            'types' => AccountType::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        if (! empty($data['card_pan_full'] ?? null)) {
            $this->audit->log('account_card_pan_attempt', null, null, [
                'name' => $data['name'] ?? null,
                'note' => 'Se intentó cargar PAN completo; no se almacena. Usar solo last4.',
            ], 'Intento de cargar PAN completo en cuenta tarjeta');
            unset($data['card_pan_full']);
        }

        $account = FinancialAccount::query()->create([
            ...$data,
            'cached_balance' => 0,
            'is_liability' => AccountType::from($data['type'])->isLiability(),
        ]);

        $this->audit->log('account_created', $account, null, $account->only([
            'name', 'type', 'currency_id', 'status', 'cbu_cvu', 'cuit', 'card_last4', 'card_brand',
        ]), 'Cuenta creada');

        return redirect()->route('accounts.index')->with('status', 'Cuenta creada.');
    }

    public function edit(FinancialAccount $financial_account): View
    {
        return view('finance.accounts.edit', [
            'account' => $financial_account,
            'currencies' => Currency::query()->active()->orderBy('code')->get(),
            'types' => AccountType::cases(),
        ]);
    }

    public function update(Request $request, FinancialAccount $financial_account): RedirectResponse
    {
        $data = $this->validated($request, updating: true);

        if (! empty($data['card_pan_full'] ?? null)) {
            $this->audit->log('account_card_pan_attempt', $financial_account, null, [
                'account_id' => $financial_account->id,
                'note' => 'Se intentó cargar PAN completo; no se almacena.',
            ], 'Intento de cargar PAN completo en cuenta tarjeta');
            unset($data['card_pan_full']);
        }

        $old = $financial_account->only([
            'name', 'type', 'status', 'description', 'cbu_cvu', 'cuit', 'card_last4', 'card_brand', 'card_holder',
        ]);
        $type = $data['type'] instanceof AccountType ? $data['type'] : AccountType::from($data['type']);
        $data['is_liability'] = $type->isLiability();

        $financial_account->update($data);
        $this->audit->log('account_updated', $financial_account, $old, $financial_account->only([
            'name', 'type', 'status', 'description', 'cbu_cvu', 'cuit', 'card_last4', 'card_brand', 'card_holder',
        ]), 'Cuenta actualizada');

        return redirect()->route('accounts.index')->with('status', 'Cuenta actualizada.');
    }

    private function validated(Request $request, bool $updating = false): array
    {
        $type = (string) $request->input('type');
        $isCard = $type === AccountType::CreditCard->value;
        $isBankish = in_array($type, [AccountType::Bank->value, AccountType::Wallet->value], true);

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'description' => ['nullable', 'string', 'max:1000'],
            'external_identifier' => ['nullable', 'string', 'max:120'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'cbu_cvu' => ['nullable', 'string', 'max:32', new CbuCvu],
            'cuit' => ['nullable', 'string', 'max:20', new Cuit],
            'card_last4' => [$isCard ? 'nullable' : 'nullable', 'digits:4'],
            'card_brand' => ['nullable', 'string', 'max:40'],
            'card_holder' => ['nullable', 'string', 'max:120'],
            'card_expiry_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'card_expiry_year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'card_pan_full' => ['nullable', 'string', 'max:32'], // nunca se guarda
        ];

        if (! $updating) {
            $rules['currency_id'] = ['required', 'exists:currencies,id'];
        }

        if ($isBankish) {
            // CBU/CVU recomendado pero no obligatorio para cuentas históricas
        }

        $data = $request->validate($rules);
        $data['cbu_cvu'] = CbuCvu::normalize($data['cbu_cvu'] ?? null);
        $data['cuit'] = Cuit::normalize($data['cuit'] ?? null);

        if (! $isCard) {
            $data['card_last4'] = null;
            $data['card_brand'] = null;
            $data['card_holder'] = null;
            $data['card_expiry_month'] = null;
            $data['card_expiry_year'] = null;
        }

        unset($data['card_pan_full']);

        return $data;
    }
}
