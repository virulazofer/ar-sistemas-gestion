<?php

namespace App\Http\Controllers\Finance;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\FinancialAccount;
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

    public function index(): View
    {
        $accounts = FinancialAccount::query()->with('currency')->orderBy('name')->get();
        foreach ($accounts as $account) {
            $account->setAttribute('computed_balance', $this->balances->computeAccountBalance($account->id));
        }

        return view('finance.accounts.index', compact('accounts'));
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
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'currency_id' => ['required', 'exists:currencies,id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'external_identifier' => ['nullable', 'string', 'max:120'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $account = FinancialAccount::query()->create([
            ...$data,
            'cached_balance' => 0,
        ]);

        $this->audit->log('account_created', $account, null, $account->only(['name', 'type', 'currency_id', 'status']), 'Cuenta creada');

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
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'description' => ['nullable', 'string', 'max:1000'],
            'external_identifier' => ['nullable', 'string', 'max:120'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $old = $financial_account->only(['name', 'type', 'status', 'description']);
        $financial_account->update($data);
        $this->audit->log('account_updated', $financial_account, $old, $financial_account->only(['name', 'type', 'status', 'description']), 'Cuenta actualizada');

        return redirect()->route('accounts.index')->with('status', 'Cuenta actualizada.');
    }
}
