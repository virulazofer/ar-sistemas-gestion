<?php

namespace App\Http\Controllers\Finance;

use App\Enums\ChartAccountType;
use App\Enums\MovementScope;
use App\Enums\MovementType;
use App\Http\Controllers\Controller;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\FinancialAccount;
use App\Models\Movement;
use App\Models\Supplier;
use App\Services\Finance\ChartAccountUsageService;
use App\Services\Finance\MovementService;
use App\Services\Finance\ScopeOriginRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MovementController extends Controller
{
    public function __construct(
        private readonly MovementService $movements,
        private readonly ScopeOriginRules $scopeRules,
        private readonly ChartAccountUsageService $chartUsage,
    ) {}

    public function index(Request $request): View
    {
        $movements = Movement::query()
            ->with(['account.currency', 'category', 'subcategory', 'chartAccount', 'user'])
            ->when($request->filled('scope'), fn ($q) => $q->where('scope', $request->string('scope')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', (int) $request->input('category_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('movement_date', '>=', $request->string('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('movement_date', '<=', $request->string('date_to')))
            ->latest('movement_date')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('finance.movements.index', compact('movements'));
    }

    public function show(Movement $movement): View
    {
        Gate::authorize('view', $movement);

        $movement->load([
            'account.currency', 'category', 'subcategory', 'chartAccount',
            'user', 'exchangeRate', 'voidedByUser', 'client', 'supplier',
            'editAudits' => fn ($q) => $q->latest('created_at')->limit(50),
            'editAudits.user',
        ]);

        $pair = null;
        if ($movement->transfer_id) {
            $pair = Movement::query()
                ->with('account.currency')
                ->where('transfer_id', $movement->transfer_id)
                ->where('id', '!=', $movement->id)
                ->first();
        }

        $links = $this->movements->linkedRelations($movement);

        return view('finance.movements.show', compact('movement', 'pair', 'links'));
    }

    public function edit(Movement $movement): View
    {
        Gate::authorize('update', $movement);

        $movement->load(['account.currency', 'chartAccount', 'client', 'supplier', 'currency']);

        $accounts = FinancialAccount::query()->with('currency')->active()->orderBy('name')->get();
        $conceptAccounts = ChartAccount::query()
            ->active()
            ->whereIn('type', [ChartAccountType::Income->value, ChartAccountType::Expense->value])
            ->whereNotNull('parent_id')
            ->orderBy('code')
            ->get();
        $clients = Client::query()->active()->orderBy('code')->orderBy('name')->get();
        $suppliers = Supplier::query()->where('status', Supplier::STATUS_ACTIVE)->orderBy('name')->get();
        $usage = $this->chartUsage->forUser();
        $links = $this->movements->linkedRelations($movement);
        $fxPreview = $this->movements->historicalRatePreview(
            $movement->movement_date?->toDateString() ?? now()->toDateString()
        );

        return view('finance.movements.edit', [
            'movement' => $movement,
            'accounts' => $accounts,
            'conceptAccounts' => $conceptAccounts,
            'clients' => $clients,
            'suppliers' => $suppliers,
            'usage' => $usage,
            'links' => $links,
            'fxPreview' => $fxPreview,
            'scopeRules' => $this->scopeRules,
        ]);
    }

    public function update(Request $request, Movement $movement): RedirectResponse
    {
        Gate::authorize('update', $movement);

        $type = $request->input('type', $movement->type->value);
        $scopeAllowed = $type === MovementType::Income->value
            ? MovementScope::valuesForIncome()
            : ($type === MovementType::Expense->value
                ? MovementScope::valuesForExpense()
                : array_merge(MovementScope::valuesForExpense(), MovementScope::valuesForIncome()));

        $data = $request->validate([
            'movement_date' => ['required', 'date'],
            'movement_time' => ['nullable', 'date_format:H:i:s'],
            'type' => ['required', Rule::in(['income', 'expense', 'transfer_out', 'transfer_in'])],
            'scope' => ['required', Rule::in($scopeAllowed)],
            'description' => ['nullable', 'string', 'max:255'],
            'observations' => ['nullable', 'string', 'max:2000'],
            'chart_account_id' => ['nullable', 'exists:chart_accounts,id'],
            'financial_account_id' => ['required', 'exists:financial_accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'exchange_rate_value' => ['nullable', 'numeric', 'gt:0'],
            'fx_mode' => ['nullable', Rule::in(['recalculate', 'keep', ''])],
            'client_id' => ['nullable', 'exists:clients,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'edit_reason' => ['nullable', 'string', 'max:500'],
        ], [
            'amount.gt' => 'El importe debe ser mayor a cero.',
            'exchange_rate_value.gt' => 'La cotización debe ser mayor a cero.',
            'chart_account_id.exists' => 'La cuenta contable seleccionada no es válida.',
            'financial_account_id.required' => 'La cuenta financiera es obligatoria.',
        ]);

        try {
            $this->movements->update($movement, $data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['edit' => $e->getMessage()]);
        }

        return redirect()
            ->route('movements.show', $movement)
            ->with('status', 'Movimiento actualizado.');
    }

    public function void(Request $request, Movement $movement): RedirectResponse
    {
        Gate::authorize('void', $movement);

        $data = $request->validate([
            'void_reason' => ['required', 'string', 'max:500'],
        ], [
            'void_reason.required' => 'El motivo de anulación es obligatorio.',
        ]);

        try {
            $this->movements->void($movement, $data['void_reason']);
        } catch (\Throwable $e) {
            return back()->withErrors(['void_reason' => $e->getMessage()]);
        }

        return redirect()->route('movements.show', $movement)->with('status', 'Movimiento anulado.');
    }
}
