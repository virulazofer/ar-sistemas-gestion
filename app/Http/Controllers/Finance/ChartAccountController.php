<?php

namespace App\Http\Controllers\Finance;

use App\Enums\ChartAccountType;
use App\Enums\MovementStatus;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\Movement;
use App\Models\Subcategory;
use App\Services\Finance\ChartAccountAdminService;
use App\Services\Finance\ChartAccountMappingService;
use App\Services\Finance\ScopeOriginRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ChartAccountController extends Controller
{
    public function __construct(
        private readonly ChartAccountAdminService $admin,
        private readonly ChartAccountMappingService $mapping,
        private readonly ScopeOriginRules $scopes,
    ) {}

    public function index(Request $request): View
    {
        $dateFrom = $request->string('from')->toString() ?: null;
        $dateTo = $request->string('to')->toString() ?: null;
        $scope = $request->string('scope')->toString() ?: null;
        $selectedId = $request->integer('account') ?: null;

        $roots = ChartAccount::query()
            ->with(['children' => fn ($q) => $q->orderBy('sort_order')->orderBy('code')
                ->with(['children' => fn ($q2) => $q2->orderBy('sort_order')->orderBy('code')
                    ->with(['children' => fn ($q3) => $q3->orderBy('sort_order')->orderBy('code')])])])
            ->roots()
            ->where('is_active', true)
            ->get();

        $selected = $selectedId
            ? ChartAccount::query()->with('children')->find($selectedId)
            : $roots->first();

        $detail = $selected ? $this->detailPayload($selected, $dateFrom, $dateTo, $scope) : null;
        $unassignedMovements = $this->mapping->countMovementsWithoutAccount();
        $progress = $this->mapping->classificationProgress();
        $flatParents = ChartAccount::query()->orderBy('code')->get(['id', 'code', 'name', 'type']);

        return view('finance.chart_accounts.index', compact(
            'roots',
            'selected',
            'detail',
            'unassignedMovements',
            'progress',
            'dateFrom',
            'dateTo',
            'scope',
            'flatParents',
        ));
    }

    public function search(Request $request): JsonResponse
    {
        $type = $request->string('type')->toString() ?: null;
        if ($type === 'income') {
            $type = ChartAccountType::Income->value;
        } elseif ($type === 'expense') {
            $type = ChartAccountType::Expense->value;
        }

        return response()->json([
            'results' => $this->admin->search(
                $request->string('q')->toString(),
                $type,
                (int) $request->integer('limit', 40),
            ),
        ]);
    }

    public function suggestScope(Request $request): JsonResponse
    {
        $account = ChartAccount::query()->find($request->integer('chart_account_id'));

        return response()->json([
            'suggested_scope' => $this->scopes->suggestFromChartAccount($account),
            'field_label' => $this->scopes->fieldLabelForType($request->string('type', 'expense')->toString()),
        ]);
    }

    public function map(): RedirectResponse
    {
        return redirect()->route('chart-accounts.index')->with('status', 'El mapa quedó integrado en Plan de cuentas.');
    }

    public function mappingTool(): View
    {
        // Pantalla de compatibilidad (fuera del menú simplificado).
        $categories = Category::query()
            ->with(['subcategories', 'chartAccount'])
            ->orderBy('scope')
            ->orderBy('sort_order')
            ->get();
        $chartAccounts = ChartAccount::query()->orderBy('code')->get();
        $assistant = $this->mapping->unassignedAssistant();
        $typeDefaults = $this->mapping->typeDefaults();
        $unassignedMovements = $this->mapping->countMovementsWithoutAccount();
        $progress = $this->mapping->classificationProgress();
        $preview = session('chart_mapping_preview');
        $imputationRules = \App\Models\ImputationRule::query()
            ->with(['targetCategory', 'targetSubcategory', 'targetChartAccount', 'creator'])
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        return view('finance.chart_accounts.mapping', compact(
            'categories',
            'chartAccounts',
            'assistant',
            'typeDefaults',
            'unassignedMovements',
            'progress',
            'preview',
            'imputationRules',
        ));
    }

    public function saveMapping(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'target' => ['required', Rule::in(['category', 'subcategory'])],
            'id' => ['required', 'integer'],
            'chart_account_id' => ['nullable', 'exists:chart_accounts,id'],
        ]);

        $chartId = $data['chart_account_id'] ? (int) $data['chart_account_id'] : null;

        if ($data['target'] === 'category') {
            $cat = Category::query()->findOrFail($data['id']);
            $this->mapping->mapCategory($cat, $chartId);
        } else {
            $sub = Subcategory::query()->findOrFail($data['id']);
            $this->mapping->mapSubcategory($sub, $chartId);
        }

        return back()->with('status', 'Asignación al plan de cuentas guardada.');
    }

    public function saveTypeDefaults(Request $request): RedirectResponse
    {
        return redirect()->route('imputation-rules.index')
            ->with('status', 'Usá Reglas de clasificación automática (los defaults por tipo quedaron deprecados en UX).');
    }

    public function previewApply(Request $request): RedirectResponse
    {
        $overwriteManual = $request->boolean('overwrite_manual');
        $preview = $this->mapping->previewApplyToMovements(25, $overwriteManual);

        return redirect()
            ->route('chart-accounts.mapping')
            ->with('chart_mapping_preview', $preview)
            ->with('status', sprintf(
                'Vista previa: %d coinciden · %d manuales intactos · %d cambiarían · %d sin cambio.',
                $preview['matched'],
                $preview['manual'],
                $preview['would_change'],
                $preview['intact'],
            ));
    }

    public function applyMapping(Request $request): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']]);
        $overwriteManual = $request->boolean('overwrite_manual');
        $result = $this->mapping->applyToMovements($overwriteManual);

        return redirect()
            ->route('chart-accounts.mapping')
            ->with('status', sprintf(
                'Asignación aplicada: %d actualizados, %d sin cambio (%d manuales preservados).',
                $result['updated'],
                $result['skipped'],
                $result['manual_skipped'],
            ));
    }

    public function create(Request $request): View
    {
        return view('finance.chart_accounts.create', [
            'types' => ChartAccountType::structuralRoots(),
            'parents' => ChartAccount::query()->orderBy('code')->get(),
            'parentId' => $request->integer('parent_id') ?: null,
            'returnTo' => $request->string('return')->toString() ?: null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        try {
            $account = $this->admin->create($data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['code' => $e->getMessage()]);
        }

        return redirect()
            ->route('chart-accounts.index', ['account' => $account->id])
            ->with('status', 'Cuenta creada.');
    }

    public function edit(ChartAccount $chart_account): View
    {
        $usage = $this->admin->usage($chart_account);
        $chart_account->loadCount('children');

        return view('finance.chart_accounts.edit', [
            'account' => $chart_account,
            'types' => ChartAccountType::structuralRoots(),
            'parents' => ChartAccount::query()->where('id', '!=', $chart_account->id)->orderBy('code')->get(),
            'reassignTargets' => ChartAccount::query()
                ->where('id', '!=', $chart_account->id)
                ->orderBy('code')
                ->get(),
            'usage' => $usage,
        ]);
    }

    public function update(Request $request, ChartAccount $chart_account): RedirectResponse
    {
        $data = $this->validated($request, $chart_account->id);
        try {
            $this->admin->update($chart_account, $data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['parent_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('chart-accounts.index', ['account' => $chart_account->id])
            ->with('status', 'Cuenta actualizada.');
    }

    public function destroy(Request $request, ChartAccount $chart_account): RedirectResponse
    {
        $data = $request->validate([
            'disposition' => ['required', Rule::in(['reassign', 'unassign', 'cancel'])],
            'reassign_to' => [
                'nullable',
                'integer',
                Rule::exists('chart_accounts', 'id')->where(fn ($q) => $q->where('id', '!=', $chart_account->id)),
            ],
            'children_action' => ['nullable', Rule::in(['reparent', 'block'])],
        ]);

        try {
            $this->admin->delete($chart_account, $data);
        } catch (\Throwable $e) {
            return back()->withErrors(['disposition' => $e->getMessage()]);
        }

        if ($data['disposition'] === 'cancel') {
            return back()->with('status', 'Eliminación cancelada.');
        }

        return redirect()->route('chart-accounts.index')->with('status', 'Cuenta eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(ChartAccount $account, ?string $from, ?string $to, ?string $scope): array
    {
        $ids = $account->selfAndDescendantIds();
        $q = Movement::query()
            ->where('status', MovementStatus::Posted->value)
            ->whereIn('chart_account_id', $ids)
            ->when($from, fn ($qq) => $qq->whereDate('movement_date', '>=', $from))
            ->when($to, fn ($qq) => $qq->whereDate('movement_date', '<=', $to))
            ->when($scope, fn ($qq) => $qq->where('scope', $scope));

        $count = (clone $q)->count();
        $totalArs = (clone $q)->sum('amount_ars');

        $byScope = Movement::query()
            ->where('status', MovementStatus::Posted->value)
            ->whereIn('chart_account_id', $ids)
            ->when($from, fn ($qq) => $qq->whereDate('movement_date', '>=', $from))
            ->when($to, fn ($qq) => $qq->whereDate('movement_date', '<=', $to))
            ->selectRaw('scope, COUNT(*) as c, COALESCE(SUM(amount_ars),0) as total')
            ->groupBy('scope')
            ->get()
            ->mapWithKeys(fn ($r) => [
                (string) ($r->scope instanceof \BackedEnum ? $r->scope->value : $r->scope) => [
                    'count' => (int) $r->c,
                    'total_ars' => (string) $r->total,
                ],
            ])
            ->all();

        $movements = (clone $q)->with(['account', 'category', 'subcategory'])
            ->latest('movement_date')
            ->latest('id')
            ->limit(50)
            ->get();

        return [
            'usage' => $this->admin->usage($account),
            'path' => $account->pathLabel(),
            'count' => $count,
            'total_ars' => $totalArs,
            'by_scope' => $byScope,
            'movements' => $movements,
            'children' => $account->children,
            'financial_accounts' => FinancialAccount::query()->where('chart_account_id', $account->id)->orderBy('name')->get(),
        ];
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', Rule::unique('chart_accounts', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:180'],
            'type' => ['required', Rule::enum(ChartAccountType::class)],
            'parent_id' => ['nullable', 'exists:chart_accounts,id'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'help_text' => ['nullable', 'string', 'max:500'],
            'suggested_scope' => ['nullable', Rule::in(['personal', 'professional', 'mixed', 'financial'])],
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['sort_order'] = (int) ($data['sort_order'] ?? 100);
        if ($request->missing('is_active') && $ignoreId === null) {
            $data['is_active'] = true;
        }

        return $data;
    }
}
