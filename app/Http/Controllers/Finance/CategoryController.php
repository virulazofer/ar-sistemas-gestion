<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\Movement;
use App\Models\Subcategory;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $categories = Category::query()
            ->with(['subcategories', 'chartAccount'])
            ->orderBy('scope')
            ->orderBy('sort_order')
            ->get();

        $chartAccounts = ChartAccount::query()->orderBy('code')->get();

        return view('finance.categories.index', compact('categories', 'chartAccounts'));
    }

    public function show(Request $request, Category $category): View
    {
        $category->load(['subcategories', 'chartAccount']);
        $from = $request->filled('from') ? Carbon::parse($request->string('from'))->startOfDay() : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->string('to'))->endOfDay() : now()->endOfDay();

        $movements = Movement::query()
            ->with(['account.currency'])
            ->where('category_id', $category->id)
            ->posted()
            ->whereDate('movement_date', '>=', $from->toDateString())
            ->whereDate('movement_date', '<=', $to->toDateString())
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        $totals = Movement::query()
            ->where('category_id', $category->id)
            ->posted()
            ->whereDate('movement_date', '>=', $from->toDateString())
            ->whereDate('movement_date', '<=', $to->toDateString())
            ->selectRaw('type, SUM(amount_ars) as total_ars, SUM(amount_usd) as total_usd, COUNT(*) as cnt')
            ->groupBy('type')
            ->get()
            ->keyBy(fn ($row) => $row->type instanceof \BackedEnum ? $row->type->value : (string) $row->getRawOriginal('type'));

        $driver = DB::getDriverName();
        $monthExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m', movement_date)"
            : "DATE_FORMAT(movement_date, '%Y-%m')";

        $monthly = Movement::query()
            ->where('category_id', $category->id)
            ->posted()
            ->whereDate('movement_date', '>=', $from->toDateString())
            ->whereDate('movement_date', '<=', $to->toDateString())
            ->selectRaw("{$monthExpr} as ym, SUM(amount_ars) as total_ars, COUNT(*) as cnt")
            ->groupBy('ym')
            ->orderBy('ym')
            ->get();

        $monthsCount = max(1, $monthly->count());
        $sumArs = (float) $monthly->sum('total_ars');
        $avgArs = $sumArs / $monthsCount;

        return view('finance.categories.show', compact(
            'category',
            'movements',
            'totals',
            'monthly',
            'from',
            'to',
            'avgArs',
            'sumArs',
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scope' => ['required', Rule::in(['personal', 'professional', 'both'])],
            'chart_account_id' => ['nullable', 'exists:chart_accounts,id'],
        ]);

        $category = Category::query()->create([
            ...$data,
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
            'chart_account_id' => ['nullable', 'exists:chart_accounts,id'],
        ]);

        $sub = Subcategory::query()->create([
            ...$data,
            'is_active' => true,
            'sort_order' => 100,
        ]);

        $this->audit->log('subcategory_created', $sub, null, $sub->toArray(), 'Subcategoría creada');

        return back()->with('status', 'Subcategoría creada.');
    }
}
