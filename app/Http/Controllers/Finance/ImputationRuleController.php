<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\ImputationRule;
use App\Models\Subcategory;
use App\Services\Finance\ImputationRuleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ImputationRuleController extends Controller
{
    public function __construct(private readonly ImputationRuleService $rules) {}

    public function index(): View
    {
        $rules = ImputationRule::query()
            ->with(['targetCategory', 'targetSubcategory', 'targetChartAccount', 'creator'])
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            $rule->cached_match_count = $this->rules->countMatches($rule);
        }

        $categories = Category::query()->orderBy('name')->get();
        $subcategories = Subcategory::query()->with('category')->orderBy('name')->get();
        $chartAccounts = ChartAccount::query()->orderBy('code')->get();
        $preview = session('imputation_rule_preview');

        return view('finance.chart_accounts.imputation_rules', compact(
            'rules',
            'categories',
            'subcategories',
            'chartAccounts',
            'preview',
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->rules->create($data);

        return back()->with('status', 'Regla de imputación creada.');
    }

    public function update(Request $request, ImputationRule $imputation_rule): RedirectResponse
    {
        $data = $this->validated($request, true);
        $this->rules->update($imputation_rule, $data);

        return back()->with('status', 'Regla actualizada.');
    }

    public function destroy(ImputationRule $imputation_rule): RedirectResponse
    {
        $imputation_rule->delete();

        return back()->with('status', 'Regla eliminada.');
    }

    public function preview(Request $request, ImputationRule $imputation_rule): RedirectResponse
    {
        $preview = $this->rules->previewApply($imputation_rule);
        $preview['rule_id'] = $imputation_rule->id;

        return redirect()
            ->route('imputation-rules.index')
            ->with('imputation_rule_preview', $preview)
            ->with('status', "Esta regla afectará {$preview['would_affect']} movimiento(s).");
    }

    public function apply(Request $request, ImputationRule $imputation_rule): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']]);
        $result = $this->rules->apply($imputation_rule);

        return redirect()
            ->route('imputation-rules.index')
            ->with('status', "Regla aplicada: {$result['updated']} movimiento(s).");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name' => [$partial ? 'nullable' : 'nullable', 'string', 'max:180'],
            'condition_type' => [
                $partial ? 'sometimes' : 'required',
                Rule::in([
                    ImputationRule::TYPE_DESCRIPTION_CONTAINS,
                    ImputationRule::TYPE_EXACT_DESCRIPTION,
                    ImputationRule::TYPE_MOVEMENT_TYPE,
                    ImputationRule::TYPE_CATEGORY_NAME,
                ]),
            ],
            'condition_value' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'target_category_id' => ['nullable', 'exists:categories,id'],
            'target_subcategory_id' => ['nullable', 'exists:subcategories,id'],
            'target_chart_account_id' => ['nullable', 'exists:chart_accounts,id'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
            'allow_manual_override' => ['nullable', 'boolean'],
        ]);
    }
}
