<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\Subcategory;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
