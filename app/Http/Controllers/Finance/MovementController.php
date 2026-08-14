<?php

namespace App\Http\Controllers\Finance;

use App\Enums\ChartAccountType;
use App\Enums\MovementScope;
use App\Enums\MovementType;
use App\Http\Controllers\Controller;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\Movement;
use App\Models\Supplier;
use App\Services\Finance\ChartAccountUsageService;
use App\Services\Finance\MovementService;
use App\Services\Finance\ScopeOriginRules;
use App\Support\Money;
use App\Support\UiSemantics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MovementController extends Controller
{
    /** @var list<string> */
    private const SORTABLE = [
        'code',
        'date',
        'description',
        'chart_account',
        'scope',
        'financial_account',
        'amount',
    ];

    /** Primera dirección al clickear una columna distinta. */
    private const NATURAL_DIR = [
        'code' => 'asc',
        'date' => 'desc',
        'description' => 'asc',
        'chart_account' => 'asc',
        'scope' => 'asc',
        'financial_account' => 'asc',
        'amount' => 'desc',
    ];

    /** @var list<string> */
    private const INLINE_FIELDS = [
        'movement_date',
        'description',
        'chart_account_id',
        'scope',
        'financial_account_id',
        'amount',
    ];

    public function __construct(
        private readonly MovementService $movements,
        private readonly ScopeOriginRules $scopeRules,
        private readonly ChartAccountUsageService $chartUsage,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Movement::class);

        $sort = (string) $request->input('sort', 'date');
        if (! in_array($sort, self::SORTABLE, true)) {
            $sort = 'date';
        }
        $dir = strtolower((string) $request->input('dir', self::NATURAL_DIR[$sort] ?? 'desc'));
        $dir = $dir === 'asc' ? 'asc' : 'desc';

        $perPage = (int) $request->input('per_page', 25);
        $perPage = max(1, min(100, $perPage));

        $query = Movement::query()
            ->select('movements.*')
            ->with(['account.currency', 'category', 'subcategory', 'chartAccount', 'currency', 'user']);

        $this->applyFilters($query, $request);
        $this->applySort($query, $sort, $dir);

        $movements = $query
            ->paginate($perPage)
            ->withQueryString();

        $canInlineEdit = (bool) ($request->user()?->can('movements.edit')
            && $request->user()?->hasRole('Administrador'));

        $accounts = FinancialAccount::query()->with('currency')->active()->orderBy('name')->get();
        $currencies = Currency::query()->orderBy('code')->get();
        $conceptAccounts = ChartAccount::query()
            ->active()
            ->whereIn('type', [ChartAccountType::Income->value, ChartAccountType::Expense->value])
            ->whereNotNull('parent_id')
            ->orderBy('code')
            ->get();
        $usage = $canInlineEdit ? $this->chartUsage->forUser() : ['recent' => [], 'frequent' => []];

        $conceptsPayload = $conceptAccounts->map(fn (ChartAccount $c) => [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'type' => $c->type instanceof \BackedEnum ? $c->type->value : (string) $c->type,
            'path' => $c->pathLabel(),
            'suggested_scope' => $c->suggested_scope,
        ])->values();

        $accountsPayload = $accounts->map(fn (FinancialAccount $a) => [
            'id' => $a->id,
            'name' => $a->name,
            'currency' => $a->currency?->code,
        ])->values();

        $rows = $movements->getCollection()->map(function (Movement $m) {
            $signed = Money::mul((string) $m->amount, (string) $m->type->signedMultiplier());

            return [
                'id' => $m->id,
                'code' => $m->displayCode(),
                'show_url' => route('movements.show', $m),
                'inline_url' => route('movements.inline', $m),
                'movement_date' => $m->movement_date?->toDateString(),
                'movement_date_display' => $m->movement_date?->format('d/m/Y'),
                'description' => $m->description,
                'chart_account_id' => $m->chart_account_id,
                'chart_label' => $m->chartAccount
                    ? trim(($m->chartAccount->code ?? '').' '.($m->chartAccount->pathLabel() ?? ''))
                    : '—',
                'scope' => $m->scope->value,
                'scope_label' => $m->type->value === 'income'
                    ? ('Origen: '.$m->scope->label())
                    : $m->scope->label(),
                'type' => $m->type->value,
                'financial_account_id' => $m->financial_account_id,
                'financial_account_name' => $m->account?->name ?? '—',
                'amount' => (string) $m->amount,
                'amount_display' => number_format((float) $signed, 2, ',', '.'),
                'currency_code' => $m->account?->currency?->code ?? $m->currency?->code ?? '',
                'amount_class' => UiSemantics::cssClass($signed, UiSemantics::MODE_RESULT),
                'status' => $m->status->value,
                'is_posted' => $m->isPosted(),
                'is_transfer' => $m->isTransfer(),
                'editable' => $m->isPosted() && ! $m->isTransfer(),
            ];
        });

        $movements->setCollection($rows);

        return view('finance.movements.index', [
            'movements' => $movements,
            'sort' => $sort,
            'dir' => $dir,
            'canInlineEdit' => $canInlineEdit,
            'accounts' => $accounts,
            'currencies' => $currencies,
            'conceptsPayload' => $conceptsPayload,
            'accountsPayload' => $accountsPayload,
            'usage' => $usage,
            'naturalDir' => self::NATURAL_DIR,
            'scopeRules' => $this->scopeRules,
        ]);
    }

    public function show(Movement $movement): View
    {
        Gate::authorize('view', $movement);

        $movement->load([
            'account.currency', 'category', 'subcategory', 'chartAccount',
            'user', 'exchangeRate', 'voidedByUser', 'client', 'supplier', 'currency',
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

        $fxDate = $movement->exchange_rate_at
            ? $movement->exchange_rate_at->toDateString()
            : null;
        $movDate = $movement->movement_date?->toDateString();
        $fxMismatch = $fxDate && $movDate && $fxDate !== $movDate;

        return view('finance.movements.show', compact('movement', 'pair', 'links', 'fxMismatch', 'fxDate'));
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

        // Histórico: permitir conservar el ámbito actual aunque no esté en el set “nuevo”.
        if (! in_array($movement->scope->value, $scopeAllowed, true)) {
            $scopeAllowed[] = $movement->scope->value;
        }

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

    public function inlineUpdate(Request $request, Movement $movement): JsonResponse
    {
        Gate::authorize('update', $movement);

        if ($movement->isTransfer()) {
            return response()->json([
                'message' => 'Las transferencias no se editan inline. Usá el detalle o anulá y volvé a cargar.',
            ], 422);
        }

        $data = $request->validate([
            'field' => ['required', Rule::in(self::INLINE_FIELDS)],
            'value' => ['nullable'],
            'edit_reason' => ['nullable', 'string', 'max:500'],
            'fx_mode' => ['nullable', Rule::in(['recalculate', 'keep', ''])],
        ], [
            'field.required' => 'Indicá el campo a editar.',
            'field.in' => 'Campo no editable inline.',
        ]);

        $field = $data['field'];
        $value = $data['value'];
        $payload = [];

        try {
            $payload = match ($field) {
                'movement_date' => [
                    'movement_date' => (string) $value,
                    'fx_mode' => (string) ($data['fx_mode'] ?? ''),
                ],
                'description' => [
                    'description' => $value !== null ? (string) $value : null,
                ],
                'chart_account_id' => [
                    'chart_account_id' => $value !== null && $value !== '' ? (int) $value : null,
                ],
                'scope' => [
                    'scope' => (string) $value,
                ],
                'financial_account_id' => [
                    'financial_account_id' => (int) $value,
                ],
                'amount' => [
                    'amount' => $value,
                ],
                default => throw new \InvalidArgumentException('Campo no soportado.'),
            };
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! empty($data['edit_reason'])) {
            $payload['edit_reason'] = trim((string) $data['edit_reason']);
        }

        try {
            $updated = $this->movements->update($movement, $payload);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $needsFx = str_contains($msg, 'Recalcular') || str_contains($msg, 'Conservar');

            return response()->json([
                'message' => $msg,
                'needs_fx' => $needsFx,
            ], 422);
        }

        $updated->load(['account.currency', 'chartAccount', 'currency']);
        $signed = Money::mul((string) $updated->amount, (string) $updated->type->signedMultiplier());

        return response()->json([
            'ok' => true,
            'message' => 'Guardado ✓',
            'row' => [
                'id' => $updated->id,
                'code' => $updated->displayCode(),
                'movement_date' => $updated->movement_date?->toDateString(),
                'movement_date_display' => $updated->movement_date?->format('d/m/Y'),
                'description' => $updated->description,
                'chart_account_id' => $updated->chart_account_id,
                'chart_label' => $updated->chartAccount
                    ? trim(($updated->chartAccount->code ?? '').' '.($updated->chartAccount->pathLabel() ?? ''))
                    : '—',
                'scope' => $updated->scope->value,
                'scope_label' => $updated->type->value === 'income'
                    ? ('Origen: '.$updated->scope->label())
                    : $updated->scope->label(),
                'financial_account_id' => $updated->financial_account_id,
                'financial_account_name' => $updated->account?->name ?? '—',
                'amount' => (string) $updated->amount,
                'amount_display' => number_format((float) $signed, 2, ',', '.'),
                'currency_code' => $updated->account?->currency?->code ?? $updated->currency?->code ?? '',
                'amount_class' => UiSemantics::cssClass($signed, UiSemantics::MODE_RESULT),
            ],
        ]);
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

    private function applyFilters(Builder $query, Request $request): void
    {
        $query
            ->when($request->filled('scope'), fn ($q) => $q->where('movements.scope', $request->string('scope')))
            ->when($request->filled('type'), fn ($q) => $q->where('movements.type', $request->string('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('movements.status', $request->string('status')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('movements.category_id', (int) $request->input('category_id')))
            ->when($request->filled('chart_account_id'), fn ($q) => $q->where('movements.chart_account_id', (int) $request->input('chart_account_id')))
            ->when($request->filled('financial_account_id'), fn ($q) => $q->where('movements.financial_account_id', (int) $request->input('financial_account_id')))
            ->when($request->filled('currency_id'), fn ($q) => $q->where('movements.currency_id', (int) $request->input('currency_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('movements.movement_date', '>=', $request->string('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('movements.movement_date', '<=', $request->string('date_to')));

        if ($request->filled('q')) {
            $term = trim((string) $request->input('q'));
            $like = '%'.$term.'%';
            $query->where(function (Builder $q) use ($like, $term) {
                $q->where('movements.code', 'like', $like)
                    ->orWhere('movements.description', 'like', $like)
                    ->orWhere('movements.amount', 'like', $like)
                    ->orWhereHas('chartAccount', function (Builder $cq) use ($like) {
                        $cq->where('name', 'like', $like)
                            ->orWhere('code', 'like', $like);
                    })
                    ->orWhereHas('account', function (Builder $aq) use ($like) {
                        $aq->where('name', 'like', $like);
                    });

                if (preg_match('/^MOV-/i', $term)) {
                    $q->orWhere('movements.code', strtoupper($term));
                }
            });
        }
    }

    private function applySort(Builder $query, string $sort, string $dir): void
    {
        $needsChart = $sort === 'chart_account';
        $needsFa = $sort === 'financial_account';

        if ($needsChart) {
            $query->leftJoin('chart_accounts', 'chart_accounts.id', '=', 'movements.chart_account_id');
        }
        if ($needsFa) {
            $query->leftJoin('financial_accounts', 'financial_accounts.id', '=', 'movements.financial_account_id');
        }

        match ($sort) {
            'code' => $query->orderBy('movements.code', $dir)->orderByDesc('movements.id'),
            'date' => $query->orderBy('movements.movement_date', $dir)->orderByDesc('movements.id'),
            'description' => $query->orderBy('movements.description', $dir)->orderByDesc('movements.id'),
            'chart_account' => $query->orderBy('chart_accounts.name', $dir)->orderByDesc('movements.id'),
            'scope' => $query->orderBy('movements.scope', $dir)->orderByDesc('movements.id'),
            'financial_account' => $query->orderBy('financial_accounts.name', $dir)->orderByDesc('movements.id'),
            'amount' => $query->orderBy('movements.amount', $dir)->orderByDesc('movements.id'),
            default => $query->orderByDesc('movements.movement_date')->orderByDesc('movements.id'),
        };
    }
}
