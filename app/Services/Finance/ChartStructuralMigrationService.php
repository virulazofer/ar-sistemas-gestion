<?php

namespace App\Services\Finance;

use App\Enums\ChartAccountType;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\ClientLedgerEntry;
use App\Models\FinancialAccount;
use App\Models\InventoryLot;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Subcategory;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Dry-run / apply de migración estructural 11F.
 */
class ChartStructuralMigrationService
{
    public function __construct(
        private readonly ChartConceptCompatibility $compat,
        private readonly FinancialAccountChartLinker $linker,
        private readonly ScopeOriginRules $scopes,
        private readonly OperationalClassificationService $operational,
        private readonly ApprovedTaxonomyService $taxonomy,
        private readonly AuditLogger $audit,
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
            'legacy_roots_candidates' => $this->legacyRootCandidates(),
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
     * Apply Fase 1 autorizado: 2B + seed + FA + Bazar/MUBI + convergencia chart.
     *
     * @return array<string, mixed>
     */
    public function applyPhase1(bool $abortOnMonetaryDiff = true): array
    {
        $batchId = '11f-phase1-'.now()->format('YmdHis').'-'.Str::lower(Str::random(6));
        $pre = $this->integritySnapshot();
        $rootsPre = $this->rootsSnapshot();

        $result = DB::transaction(function () use ($batchId, $pre, $rootsPre, $abortOnMonetaryDiff) {
            $legacy = $this->correctLegacyAssetRoots();
            (new \Database\Seeders\ChartAccountSeeder)->run();
            // Re-aplicar 2B por si el seeder recreó hojas vacías y las legacy ya tenían código.
            $legacy = array_merge($legacy, ['post_seed_pass' => $this->correctLegacyAssetRoots()]);

            $this->taxonomy->ensureCanonical(write: true);
            $remap = $this->compat->remapMasters(write: true);
            $fa = $this->linker->linkAll(force: false);
            $named = $this->classifyBazarAndMubi();
            $convergence = $this->applyUnequivocalChartConvergence();

            $post = $this->integritySnapshot();
            $diff = $this->compareIntegrity($pre, $post);

            if ($abortOnMonetaryDiff && ! $diff['ok']) {
                throw new RuntimeException(
                    'Integridad PRE/POST con diferencias monetarias/estructurales: '.json_encode($diff['mismatches'], JSON_UNESCAPED_UNICODE)
                );
            }

            $rootsPost = $this->rootsSnapshot();
            $irregular = collect($rootsPost)
                ->reject(fn ($r) => in_array($r['code'], ['1', '2', '3', '4', '5'], true))
                ->values()
                ->all();

            $report = [
                'mode' => 'APPLY-PHASE1',
                'apply' => true,
                'batch_id' => $batchId,
                'roots_pre' => $rootsPre,
                'roots_post' => $rootsPost,
                'irregular_roots_post' => $irregular,
                'legacy_2b' => $legacy,
                'remap' => $remap,
                'financial_accounts' => $fa,
                'bazar_mubi' => $named,
                'convergence' => $convergence,
                'integrity_pre' => $pre,
                'integrity_post' => $post,
                'integrity_diff' => $diff,
                'integrity_ok' => $diff['ok'],
                'protected_roots_ok' => count($rootsPost) === 5
                    && collect($rootsPost)->pluck('code')->sort()->values()->all() === ['1', '2', '3', '4', '5'],
                'pending_classify' => $this->operational->countPending(),
                'missing_chart_optional' => $this->operational->progress()['missing_chart_optional'],
                'scope_checks' => $this->scopeChecks(),
                'stop' => '11F PLAN DE CUENTAS — FASE 1 COMPLETADA',
            ];

            $this->audit->log(
                'chart_11f_phase1_applied',
                null,
                ['integrity_pre' => $pre, 'roots_pre' => $rootsPre],
                [
                    'batch_id' => $batchId,
                    'convergence_updated' => $convergence['updated'] ?? 0,
                    'fa_linked' => $fa['linked'] ?? 0,
                    'legacy_2b' => $legacy,
                    'integrity_ok' => $diff['ok'],
                ],
                '11F Fase 1 apply (convergencia + 2B)'
            );

            return $report;
        });

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function correctLegacyAssetRoots(): array
    {
        $bienes = ChartAccount::query()->where('code', '1.5')->first();
        if (! $bienes) {
            // Crear padre mínimo si aún no corrió el seeder.
            $activo = ChartAccount::query()->where('code', '1')->whereNull('parent_id')->first();
            if (! $activo) {
                return [
                    'status' => 'skipped',
                    'reason' => 'No existe raíz ACTIVO (1); no se puede corregir 2B.',
                    'moves' => [],
                ];
            }
            $bienes = ChartAccount::query()->create([
                'code' => '1.5',
                'name' => 'Bienes de uso',
                'type' => ChartAccountType::Asset,
                'parent_id' => $activo->id,
                'is_active' => true,
                'is_protected' => false,
                'sort_order' => 50,
            ]);
        }

        // Liberar 1.5.3 si hoy es "Otros bienes de uso" → 1.5.6
        $otros = ChartAccount::query()
            ->where('code', '1.5.3')
            ->whereRaw('LOWER(name) like ?', ['%otros bienes de uso%'])
            ->first();
        if ($otros) {
            $conflict = ChartAccount::query()->where('code', '1.5.6')->where('id', '!=', $otros->id)->first();
            if ($conflict) {
                // Fusionar por nombre: mantener el de código 1.5.6, no borrar data del 1.5.3 si tiene refs.
                $deps = $this->chartDependencies($otros);
                if (($deps['movements'] + $deps['categories'] + $deps['subcategories'] + $deps['financial_accounts']) === 0
                    && $deps['children'] === 0) {
                    $old = $otros->toArray();
                    $otros->delete();
                    $this->audit->log('chart_account_deleted', null, $old, ['reason' => '2b_free_code_1.5.3'], 'Eliminada hoja vacía Otros (código liberado)');
                } else {
                    return [
                        'status' => 'partial',
                        'reason' => 'Conflicto código 1.5.6 ocupado y 1.5.3 Otros tiene referencias; detener corrección 2B de códigos.',
                        'otros_deps' => $deps,
                        'moves' => [],
                    ];
                }
            } else {
                $old = $otros->only(['id', 'code', 'name', 'parent_id']);
                $otros->update([
                    'code' => '1.5.6',
                    'parent_id' => $bienes->id,
                    'type' => ChartAccountType::Asset,
                    'is_protected' => false,
                    'sort_order' => 60,
                ]);
                $this->audit->log('chart_2b_recode_otros', $otros, $old, $otros->fresh()->only(['id', 'code', 'name', 'parent_id']), '2B: Otros bienes de uso 1.5.3→1.5.6');
            }
        }

        $targets = [
            ['name' => 'Instrumentos musicales', 'code' => '1.5.3', 'sort' => 30],
            ['name' => 'Propiedades', 'code' => '1.5.4', 'sort' => 40],
            ['name' => 'Vehículos', 'code' => '1.5.5', 'sort' => 50],
        ];

        $moves = [];
        foreach ($targets as $target) {
            $moves[] = $this->moveOrReuseUnderBienes($target['name'], $target['code'], $bienes, $target['sort']);
        }

        // Asegurar "Otros bienes de uso" en 1.5.6
        $otrosFinal = ChartAccount::query()->where('code', '1.5.6')->first()
            ?? ChartAccount::query()->whereRaw('LOWER(name) = ?', ['otros bienes de uso'])->first();
        if ($otrosFinal) {
            $otrosFinal->update([
                'code' => '1.5.6',
                'name' => 'Otros bienes de uso',
                'parent_id' => $bienes->id,
                'type' => ChartAccountType::Asset,
                'is_protected' => false,
                'sort_order' => 60,
            ]);
        }

        return [
            'status' => 'ok',
            'bienes_de_uso_id' => $bienes->id,
            'moves' => $moves,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function moveOrReuseUnderBienes(string $name, string $code, ChartAccount $bienes, int $sort): array
    {
        $key = mb_strtolower($name);
        $candidates = ChartAccount::query()
            ->whereRaw('LOWER(name) = ?', [$key])
            ->orderByRaw('CASE WHEN parent_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('id')
            ->get();

        $account = $candidates->first();
        $byCode = ChartAccount::query()->where('code', $code)->first();

        if (! $account && $byCode && mb_strtolower($byCode->name) === $key) {
            $account = $byCode;
        }

        if (! $account && $byCode && mb_strtolower($byCode->name) !== $key) {
            // Código ocupado por otra cuenta: reutilizar la de código solo si es hoja seed vacía del mismo slot esperado.
            $depsCode = $this->chartDependencies($byCode);
            if ($depsCode['movements'] === 0 && $depsCode['children'] === 0
                && $depsCode['categories'] === 0 && $depsCode['subcategories'] === 0
                && $depsCode['financial_accounts'] === 0) {
                $old = $byCode->only(['id', 'code', 'name', 'parent_id', 'type']);
                $byCode->update([
                    'name' => $name,
                    'parent_id' => $bienes->id,
                    'type' => ChartAccountType::Asset,
                    'is_protected' => false,
                    'is_active' => true,
                    'sort_order' => $sort,
                ]);
                $this->audit->log('chart_2b_reuse_code', $byCode, $old, $byCode->fresh()->only(['id', 'code', 'name', 'parent_id']), '2B: reutilizó hoja seed vacía');

                return [
                    'name' => $name,
                    'status' => 'reused_empty_code',
                    'id' => $byCode->id,
                    'code_pre' => $old['code'],
                    'code_post' => $code,
                    'parent_pre' => $old['parent_id'],
                    'parent_post' => $bienes->id,
                    'deps' => $depsCode,
                ];
            }

            return [
                'name' => $name,
                'status' => 'skipped_unsafe',
                'reason' => "Código {$code} ocupado por '{$byCode->name}' (id {$byCode->id}) con referencias.",
                'deps' => $depsCode,
            ];
        }

        if (! $account) {
            $created = ChartAccount::query()->create([
                'code' => $code,
                'name' => $name,
                'type' => ChartAccountType::Asset,
                'parent_id' => $bienes->id,
                'is_active' => true,
                'is_protected' => false,
                'sort_order' => $sort,
            ]);
            $this->audit->log('chart_2b_created', $created, null, $created->toArray(), '2B: creada bajo Bienes de uso');

            return [
                'name' => $name,
                'status' => 'created',
                'id' => $created->id,
                'code_pre' => null,
                'code_post' => $code,
                'parent_pre' => null,
                'parent_post' => $bienes->id,
                'deps' => $this->chartDependencies($created),
            ];
        }

        $deps = $this->chartDependencies($account);
        // Mover jerarquía es seguro aunque tenga movimientos/refs: se preservan IDs.
        if ($byCode && (int) $byCode->id !== (int) $account->id) {
            $depsCode = $this->chartDependencies($byCode);
            $empty = ($depsCode['movements'] + $depsCode['children'] + $depsCode['categories']
                + $depsCode['subcategories'] + $depsCode['financial_accounts']) === 0;
            if (! $empty) {
                return [
                    'name' => $name,
                    'status' => 'skipped_unsafe',
                    'reason' => "No se puede asignar código {$code}: ocupado por id {$byCode->id} con referencias; se preserva cuenta legacy id {$account->id}.",
                    'id' => $account->id,
                    'code_pre' => $account->code,
                    'deps' => $deps,
                    'conflict_deps' => $depsCode,
                ];
            }
            $oldDup = $byCode->toArray();
            $byCode->delete();
            $this->audit->log('chart_account_deleted', null, $oldDup, ['reason' => '2b_duplicate_empty_seed'], '2B: eliminada hoja seed vacía duplicada');
        }

        $old = $account->only(['id', 'code', 'name', 'parent_id', 'type', 'is_protected']);
        $account->update([
            'code' => $code,
            'name' => $name,
            'parent_id' => $bienes->id,
            'type' => ChartAccountType::Asset,
            'is_protected' => false,
            'is_active' => true,
            'sort_order' => $sort,
        ]);
        $this->audit->log('chart_2b_moved', $account, $old, $account->fresh()->only(['id', 'code', 'name', 'parent_id', 'type', 'is_protected']), '2B: movida bajo Bienes de uso');

        // Desactivar duplicados por nombre (no borrar si tienen deps).
        foreach ($candidates->where('id', '!=', $account->id) as $dup) {
            $dupDeps = $this->chartDependencies($dup);
            if (($dupDeps['movements'] + $dupDeps['categories'] + $dupDeps['subcategories'] + $dupDeps['financial_accounts']) === 0
                && $dupDeps['children'] === 0) {
                $snap = $dup->toArray();
                $dup->delete();
                $this->audit->log('chart_account_deleted', null, $snap, ['reason' => '2b_duplicate_name'], '2B: duplicado vacío eliminado');
            } else {
                $dup->update(['is_active' => false]);
                $this->audit->log('chart_2b_deactivate_dup', $dup, null, ['deps' => $dupDeps], '2B: duplicado con refs desactivado');
            }
        }

        return [
            'name' => $name,
            'status' => 'moved',
            'id' => $account->id,
            'code_pre' => $old['code'],
            'code_post' => $code,
            'parent_pre' => $old['parent_id'],
            'parent_post' => $bienes->id,
            'deps' => $deps,
            'movements_preserved' => $deps['movements'],
            'refs_preserved' => $deps['categories'] + $deps['subcategories'] + $deps['financial_accounts'],
        ];
    }

    /**
     * @return array{movements:int,children:int,categories:int,subcategories:int,financial_accounts:int}
     */
    private function chartDependencies(ChartAccount $account): array
    {
        return [
            'movements' => Movement::query()->where('chart_account_id', $account->id)->count(),
            'children' => ChartAccount::query()->where('parent_id', $account->id)->count(),
            'categories' => Category::query()->where('chart_account_id', $account->id)->count(),
            'subcategories' => Subcategory::query()->where('chart_account_id', $account->id)->count(),
            'financial_accounts' => FinancialAccount::query()->where('chart_account_id', $account->id)->count(),
        ];
    }

    /**
     * Bazar Nazca → EGRESOS › Muebles y útiles (5.5)
     * MUBI → EGRESOS › Servicios › Suscripciones (5.2.5)
     *
     * @return array<string, mixed>
     */
    public function classifyBazarAndMubi(): array
    {
        $myu = ChartAccount::query()->where('code', '5.5')->first();
        $susc = ChartAccount::query()->where('code', '5.2.5')->first();
        $servicios = ChartAccount::query()->where('code', '5.2')->first();

        $out = ['bazar_nazca' => null, 'mubi' => null];

        if ($myu) {
            $cat = Category::query()->firstOrCreate(
                ['name' => 'Muebles y útiles'],
                [
                    'scope' => 'both',
                    'chart_account_id' => $myu->id,
                    'is_active' => true,
                    'sort_order' => 50,
                    'excel_name' => 'MYU',
                ]
            );
            if (! $cat->chart_account_id) {
                $cat->update(['chart_account_id' => $myu->id]);
            }
            $sub = Subcategory::query()->firstOrCreate(
                ['category_id' => $cat->id, 'name' => 'Bazar Nazca'],
                ['chart_account_id' => $myu->id, 'is_active' => true, 'sort_order' => 5]
            );
            if (! $sub->chart_account_id) {
                $sub->update(['chart_account_id' => $myu->id]);
            }

            $updated = $this->assignNamedMovementsToConcept(
                names: ['Bazar Nazca', 'BazarNazca'],
                categoryId: (int) $cat->id,
                subcategoryId: (int) $sub->id,
                chartAccountId: (int) $myu->id,
            );
            $out['bazar_nazca'] = [
                'chart_code' => '5.5',
                'category_id' => $cat->id,
                'subcategory_id' => $sub->id,
                'movements_updated' => $updated,
            ];
        }

        if ($susc && $servicios) {
            $cat = Category::query()->firstOrCreate(
                ['name' => 'Servicios'],
                [
                    'scope' => 'both',
                    'chart_account_id' => $servicios->id,
                    'is_active' => true,
                    'sort_order' => 20,
                ]
            );
            if (! $cat->chart_account_id) {
                $cat->update(['chart_account_id' => $servicios->id]);
            }
            $sub = Subcategory::query()->firstOrCreate(
                ['category_id' => $cat->id, 'name' => 'Suscripciones'],
                ['chart_account_id' => $susc->id, 'is_active' => true, 'sort_order' => 50]
            );
            if (! $sub->chart_account_id) {
                $sub->update(['chart_account_id' => $susc->id]);
            }
            // Alias operativo MUBI → misma hoja Suscripciones
            $mubiSub = Subcategory::query()->firstOrCreate(
                ['category_id' => $cat->id, 'name' => 'MUBI'],
                ['chart_account_id' => $susc->id, 'is_active' => true, 'sort_order' => 51]
            );
            if (! $mubiSub->chart_account_id) {
                $mubiSub->update(['chart_account_id' => $susc->id]);
            }

            $updated = $this->assignNamedMovementsToConcept(
                names: ['MUBI', 'Mubi'],
                categoryId: (int) $cat->id,
                subcategoryId: (int) $mubiSub->id,
                chartAccountId: (int) $susc->id,
            );
            $out['mubi'] = [
                'chart_code' => '5.2.5',
                'category_id' => $cat->id,
                'subcategory_id' => $mubiSub->id,
                'suscripciones_subcategory_id' => $sub->id,
                'movements_updated' => $updated,
            ];
        }

        $this->audit->log('chart_11f_bazar_mubi', null, null, $out, '11F: Bazar Nazca / MUBI clasificados');

        return $out;
    }

    /**
     * @param  list<string>  $names
     */
    private function assignNamedMovementsToConcept(
        array $names,
        int $categoryId,
        int $subcategoryId,
        int $chartAccountId,
    ): int {
        $catIds = Category::query()
            ->where(function ($q) use ($names) {
                foreach ($names as $n) {
                    $q->orWhereRaw('LOWER(name) like ?', ['%'.mb_strtolower($n).'%']);
                }
            })->pluck('id');
        $subIds = Subcategory::query()
            ->where(function ($q) use ($names) {
                foreach ($names as $n) {
                    $q->orWhereRaw('LOWER(name) like ?', ['%'.mb_strtolower($n).'%']);
                }
            })->pluck('id');

        $query = Movement::query()->posted()
            ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value])
            ->where(function ($q) use ($names, $catIds, $subIds) {
                foreach ($names as $n) {
                    $q->orWhere('description', 'like', '%'.$n.'%');
                }
                if ($catIds->isNotEmpty()) {
                    $q->orWhereIn('category_id', $catIds->all());
                }
                if ($subIds->isNotEmpty()) {
                    $q->orWhereIn('subcategory_id', $subIds->all());
                }
            });

        $updated = 0;
        foreach ($query->orderBy('id')->get() as $m) {
            $dirty = false;
            // Solo completar clasificación / chart; no tocar importe/fecha/FA/scope/descripcion.
            if ((int) $m->category_id !== $categoryId) {
                $m->category_id = $categoryId;
                $dirty = true;
            }
            if ((int) $m->subcategory_id !== $subcategoryId) {
                $m->subcategory_id = $subcategoryId;
                $dirty = true;
            }
            if ((int) $m->chart_account_id !== $chartAccountId) {
                $m->chart_account_id = $chartAccountId;
                $dirty = true;
            }
            if ($dirty) {
                $m->save();
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Convergencia: solo asigna chart_account_id nulo desde cat/sub inequívoco.
     *
     * @return array{updated:int,skipped:int,already:int,unequivocal:int}
     */
    public function applyUnequivocalChartConvergence(): array
    {
        $updated = 0;
        $skipped = 0;
        $already = 0;
        $unequivocal = 0;

        $movements = Movement::query()
            ->posted()
            ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value])
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'category_id', 'subcategory_id', 'chart_account_id', 'type']);

        foreach ($movements as $m) {
            if ($m->chart_account_id) {
                $already++;

                continue;
            }

            $to = null;
            if ($m->subcategory_id) {
                $sub = Subcategory::query()->find($m->subcategory_id);
                if ($sub?->chart_account_id) {
                    $to = (int) $sub->chart_account_id;
                }
            }
            if ($to === null && $m->category_id) {
                $cat = Category::query()->find($m->category_id);
                if ($cat?->chart_account_id) {
                    $to = (int) $cat->chart_account_id;
                }
            }

            if ($to === null) {
                $skipped++;

                continue;
            }

            $unequivocal++;
            $m->update(['chart_account_id' => $to]);
            $updated++;
        }

        $this->audit->log('chart_11f_convergence', null, null, [
            'updated' => $updated,
            'skipped' => $skipped,
            'already' => $already,
            'unequivocal' => $unequivocal,
        ], '11F convergencia chart_account_id desde cat/sub');

        return compact('updated', 'skipped', 'already', 'unequivocal');
    }

    /**
     * @return array<string, mixed>
     */
    public function integritySnapshot(): array
    {
        $base = Movement::query()->posted()
            ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value]);

        $sum = function (string $type, string $col) {
            return (string) Movement::query()->posted()
                ->where('type', $type)
                ->sum($col);
        };

        $faBalances = FinancialAccount::query()
            ->orderBy('id')
            ->get(['id', 'cached_balance'])
            ->mapWithKeys(fn ($fa) => [(string) $fa->id => (string) $fa->cached_balance])
            ->all();

        $ccClients = (string) ClientLedgerEntry::query()->sum('signed_amount');
        $ccSuppliers = (string) SupplierLedgerEntry::query()->sum('signed_amount');
        $stockQty = (string) InventoryLot::query()->sum('qty_remaining');

        return [
            'movements_total' => Movement::query()->count(),
            'movements_posted_ie' => (clone $base)->count(),
            'income_ars' => $sum(MovementType::Income->value, 'amount_ars'),
            'income_usd' => $sum(MovementType::Income->value, 'amount_usd'),
            'expense_ars' => $sum(MovementType::Expense->value, 'amount_ars'),
            'expense_usd' => $sum(MovementType::Expense->value, 'amount_usd'),
            'clients' => Client::query()->count(),
            'suppliers' => Supplier::query()->count(),
            'products' => Product::query()->count(),
            'stock_qty_remaining' => $stockQty,
            'cc_clients_signed_sum' => $ccClients,
            'cc_suppliers_signed_sum' => $ccSuppliers,
            'financial_accounts' => FinancialAccount::query()->count(),
            'fa_balances' => $faBalances,
        ];
    }

