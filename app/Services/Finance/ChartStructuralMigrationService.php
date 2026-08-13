<?php

namespace App\Services\Finance;

use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\Movement;
use App\Models\Subcategory;
use Illuminate\Support\Facades\DB;

/**
 * Dry-run de migración estructural 11F. NO aplica cambios masivos a movimientos.
 */
class ChartStructuralMigrationService
{
    public function __construct(
        private readonly ChartConceptCompatibility $compat,
        private readonly FinancialAccountChartLinker $linker,
        private readonly ScopeOriginRules $scopes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dryRun(): array
    {
        $roots = ChartAccount::query()->roots()->get(['id', 'code', 'name', 'type', 'is_protected', 'is_active']);
        $protectedOk = $roots->where('is_protected', true)->count() >= 5
            && $roots->pluck('code')->intersect(['1', '2', '3', '4', '5'])->count() === 5;

        $treeCount = ChartAccount::query()->where('is_active', true)->count();

        $scopeDist = Movement::query()
            ->where('status', MovementStatus::Posted->value)
            ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value])
            ->select('type', 'scope', DB::raw('COUNT(*) as c'))
            ->groupBy('type', 'scope')
            ->get()
            ->map(fn ($r) => [
                'type' => $r->type instanceof \BackedEnum ? $r->type->value : (string) $r->type,
                'scope' => $r->scope instanceof \BackedEnum ? $r->scope->value : (string) $r->scope,
                'count' => (int) $r->c,
            ])
            ->all();

        $incomePersonal = Movement::query()
            ->posted()
            ->where('type', MovementType::Income->value)
            ->where('scope', 'personal')
            ->count();

        $incomeMixed = Movement::query()
            ->posted()
            ->where('type', MovementType::Income->value)
            ->where('scope', 'mixed')
            ->count();

        $compatible = 0;
        $ambiguous = 0;
        foreach ($scopeDist as $row) {
            $bucket = $this->scopes->isHistoricallyCompatible($row['type'], $row['scope']);
            if ($bucket === 'A') {
                $compatible += $row['count'];
            } else {
                $ambiguous += $row['count'];
            }
        }

        $withChart = Movement::query()->posted()
            ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value])
            ->whereNotNull('chart_account_id')->count();
        $withCat = Movement::query()->posted()
            ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value])
            ->whereNotNull('category_id')->count();
        $withoutClass = Movement::query()->posted()
            ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value])
            ->whereNull('category_id')
            ->whereNull('chart_account_id')
            ->count();

        $autoMigrable = Movement::query()->posted()
            ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value])
            ->whereNull('chart_account_id')
            ->where(function ($q) {
                $q->whereNotNull('category_id')->orWhereNotNull('subcategory_id');
            })
            ->count();

        $remapPreview = $this->compat->remapMasters(write: false);
        $faPreview = [
            'total' => FinancialAccount::query()->count(),
            'already_linked' => FinancialAccount::query()->whereNotNull('chart_account_id')->count(),
            'would_link' => FinancialAccount::query()->whereNull('chart_account_id')->count(),
        ];

        $pendingNamed = $this->pendingApprovedNames(['Bazar Nazca', 'MUBI', 'Bazar Nazca / MUBI']);

        return [
            'mode' => 'DRY-RUN',
            'apply' => false,
            'protected_roots_ok' => $protectedOk,
            'roots' => $roots->map(fn ($r) => $r->toArray())->all(),
            'active_accounts' => $treeCount,
            'scope_distribution' => $scopeDist,
            'compatible_scope_movements' => $compatible,
            'ambiguous_scope_movements' => $ambiguous,
            'income_personal_incompatible' => $incomePersonal,
            'income_mixed_incompatible' => $incomeMixed,
            'movements_with_chart' => $withChart,
            'movements_with_category' => $withCat,
            'movements_without_classification' => $withoutClass,
            'movements_auto_migrable_to_chart' => $autoMigrable,
            'category_remap_preview' => $remapPreview,
            'financial_accounts' => $faPreview,
            'pending_approved_optional' => $pendingNamed,
            'notes' => [
                'No se alteran importes, fechas, CC, clientes, stock ni cuentas financieras (atributos).',
                'Ingreso+Personal/Mixto históricos: reportados, sin conversión silenciosa.',
                'Clasificaciones 11F-8 ALTA se preservan (cat/sub); el apply masivo espera aprobación.',
                'Link FA→plan y remap masters pueden aplicarse en fase controlada aparte del apply masivo de movimientos.',
            ],
            'stop' => 'DETENERSE ANTES DEL APPLY MASIVO DE DATOS',
        ];
    }

    /**
     * Aplica solo infraestructura segura (no movimientos): árbol, link FA, remap masters.
     *
     * @return array<string, mixed>
     */
    public function applyInfrastructureOnly(): array
    {
        (new \Database\Seeders\ChartAccountSeeder)->run();
        $fa = $this->linker->linkAll(force: false);
        $remap = $this->compat->remapMasters(write: true);

        return [
            'mode' => 'INFRA-ONLY',
            'financial_accounts' => $fa,
            'remap' => $remap,
            'movements_touched' => 0,
            'stop' => 'Sin apply masivo de movimientos',
        ];
    }

    /**
     * @param  list<string>  $names
     * @return list<array<string, mixed>>
     */
    private function pendingApprovedNames(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $cat = Category::query()->whereRaw('LOWER(name) like ?', ['%'.mb_strtolower($name).'%'])->first();
            $sub = Subcategory::query()->whereRaw('LOWER(name) like ?', ['%'.mb_strtolower($name).'%'])->first();
            $mov = Movement::query()->posted()
                ->where(function ($q) use ($name, $cat, $sub) {
                    $q->where('description', 'like', '%'.$name.'%');
                    if ($cat) {
                        $q->orWhere('category_id', $cat->id);
                    }
                    if ($sub) {
                        $q->orWhere('subcategory_id', $sub->id);
                    }
                })->count();
            $out[] = [
                'name' => $name,
                'category_id' => $cat?->id,
                'subcategory_id' => $sub?->id,
                'movements' => $mov,
                'classified' => (bool) ($cat?->chart_account_id || $sub?->chart_account_id),
                'note' => 'Opcional compatibilidad; no bloquea dry-run',
            ];
        }

        return $out;
    }
}
