<?php

namespace App\Services\Finance;

use App\Enums\MovementType;
use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\ImputationRule;
use App\Models\Movement;
use App\Models\Setting;
use App\Models\Subcategory;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reglas dinámicas categoría/subcategoría → cuenta del plan.
 * Precedencia al resolver: 1) manual explícito 2) subcategoría 3) categoría
 * 4) regla automática / tipo genérico 5) pendiente (sin asignar).
 * Distinto de cuentas financieras (caja/banco).
 * applyToMovements no sobrescribe asignaciones ya confirmadas salvo overwriteManual.
 */
class ChartAccountMappingService
{
    public const SETTING_TYPE_DEFAULTS = 'chart_mapping.type_defaults';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ?ImputationRuleService $imputationRules = null,
    ) {}

    /**
     * @return array{chart_account_id: ?int, source: string, rule_id?: ?int}
     */
    public function resolve(?int $categoryId, ?int $subcategoryId, ?string $movementType = null, ?string $description = null): array
    {
        $categoryName = null;

        if ($subcategoryId) {
            $sub = Subcategory::query()->find($subcategoryId);
            if ($sub?->chart_account_id) {
                return ['chart_account_id' => (int) $sub->chart_account_id, 'source' => 'subcategory'];
            }
            if ($sub && $categoryId && (int) $sub->category_id !== (int) $categoryId) {
                return ['chart_account_id' => null, 'source' => 'invalid_subcategory'];
            }
            if ($sub && ! $categoryId) {
                $categoryId = (int) $sub->category_id;
            }
        }

        if ($categoryId) {
            $cat = Category::query()->find($categoryId);
            $categoryName = $cat?->name;
            if ($cat?->chart_account_id) {
                return ['chart_account_id' => (int) $cat->chart_account_id, 'source' => 'category'];
            }
        }

        $rules = $this->imputationRules ?? app(ImputationRuleService::class);
        $matched = $rules->match($description, $movementType, $categoryName);
        if ($matched && ! empty($matched['chart_account_id'])) {
            $source = 'imputation_rule';
            // Compatibilidad: reglas espejo de defaults por tipo se reportan como "type".
            $rule = ImputationRule::query()->find($matched['rule_id'] ?? 0);
            if ($rule && $rule->condition_type === ImputationRule::TYPE_MOVEMENT_TYPE) {
                $source = 'type';
            }

            return [
                'chart_account_id' => (int) $matched['chart_account_id'],
                'source' => $source,
                'rule_id' => $matched['rule_id'] ?? null,
            ];
        }

        $typeDefaults = $this->typeDefaults();
        $type = $movementType ? strtolower($movementType) : null;
        if ($type && ! empty($typeDefaults[$type])) {
            return ['chart_account_id' => (int) $typeDefaults[$type], 'source' => 'type'];
        }

        return ['chart_account_id' => null, 'source' => 'unassigned'];
    }

    /**
     * @return array{income: ?int, expense: ?int}
     */
    public function typeDefaults(): array
    {
        $raw = Setting::getValue(self::SETTING_TYPE_DEFAULTS, []);
        if (! is_array($raw)) {
            $raw = [];
        }

        return [
            'income' => isset($raw['income']) ? (int) $raw['income'] : null,
            'expense' => isset($raw['expense']) ? (int) $raw['expense'] : null,
        ];
    }

    /**
     * @param  array{income?: int|null, expense?: int|null}  $defaults
     */
    public function saveTypeDefaults(array $defaults): void
    {
        $payload = [
            'income' => ! empty($defaults['income']) ? (int) $defaults['income'] : null,
            'expense' => ! empty($defaults['expense']) ? (int) $defaults['expense'] : null,
        ];
        Setting::setValue(self::SETTING_TYPE_DEFAULTS, $payload, 'json');
        $this->audit->log('chart_mapping_type_defaults', null, null, $payload, 'Defaults tipo→plan de cuentas');

        // Espejo en reglas de imputación (UX: REGLAS DE IMPUTACIÓN).
        ($this->imputationRules ?? app(ImputationRuleService::class))->syncTypeDefaultRules($payload);
    }

    /**
     * @return array{total: int, classified: int, pending: int, percent: float}
     */
    public function classificationProgress(): array
    {
        return app(UnclassifiedMovementsService::class)->progress();
    }

    public function mapCategory(Category $category, ?int $chartAccountId): Category
    {
        $old = $category->only(['chart_account_id']);
        $category->update(['chart_account_id' => $chartAccountId]);
        $this->audit->log('category_chart_mapped', $category, $old, $category->only(['chart_account_id']), 'Categoría mapeada a plan');

        return $category->fresh();
    }

    public function mapSubcategory(Subcategory $subcategory, ?int $chartAccountId): Subcategory
    {
        $old = $subcategory->only(['chart_account_id']);
        $subcategory->update(['chart_account_id' => $chartAccountId]);
        $this->audit->log('subcategory_chart_mapped', $subcategory, $old, $subcategory->only(['chart_account_id']), 'Subcategoría mapeada a plan');

        return $subcategory->fresh();
    }

    /**
     * Asistente: categorías/subcategorías sin cuenta contable, agrupadas.
     *
     * @return array{
     *   total_unmapped: int,
     *   categories: list<array{id: int, name: string, scope: string, movement_count: int}>,
     *   subcategories: list<array{id: int, name: string, category_id: int, category_name: string, movement_count: int}>
     * }
     */
    public function unassignedAssistant(): array
    {
        $categories = Category::query()
            ->whereNull('chart_account_id')
            ->orderBy('scope')
            ->orderBy('sort_order')
            ->get();

        $subs = Subcategory::query()
            ->with('category')
            ->whereNull('chart_account_id')
            ->orderBy('category_id')
            ->orderBy('sort_order')
            ->get();

        $catIds = $categories->pluck('id')->all();
        $subIds = $subs->pluck('id')->all();

        $catCounts = $catIds === []
            ? collect()
            : Movement::query()
                ->posted()
                ->whereNull('chart_account_id')
                ->whereIn('category_id', $catIds)
                ->selectRaw('category_id, COUNT(*) as cnt')
                ->groupBy('category_id')
                ->pluck('cnt', 'category_id');

        $subCounts = $subIds === []
            ? collect()
            : Movement::query()
                ->posted()
                ->whereNull('chart_account_id')
                ->whereIn('subcategory_id', $subIds)
                ->selectRaw('subcategory_id, COUNT(*) as cnt')
                ->groupBy('subcategory_id')
                ->pluck('cnt', 'subcategory_id');

        $catRows = [];
        foreach ($categories as $cat) {
            $catRows[] = [
                'id' => $cat->id,
                'name' => $cat->name,
                'scope' => $cat->scope,
                'movement_count' => (int) ($catCounts[$cat->id] ?? 0),
            ];
        }

        // Agrupar subcategorías por categoría para el asistente (~695 movs sin cuenta).
        $groupedSubs = [];
        foreach ($subs as $sub) {
            $catName = $sub->category?->name ?? '—';
            $groupedSubs[$catName][] = [
                'id' => $sub->id,
                'name' => $sub->name,
                'category_id' => $sub->category_id,
                'category_name' => $catName,
                'movement_count' => (int) ($subCounts[$sub->id] ?? 0),
            ];
        }

        $subRows = [];
        foreach ($groupedSubs as $rows) {
            foreach ($rows as $row) {
                $subRows[] = $row;
            }
        }

        return [
            'total_unmapped' => count($catRows) + count($subRows),
            'categories' => $catRows,
            'subcategories' => $subRows,
            'subcategories_by_category' => $groupedSubs,
        ];
    }

    /**
     * Contador de alerta accionable: pendientes OPERATIVOS (sin categoría).
     * Cat/sub correcta con chart_account_id null NO cuenta como incompleto.
     */
    public function countMovementsWithoutAccount(): int
    {
        return app(OperationalClassificationService::class)->countPending();
    }

    /**
     * Métrica secundaria: tienen cat/sub pero aún sin cuenta contable (opcional/patrimonial).
     */
    public function countMovementsMissingOptionalChart(): int
    {
        return Movement::query()
            ->posted()
            ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value])
            ->whereNotNull('category_id')
            ->whereNull('chart_account_id')
            ->count();
    }

    /**
     * Vista previa de materialización cat/sub → plan sobre movimientos existentes.
     * No escribe; no recalcula FX.
     * Por defecto NO sobrescribe chart ya asignado (clasificación confirmada).
     *
     * @return array{
     *   total_candidates: int,
     *   matched: int,
     *   manual: int,
     *   would_change: int,
     *   would_assign: int,
     *   unchanged: int,
     *   intact: int,
     *   overwrite_manual: bool,
     *   sample: list<array{id: int, date: string, description: ?string, from: ?int, to: ?int, source: string, status: string}>
     * }
     */
    public function previewApplyToMovements(?int $limitSample = 25, bool $overwriteManual = false): array
    {
        $movements = Movement::query()
            ->posted()
            ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value])
            ->orderBy('id')
            ->get(['id', 'movement_date', 'description', 'category_id', 'subcategory_id', 'chart_account_id', 'type']);

        $matched = 0;
        $manual = 0;
        $wouldChange = 0;
        $wouldAssign = 0;
        $unchanged = 0;
        $sample = [];

        foreach ($movements as $m) {
            $type = $m->type instanceof MovementType ? $m->type->value : (string) $m->getRawOriginal('type');
            $resolved = $this->resolve($m->category_id, $m->subcategory_id, $type, $m->description);
            $to = $resolved['chart_account_id'];
            $from = $m->chart_account_id ? (int) $m->chart_account_id : null;

            if ($to === null && $from === null) {
                $unchanged++;

                continue;
            }

            if ($to === $from) {
                $unchanged++;

                continue;
            }

            // Diferencia entre actual y resuelto = candidato a cambio.
            $matched++;
            $hasManualChart = $from !== null;

            if ($hasManualChart && ! $overwriteManual) {
                $manual++;
                if (count($sample) < ($limitSample ?? 25)) {
                    $sample[] = [
                        'id' => $m->id,
                        'date' => $m->movement_date?->toDateString() ?? '',
                        'description' => $m->description,
                        'from' => $from,
                        'to' => $to,
                        'source' => $resolved['source'],
                        'status' => 'manual_intact',
                    ];
                }

                continue;
            }

            $wouldChange++;
            if ($from === null && $to !== null) {
                $wouldAssign++;
            }

            if (count($sample) < ($limitSample ?? 25)) {
                $sample[] = [
                    'id' => $m->id,
                    'date' => $m->movement_date?->toDateString() ?? '',
                    'description' => $m->description,
                    'from' => $from,
                    'to' => $to,
                    'source' => $resolved['source'],
                    'status' => 'would_change',
                ];
            }
        }

        return [
            'total_candidates' => $movements->count(),
            'matched' => $matched,
            'manual' => $manual,
            'would_change' => $wouldChange,
            'would_assign' => $wouldAssign,
            'unchanged' => $unchanged,
            'intact' => $unchanged + $manual,
            'overwrite_manual' => $overwriteManual,
            'sample' => $sample,
        ];
    }

    /**
     * Aplica reglas a movimientos existentes (solo tras preview explícito).
     * Por defecto no sobrescribe chart_account_id ya confirmado.
     * No toca FX congelado ni amarillos 11E-R.
     *
     * @return array{updated: int, skipped: int, manual_skipped: int}
     */
    public function applyToMovements(bool $overwriteManual = false): array
    {
        return DB::transaction(function () use ($overwriteManual) {
            $preview = $this->previewApplyToMovements(5, $overwriteManual);
            $updated = 0;
            $skipped = 0;
            $manualSkipped = 0;

            $movements = Movement::query()
                ->posted()
                ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value])
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            foreach ($movements as $m) {
                $type = $m->type instanceof MovementType ? $m->type->value : (string) $m->getRawOriginal('type');
                $resolved = $this->resolve($m->category_id, $m->subcategory_id, $type, $m->description);
                $to = $resolved['chart_account_id'];
                $from = $m->chart_account_id ? (int) $m->chart_account_id : null;

                if ($to === $from) {
                    $skipped++;

                    continue;
                }

                if ($from !== null && ! $overwriteManual) {
                    $manualSkipped++;
                    $skipped++;

                    continue;
                }

                $m->update(['chart_account_id' => $to]);
                $updated++;
            }

            $this->audit->log('chart_mapping_applied_to_movements', null, $preview, [
                'updated' => $updated,
                'skipped' => $skipped,
                'manual_skipped' => $manualSkipped,
                'overwrite_manual' => $overwriteManual,
            ], 'Asignación al plan materializada en movimientos');

            return [
                'updated' => $updated,
                'skipped' => $skipped,
                'manual_skipped' => $manualSkipped,
            ];
        });
    }

    /**
     * Árbol del plan con totales reales de movimientos posted.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function reportTree(?string $dateFrom = null, ?string $dateTo = null): Collection
    {
        $query = Movement::query()
            ->posted()
            ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value])
            ->whereNotNull('chart_account_id');

        if ($dateFrom) {
            $query->whereDate('movement_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('movement_date', '<=', $dateTo);
        }

        $totals = $query
            ->selectRaw('chart_account_id, SUM(amount_ars) as total_ars, SUM(amount_usd) as total_usd, COUNT(*) as cnt')
            ->groupBy('chart_account_id')
            ->get()
            ->keyBy('chart_account_id');

        $accounts = ChartAccount::query()->orderBy('code')->get()->keyBy('id');

        $build = function (?int $parentId) use (&$build, $accounts, $totals): array {
            $nodes = [];
            foreach ($accounts->where('parent_id', $parentId) as $acc) {
                $children = $build($acc->id);
                $own = $totals->get($acc->id);
                $ownArs = Money::normalize((string) ($own->total_ars ?? 0));
                $ownUsd = Money::normalize((string) ($own->total_usd ?? 0));
                $ownCnt = (int) ($own->cnt ?? 0);

                $childArs = '0.00';
                $childUsd = '0.00';
                $childCnt = 0;
                foreach ($children as $ch) {
                    $childArs = Money::add($childArs, $ch['total_ars']);
                    $childUsd = Money::add($childUsd, $ch['total_usd']);
                    $childCnt += $ch['count'];
                }

                $nodes[] = [
                    'id' => $acc->id,
                    'code' => $acc->code,
                    'name' => $acc->name,
                    'type' => $acc->type instanceof \BackedEnum ? $acc->type->value : (string) $acc->type,
                    'type_label' => $acc->typeLabel(),
                    'own_ars' => $ownArs,
                    'own_usd' => $ownUsd,
                    'own_count' => $ownCnt,
                    'total_ars' => Money::add($ownArs, $childArs),
                    'total_usd' => Money::add($ownUsd, $childUsd),
                    'count' => $ownCnt + $childCnt,
                    'children' => $children,
                ];
            }

            return $nodes;
        };

        return collect($build(null));
    }
}