    /**
     * @param  array<string, mixed>  $pre
     * @param  array<string, mixed>  $post
     * @return array{ok:bool,mismatches:list<string>}
     */
    public function compareIntegrity(array $pre, array $post): array
    {
        $keys = [
            'movements_total', 'movements_posted_ie',
            'income_ars', 'income_usd', 'expense_ars', 'expense_usd',
            'clients', 'suppliers', 'products', 'stock_qty_remaining',
            'cc_clients_signed_sum', 'cc_suppliers_signed_sum',
            'financial_accounts',
        ];
        $mismatches = [];
        foreach ($keys as $k) {
            if ((string) ($pre[$k] ?? '') !== (string) ($post[$k] ?? '')) {
                $mismatches[] = "{$k}: {$pre[$k]} → {$post[$k]}";
            }
        }
        $preFa = $pre['fa_balances'] ?? [];
        $postFa = $post['fa_balances'] ?? [];
        if ($preFa !== $postFa) {
            $mismatches[] = 'fa_balances diferían';
        }

        return ['ok' => $mismatches === [], 'mismatches' => $mismatches];
    }

    /**
     * @return list<array{id:int,code:string,name:string,type:?string,is_protected:bool}>
     */
    public function rootsSnapshot(): array
    {
        return ChartAccount::query()->roots()
            ->get(['id', 'code', 'name', 'type', 'is_protected'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'code' => (string) $r->code,
                'name' => (string) $r->name,
                'type' => $r->type instanceof \BackedEnum ? $r->type->value : (string) $r->type,
                'is_protected' => (bool) $r->is_protected,
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function legacyRootCandidates(): array
    {
        $names = ['Instrumentos musicales', 'Propiedades', 'Vehículos'];
        $out = [];
        foreach ($names as $name) {
            $acc = ChartAccount::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
            $out[] = [
                'name' => $name,
                'found' => (bool) $acc,
                'id' => $acc?->id,
                'code' => $acc?->code,
                'parent_id' => $acc?->parent_id,
                'is_root' => $acc?->parent_id === null,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function scopeChecks(): array
    {
        $incomePersonal = Movement::query()->posted()
            ->where('type', MovementType::Income->value)->where('scope', 'personal')->count();
        $compatible = 0;
        $rows = Movement::query()->posted()
            ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value])
            ->select('type', 'scope', DB::raw('COUNT(*) as c'))
            ->groupBy('type', 'scope')->get();
        foreach ($rows as $row) {
            $type = $row->type instanceof \BackedEnum ? $row->type->value : (string) $row->type;
            $scope = $row->scope instanceof \BackedEnum ? $row->scope->value : (string) $row->scope;
            if ($this->scopes->isHistoricallyCompatible($type, $scope) === 'A') {
                $compatible += (int) $row->c;
            }
        }

        return [
            'compatible_scope_movements' => $compatible,
            'income_personal' => $incomePersonal,
            'expense_scopes_allowed' => ['personal', 'professional', 'mixed'],
            'income_scopes_allowed_new' => ['professional', 'financial'],
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
