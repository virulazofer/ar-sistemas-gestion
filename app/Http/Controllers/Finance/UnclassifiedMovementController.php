<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\Movement;
use App\Models\Subcategory;
use App\Services\Finance\CategoryReclassificationService;
use App\Services\Finance\CategorySemanticsAnalyzer;
use App\Services\Finance\ImputationRuleService;
use App\Services\Finance\UnclassifiedMovementsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UnclassifiedMovementController extends Controller
{
    public function __construct(
        private readonly UnclassifiedMovementsService $unclassified,
        private readonly ImputationRuleService $rules,
        private readonly CategoryReclassificationService $reclass,
        private readonly CategorySemanticsAnalyzer $semantics,
    ) {}

    public function index(Request $request): View
    {
        $filters = [
            'q' => $request->string('q')->toString(),
            'scope' => $request->string('scope')->toString() ?: null,
            'type' => $request->string('type')->toString() ?: null,
            'category_id' => $request->integer('category_id') ?: null,
            'financial_account_id' => $request->integer('financial_account_id') ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'sort' => $request->string('sort')->toString() ?: 'date_desc',
        ];
        $perPage = $request->integer('per_page') ?: 25;
        $movements = $this->unclassified->paginate($filters, $perPage);
        $progress = $this->unclassified->progress();
        $patterns = $this->unclassified->groupByPattern();
        $categories = Category::query()->orderBy('name')->get();
        $subcategories = Subcategory::query()->with('category')->orderBy('name')->get();
        $chartAccounts = ChartAccount::query()->orderBy('code')->get();
        $financialAccounts = FinancialAccount::query()->orderBy('name')->get(['id', 'name']);
        $bulkPreview = session('unclassified_bulk_preview');
        $patternPreview = session('unclassified_pattern_preview');

        return view('finance.chart_accounts.unclassified', compact(
            'movements',
            'filters',
            'progress',
            'patterns',
            'categories',
            'subcategories',
            'chartAccounts',
            'financialAccounts',
            'bulkPreview',
            'patternPreview',
            'perPage',
        ));
    }

    public function classify(Request $request, Movement $movement): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'exists:categories,id'],
            'subcategory_id' => ['nullable', 'exists:subcategories,id'],
            'chart_account_id' => ['nullable', 'exists:chart_accounts,id'],
            'save_rule' => ['nullable', 'boolean'],
            'rule_condition_value' => ['nullable', 'string', 'max:120'],
        ]);

        $this->unclassified->classifyOne(
            $movement,
            $data['category_id'] ?? null,
            $data['subcategory_id'] ?? null,
            $data['chart_account_id'] ?? null,
        );

        if ($request->boolean('save_rule') && ! empty($data['rule_condition_value'])) {
            $this->rules->create([
                'name' => 'Desde mov #'.$movement->id,
                'condition_type' => 'description_contains',
                'condition_value' => $data['rule_condition_value'],
                'target_category_id' => $data['category_id'] ?? null,
                'target_subcategory_id' => $data['subcategory_id'] ?? null,
                'target_chart_account_id' => $data['chart_account_id'] ?? null,
                'priority' => 50,
                'is_active' => true,
                'allow_manual_override' => true,
            ]);
        }

        return back()->with('status', 'Movimiento clasificado.');
    }

    public function previewBulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'movement_ids' => ['required', 'array', 'min:1'],
            'movement_ids.*' => ['integer', 'exists:movements,id'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'subcategory_id' => ['nullable', 'exists:subcategories,id'],
            'chart_account_id' => ['nullable', 'exists:chart_accounts,id'],
            'save_rule' => ['nullable', 'boolean'],
            'rule_condition_value' => ['nullable', 'string', 'max:120'],
        ]);

        $preview = $this->unclassified->previewBulk(
            $data['movement_ids'],
            $data['category_id'] ?? null,
            $data['subcategory_id'] ?? null,
            $data['chart_account_id'] ?? null,
        );
        $preview['payload'] = $data;

        return redirect()
            ->route('chart-accounts.unclassified')
            ->with('unclassified_bulk_preview', $preview)
            ->with('status', "Vista previa: esta clasificación afectará {$preview['would_affect']} movimiento(s).");
    }

    public function applyBulk(Request $request): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']]);
        $preview = session('unclassified_bulk_preview');
        if (! is_array($preview) || empty($preview['payload'])) {
            return back()->withErrors(['confirm' => 'Primero generá la vista previa.']);
        }

        $data = $preview['payload'];
        $result = $this->unclassified->applyBulk(
            $data['movement_ids'],
            $data['category_id'] ?? null,
            $data['subcategory_id'] ?? null,
            $data['chart_account_id'] ?? null,
        );

        if (! empty($data['save_rule']) && ! empty($data['rule_condition_value'])) {
            $this->rules->create([
                'name' => 'Regla masiva',
                'condition_type' => 'description_contains',
                'condition_value' => $data['rule_condition_value'],
                'target_category_id' => $data['category_id'] ?? null,
                'target_subcategory_id' => $data['subcategory_id'] ?? null,
                'target_chart_account_id' => $data['chart_account_id'] ?? null,
                'priority' => 50,
                'is_active' => true,
                'allow_manual_override' => true,
            ]);
        }

        return redirect()
            ->route('chart-accounts.unclassified')
            ->with('status', "Clasificación masiva aplicada: {$result['updated']} movimiento(s).");
    }

    public function previewPattern(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'movement_ids' => ['required', 'array', 'min:1'],
            'movement_ids.*' => ['integer'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'subcategory_id' => ['nullable', 'exists:subcategories,id'],
            'chart_account_id' => ['nullable', 'exists:chart_accounts,id'],
            'pattern_value' => ['nullable', 'string', 'max:120'],
            'confidence' => ['nullable', 'string', 'max:10'],
            'save_rule' => ['nullable', 'boolean'],
        ]);

        if (($data['confidence'] ?? '') === 'BAJA' && $request->boolean('force') === false) {
            return back()->withErrors(['confidence' => 'Confianza BAJA: no aplicar automáticamente. Revisá fila a fila o forzá con confirmación explícita.']);
        }

        $preview = $this->unclassified->previewBulk(
            $data['movement_ids'],
            $data['category_id'] ?? null,
            $data['subcategory_id'] ?? null,
            $data['chart_account_id'] ?? null,
        );
        $preview['payload'] = $data;

        return redirect()
            ->route('chart-accounts.unclassified')
            ->with('unclassified_pattern_preview', $preview)
            ->with('status', "Esta regla afectará {$preview['would_affect']} movimiento(s).");
    }

    public function applyPattern(Request $request): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']]);
        $preview = session('unclassified_pattern_preview');
        if (! is_array($preview) || empty($preview['payload'])) {
            return back()->withErrors(['confirm' => 'Primero generá la vista previa del patrón.']);
        }
        $data = $preview['payload'];
        $result = $this->unclassified->applyBulk(
            $data['movement_ids'],
            $data['category_id'] ?? null,
            $data['subcategory_id'] ?? null,
            $data['chart_account_id'] ?? null,
        );

        if (! empty($data['save_rule']) && ! empty($data['pattern_value'])) {
            $this->rules->create([
                'name' => 'Patrón '.$data['pattern_value'],
                'condition_type' => 'description_contains',
                'condition_value' => $data['pattern_value'],
                'target_category_id' => $data['category_id'] ?? null,
                'target_subcategory_id' => $data['subcategory_id'] ?? null,
                'target_chart_account_id' => $data['chart_account_id'] ?? null,
                'priority' => 40,
                'is_active' => true,
                'allow_manual_override' => true,
            ]);
        }

        return redirect()
            ->route('chart-accounts.unclassified')
            ->with('status', "Patrón aplicado: {$result['updated']} movimiento(s).");
    }

    public function normalizeSuper(Request $request): RedirectResponse
    {
        $previewOnly = ! $request->boolean('apply');
        $result = $this->reclass->normalizeSuperToSupermercado(apply: ! $previewOnly);

        if ($previewOnly) {
            return back()->with('super_preview', $result)->with('status', 'Vista previa Super→Supermercado lista.');
        }

        return back()->with('status', 'Normalización Super→Supermercado aplicada con auditoría.');
    }

    public function ensureProfessionalIncomes(Request $request): RedirectResponse
    {
        $apply = $request->boolean('apply');
        $report = $this->reclass->ensureProfessionalIncomeMappings(apply: $apply);

        return back()->with('professional_income_report', $report)
            ->with('status', $apply ? 'Mapeos de ingresos profesionales aplicados.' : 'Vista previa de ingresos profesionales.');
    }

    public function semanticsReport(): View
    {
        $analysis = $this->semantics->analyzeAmbiguous();

        return view('finance.chart_accounts.semantics', compact('analysis'));
    }
}
