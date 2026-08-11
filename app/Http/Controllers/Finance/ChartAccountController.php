<?php

namespace App\Http\Controllers\Finance;

use App\Enums\ChartAccountType;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\Movement;
use App\Models\Subcategory;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ChartAccountController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $roots = ChartAccount::query()
            ->with(['children.children.children'])
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();

        $flat = ChartAccount::query()->orderBy('code')->get();

        return view('finance.chart_accounts.index', compact('roots', 'flat'));
    }

    public function map(): View
    {
        $roots = ChartAccount::query()
            ->with(['children.children' => fn ($q) => $q->orderBy('code')])
            ->whereNull('parent_id')
            ->orderBy('code')
            ->get();

        return view('finance.chart_accounts.map', compact('roots'));
    }

    public function create(Request $request): View
    {
        return view('finance.chart_accounts.create', [
            'types' => ChartAccountType::cases(),
            'parents' => ChartAccount::query()->orderBy('code')->get(),
            'parentId' => $request->integer('parent_id') ?: null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $account = ChartAccount::query()->create($data);
        $this->audit->log('chart_account_created', $account, null, $account->toArray(), 'Cuenta contable creada');

        return redirect()->route('chart-accounts.index')->with('status', 'Cuenta creada.');
    }

    public function edit(ChartAccount $chart_account): View
    {
        $usage = $this->usage($chart_account);

        return view('finance.chart_accounts.edit', [
            'account' => $chart_account,
            'types' => ChartAccountType::cases(),
            'parents' => ChartAccount::query()->where('id', '!=', $chart_account->id)->orderBy('code')->get(),
            'usage' => $usage,
        ]);
    }

    public function update(Request $request, ChartAccount $chart_account): RedirectResponse
    {
        $data = $this->validated($request, $chart_account->id);
        if (($data['parent_id'] ?? null) == $chart_account->id) {
            return back()->withInput()->withErrors(['parent_id' => 'Una cuenta no puede ser padre de sí misma.']);
        }
        $old = $chart_account->toArray();
        $chart_account->update($data);
        $this->audit->log('chart_account_updated', $chart_account, $old, $chart_account->toArray(), 'Cuenta contable actualizada');

        return redirect()->route('chart-accounts.index')->with('status', 'Cuenta actualizada.');
    }

    /**
     * @return array{categories: int, subcategories: int, movements: int}
     */
    private function usage(ChartAccount $account): array
    {
        return [
            'categories' => Category::query()->where('chart_account_id', $account->id)->count(),
            'subcategories' => Subcategory::query()->where('chart_account_id', $account->id)->count(),
            'movements' => Movement::query()->where('chart_account_id', $account->id)->count(),
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
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['sort_order'] = (int) ($data['sort_order'] ?? 100);
        if ($request->missing('is_active') && $ignoreId === null) {
            $data['is_active'] = true;
        }

        return $data;
    }
}
