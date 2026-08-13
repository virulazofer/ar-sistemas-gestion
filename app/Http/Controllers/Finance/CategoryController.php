<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\Movement;
use App\Models\Subcategory;
use App\Services\AuditLogger;
use App\Services\Finance\CategoryReclassificationService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CategoryReclassificationService $reclass,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        // UX 11F: categorías operativas convergen con Plan de cuentas (compatibilidad dual-read).
        if (! $request->boolean('legacy')) {
            return redirect()
                ->route('chart-accounts.index')
                ->with('status', 'Las categorías financieras convergen con el Plan de cuentas. Vista legacy: ?legacy=1');
        }

        $from = $request->filled('from') ? Carbon::parse($request->string('from'))->startOfDay() : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->string('to'))->endOfDay() : now()->endOfDay();

        $categories = Category::query()
            ->with(['subcategories', 'chartAccount'])
            ->orderBy('scope')
            ->orderBy('sort_order')
            ->get();

        $chartAccounts = ChartAccount::query()->orderBy('code')->get();

        $catTotals = Movement::query()
            ->posted()
            ->whereNotNull('category_id')
            ->whereDate('movement_date', '>=', $from->toDateString())
            ->whereDate('movement_date', '<=', $to->toDateString())
            ->selectRaw('category_id, SUM(amount_ars) as total_ars, COUNT(*) as cnt')
            ->groupBy('category_id')
            ->get()
            ->keyBy('category_id');

        $subTotals = Movement::query()
            ->posted()
            ->whereNotNull('subcategory_id')
            ->whereDate('movement_date', '>=', $from->toDateString())
            ->whereDate('movement_date', '<=', $to->toDateString())
            ->selectRaw('subcategory_id, SUM(amount_ars) as total_ars, COUNT(*) as cnt')
            ->groupBy('subcategory_id')
            ->get()
            ->keyBy('subcategory_id');

        return view('finance.categories.index', compact(
            'categories',
            'chartAccounts',
            'from',
            'to',
            'catTotals',
            'subTotals',
        ));
    }

    public function show(Request $request, Category $category): View
    {
        $category->load(['subcategories.chartAccount', 'chartAccount']);
        [$from, $to, $movements, $totals, $monthly, $avgArs, $sumArs, $filters] = $this->analytics(
            $request,
            fn ($q) => $q->where('category_id', $category->id)
        );

        $subBreakdown = Movement::query()
            ->posted()
            ->where('category_id', $category->id)
            ->whereDate('movement_date', '>=', $from->toDateString())
            ->whereDate('movement_date', '<=', $to->toDateString())
            ->when($filters['scope'] ?? null, fn ($q) => $q->where('scope', $filters['scope']))
            ->when($filters['financial_account_id'] ?? null, fn ($q) => $q->where('financial_account_id', $filters['financial_account_id']))
            ->when(($filters['q'] ?? '') !== '', fn ($q) => $q->where('description', 'like', '%'.$filters['q'].'%'))
            ->selectRaw('subcategory_id, SUM(amount_ars) as total_ars, COUNT(*) as cnt')
            ->groupBy('subcategory_id')
            ->get()
            ->keyBy('subcategory_id');

        return view('finance.categories.show', compact(
            'category',
            'movements',
            'totals',
            'monthly',
            'from',
            'to',
            'avgArs',
            'sumArs',
            'subBreakdown',
            'filters',
        ));
    }

    public function showSubcategory(Request $request, Subcategory $subcategory): View
    {
        $subcategory->load(['category', 'chartAccount']);
        [$from, $to, $movements, $totals, $monthly, $avgArs, $sumArs, $filters] = $this->analytics(
            $request,
            fn ($q) => $q->where('subcategory_id', $subcategory->id)
        );

        return view('finance.categories.subcategory_show', compact(
            'subcategory',
            'movements',
            'totals',
            'monthly',
            'from',
            'to',
            'avgArs',
            'sumArs',
            'filters',
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scope' => ['required', Rule::in(['personal', 'professional', 'both'])],
        ]);

        $category = Category::query()->create([
            'name' => $data['name'],
            'scope' => $data['scope'],
            'chart_account_id' => null,
            'is_active' => true,
            'sort_order' => 100,
        ]);

        $this->audit->log('category_created', $category, null, $category->toArray(), 'Categoría creada');

        return back()->with('status', 'Categoría creada.');
    }

    public function storeSubcategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        $sub = Subcategory::query()->create([
            'category_id' => $data['category_id'],
            'name' => $data['name'],
            'chart_account_id' => null,
            'is_active' => true,
            'sort_order' => 100,
        ]);

        $this->audit->log('subcategory_created', $sub, null, $sub->toArray(), 'Subcategoría creada');

        return back()->with('status', 'Subcategoría creada.');
    }

    public function rename(Request $request, Category $category): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'confirm' => ['accepted'],
        ]);
        $preview = $this->reclass->previewRenameCategory($category, $data['name']);
        $this->reclass->renameCategory($category, $data['name']);

        return back()->with('status', "Categoría renombrada. Movimientos afectados: {$preview['movements']}.");
    }

    public function renameSubcategory(Request $request, Subcategory $subcategory): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'confirm' => ['accepted'],
        ]);
        $preview = $this->reclass->previewRenameSubcategory($subcategory, $data['name']);
        $this->reclass->renameSubcategory($subcategory, $data['name']);

        return back()->with('status', "Subcategoría renombrada. Movimientos afectados: {$preview['movements']}.");
    }

    public function previewMerge(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source_id' => ['required', 'exists:categories,id'],
            'target_id' => ['required', 'exists:categories,id', 'different:source_id'],
        ]);
        $source = Category::query()->findOrFail($data['source_id']);
        $target = Category::query()->findOrFail($data['target_id']);
        $preview = $this->reclass->previewMergeCategories($source, $target);

        return back()->with('category_merge_preview', array_merge($preview, $data))
            ->with('status', "Vista previa fusión: afectará {$preview['movements']} movimiento(s).");
    }

    public function merge(Request $request): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']]);
        $preview = session('category_merge_preview');
        if (! is_array($preview) || empty($preview['source_id']) || empty($preview['target_id'])) {
            return back()->withErrors(['confirm' => 'Primero generá la vista previa de fusión.']);
        }
        $source = Category::query()->findOrFail($preview['source_id']);
        $target = Category::query()->findOrFail($preview['target_id']);
        $result = $this->reclass->mergeCategories($source, $target);

        return redirect()->route('categories.index')
            ->with('status', "Fusión aplicada: {$result['moved_movements']} movimientos, {$result['moved_subcategories']} subcategorías.");
    }

    public function convertToSubcategory(Request $request, Category $category): RedirectResponse
    {
        $data = $request->validate([
            'target_subcategory_id' => ['required', 'exists:subcategories,id'],
            'confirm' => ['accepted'],
        ]);
        $target = Subcategory::query()->findOrFail($data['target_subcategory_id']);
        $preview = $this->reclass->previewConvertCategoryToSubcategory($category, $target);
        $result = $this->reclass->convertCategoryToSubcategory($category, $target);

        return back()->with('status', "Reubicación aplicada: {$result['moved_movements']} movimientos (preview: {$preview['movements']}).");
    }

    /**
     * @param  callable(\Illuminate\Database\Eloquent\Builder): \Illuminate\Database\Eloquent\Builder  $scope
     * @return array{0: Carbon, 1: Carbon, 2: mixed, 3: mixed, 4: mixed, 5: float, 6: float, 7: array}
     */
    private function analytics(Request $request, callable $scope): array
    {
        $from = $request->filled('from') ? Carbon::parse($request->string('from'))->startOfDay() : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->string('to'))->endOfDay() : now()->endOfDay();
        $movementScope = $request->string('scope')->toString() ?: null;
        $financialAccountId = $request->integer('financial_account_id') ?: null;
        $q = trim((string) $request->get('q', ''));

        $base = Movement::query()->posted()
            ->whereDate('movement_date', '>=', $from->toDateString())
            ->whereDate('movement_date', '<=', $to->toDateString())
            ->when($movementScope, fn ($query) => $query->where('scope', $movementScope))
            ->when($financialAccountId, fn ($query) => $query->where('financial_account_id', $financialAccountId))
            ->when($q !== '', fn ($query) => $query->where('description', 'like', "%{$q}%"));
        $base = $scope($base);

        $movements = (clone $base)
            ->with(['account.currency', 'category', 'subcategory', 'chartAccount'])
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        $totals = (clone $base)
            ->selectRaw('type, SUM(amount_ars) as total_ars, SUM(amount_usd) as total_usd, COUNT(*) as cnt')
            ->groupBy('type')
            ->get()
            ->keyBy(fn ($row) => $row->type instanceof \BackedEnum ? $row->type->value : (string) $row->getRawOriginal('type'));

        $driver = DB::getDriverName();
        $monthExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m', movement_date)"
            : "DATE_FORMAT(movement_date, '%Y-%m')";

        $monthly = (clone $base)
            ->selectRaw("{$monthExpr} as ym, SUM(amount_ars) as total_ars, COUNT(*) as cnt")
            ->groupBy('ym')
            ->orderBy('ym')
            ->get();

        $monthsCount = max(1, $monthly->count());
        $sumArs = (float) $monthly->sum('total_ars');
        $avgArs = $sumArs / $monthsCount;

        $filters = [
            'scope' => $movementScope,
            'financial_account_id' => $financialAccountId,
            'q' => $q,
            'accounts' => \App\Models\FinancialAccount::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
        ];

        return [$from, $to, $movements, $totals, $monthly, $avgArs, $sumArs, $filters];
    }
}
