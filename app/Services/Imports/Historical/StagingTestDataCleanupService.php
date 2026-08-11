<?php

namespace App\Services\Imports\Historical;

use App\Models\ClientLedgerEntry;
use App\Models\ImportBatch;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryUnit;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Paso 6B: diagnóstico y limpieza de movimientos de prueba en staging.
 * No toca catálogo INVID, maestros, admin, ni trazabilidad 11E.
 */
class StagingTestDataCleanupService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Diagnóstico solo lectura.
     *
     * @return array{candidates: list<array<string,mixed>>, counts: array<string,int>, class_a: list<array>, class_b: list, class_c: list, class_d: list<array>}
     */
    public function diagnose(): array
    {
        $candidates = [];

        foreach (Movement::query()->orderBy('id')->get() as $m) {
            $origin = $this->classifyMovementOrigin($m);
            $candidates[] = [
                'table' => 'movements',
                'model' => Movement::class,
                'id' => $m->id,
                'date' => optional($m->movement_date)?->toDateString(),
                'concepto' => $m->description,
                'importe' => (float) $m->amount,
                'type' => $m->type?->value ?? (string) $m->type,
                'client_id' => $m->client_id,
                'supplier_id' => $m->supplier_id,
                'created_at' => optional($m->created_at)?->toDateTimeString(),
                'import_batch_id' => $m->import_batch_id,
                'external_id' => $m->external_id,
                'source_row' => $m->source_row,
                'class' => $origin['class'],
                'reason' => $origin['reason'],
            ];
        }

        foreach (ClientLedgerEntry::query()->orderBy('id')->get() as $e) {
            $linkedHist = $e->financial_movement_id
                && Movement::query()->where('id', $e->financial_movement_id)->whereNotNull('import_batch_id')->exists();
            $class = $linkedHist ? 'B' : 'A';
            if ($e->import_batch_id ?? null) {
                $class = 'B';
            }
            // ClientLedgerEntry may not have import_batch_id column
            $candidates[] = [
                'table' => 'client_ledger_entries',
                'model' => ClientLedgerEntry::class,
                'id' => $e->id,
                'date' => optional($e->entry_date)?->toDateString(),
                'concepto' => $e->description,
                'importe' => (float) $e->amount,
                'type' => $e->type?->value ?? (string) $e->type,
                'client_id' => $e->client_id,
                'created_at' => optional($e->created_at)?->toDateTimeString(),
                'import_batch_id' => null,
                'financial_movement_id' => $e->financial_movement_id,
                'class' => $class,
                'reason' => $linkedHist
                    ? 'Vinculado a movimiento con import_batch (conservar/tratar con batch)'
                    : 'Asiento CC operativo preexistente (candidato prueba si no hay batch histórico)',
            ];
        }

        if (Schema::hasTable('sales')) {
            foreach (Sale::query()->orderBy('id')->get() as $s) {
                $candidates[] = [
                    'table' => 'sales',
                    'model' => Sale::class,
                    'id' => $s->id,
                    'date' => optional($s->sold_on ?? $s->created_at)?->toDateString(),
                    'concepto' => $s->notes ?? ('Venta #'.$s->id),
                    'importe' => (float) ($s->total ?? $s->total_ars ?? 0),
                    'client_id' => $s->client_id ?? null,
                    'created_at' => optional($s->created_at)?->toDateTimeString(),
                    'import_batch_id' => null,
                    'class' => 'A',
                    'reason' => 'Venta operativa de prueba (sin import histórico)',
                ];
            }
        }

        if (Schema::hasTable('inventory_movements')) {
            foreach (InventoryMovement::query()->orderBy('id')->get() as $im) {
                $candidates[] = [
                    'table' => 'inventory_movements',
                    'model' => InventoryMovement::class,
                    'id' => $im->id,
                    'date' => optional($im->moved_at ?? $im->created_at)?->toDateTimeString(),
                    'concepto' => $im->type?->value ?? (string) ($im->type ?? 'inventory'),
                    'importe' => (float) ($im->qty ?? 0),
                    'created_at' => optional($im->created_at)?->toDateTimeString(),
                    'import_batch_id' => $im->import_batch_id ?? null,
                    // Catálogo INVID confirma stock 0; cualquier movimiento de stock previo es prueba.
                    'class' => 'A',
                    'reason' => 'Movimiento de stock pre-histórico (prueba); catálogo debe quedar en stock 0',
                ];
            }
        }

        if (Schema::hasTable('inventory_lots')) {
            foreach (InventoryLot::query()->where('qty_remaining', '>', 0)->orderBy('id')->get() as $lot) {
                $candidates[] = [
                    'table' => 'inventory_lots',
                    'model' => InventoryLot::class,
                    'id' => $lot->id,
                    'concepto' => 'Lot product_id='.$lot->product_id,
                    'importe' => (float) $lot->qty_remaining,
                    'created_at' => optional($lot->created_at)?->toDateTimeString(),
                    'class' => 'A',
                    'reason' => 'Lote con stock > 0 pre-histórico (prueba); se purga qty, no el producto',
                    'purge_mode' => 'zero_qty',
                ];
            }
        }

        if (Schema::hasTable('inventory_units')) {
            foreach (InventoryUnit::query()->orderBy('id')->get() as $unit) {
                $candidates[] = [
                    'table' => 'inventory_units',
                    'model' => InventoryUnit::class,
                    'id' => $unit->id,
                    'concepto' => $unit->internal_code ?? ('unit#'.$unit->id),
                    'importe' => 1,
                    'created_at' => optional($unit->created_at)?->toDateTimeString(),
                    'class' => 'A',
                    'reason' => 'Unidad de inventario de prueba',
                ];
            }
        }

        $classA = array_values(array_filter($candidates, fn ($c) => ($c['class'] ?? '') === 'A'));
        $classB = array_values(array_filter($candidates, fn ($c) => ($c['class'] ?? '') === 'B'));
        $classC = array_values(array_filter($candidates, fn ($c) => ($c['class'] ?? '') === 'C'));
        $classD = array_values(array_filter($candidates, fn ($c) => ($c['class'] ?? '') === 'D'));

        return [
            'candidates' => $candidates,
            'counts' => [
                'total' => count($candidates),
                'A_manual_test' => count($classA),
                'B_catalog_masters' => count($classB),
                'C_structural' => count($classC),
                'D_doubtful' => count($classD),
                'products' => Product::query()->count(),
            ],
            'class_a' => $classA,
            'class_b' => $classB,
            'class_c' => $classC,
            'class_d' => $classD,
            'products_count' => Product::query()->count(),
            'stop_required' => count($classD) > 0,
        ];
    }

    /**
     * Borra solo clase A. Si hay clase D → excepción (detenerse).
     *
     * @return array<string, mixed>
     */
    public function purgeClassA(bool $forceDespiteD = false): array
    {
        $diag = $this->diagnose();
        if ($diag['stop_required'] && ! $forceDespiteD) {
            throw new InvalidArgumentException(
                '6B DETENIDO: hay candidatos clase D dudosos. No se borra nada. Detalle: '
                .json_encode($diag['class_d'], JSON_UNESCAPED_UNICODE)
            );
        }

        $report = [
            'deleted' => [],
            'by_table' => [],
            'audit_snapshot' => $diag['class_a'],
            'products_before' => Product::query()->count(),
        ];

        DB::transaction(function () use ($diag, &$report) {
            // Orden: sales items → sales → ledger → movements
            $idsByTable = [];
            foreach ($diag['class_a'] as $c) {
                $idsByTable[$c['table']][] = $c['id'];
            }

            if (! empty($idsByTable['sales']) && Schema::hasTable('sale_items')) {
                SaleItem::query()->whereIn('sale_id', $idsByTable['sales'])->delete();
                $report['by_table']['sale_items'] = 'cascade_by_sale';
            }
            if (! empty($idsByTable['sales'])) {
                $n = Sale::query()->whereIn('id', $idsByTable['sales'])->delete();
                $report['by_table']['sales'] = $n;
                $report['deleted']['sales'] = $idsByTable['sales'];
            }
            if (! empty($idsByTable['client_ledger_entries'])) {
                $n = ClientLedgerEntry::query()->whereIn('id', $idsByTable['client_ledger_entries'])->delete();
                $report['by_table']['client_ledger_entries'] = $n;
                $report['deleted']['client_ledger_entries'] = $idsByTable['client_ledger_entries'];
            }
            if (! empty($idsByTable['movements'])) {
                // Anular/borrar movimientos de prueba (hard delete en staging limpio)
                $n = Movement::query()->whereIn('id', $idsByTable['movements'])->delete();
                $report['by_table']['movements'] = $n;
                $report['deleted']['movements'] = $idsByTable['movements'];
            }
            if (! empty($idsByTable['inventory_units']) && Schema::hasTable('inventory_units')) {
                $n = InventoryUnit::query()->whereIn('id', array_filter($idsByTable['inventory_units']))->delete();
                $report['by_table']['inventory_units'] = $n;
                $report['deleted']['inventory_units'] = $idsByTable['inventory_units'];
            }
            if (! empty($idsByTable['inventory_movements']) && Schema::hasTable('inventory_movements')) {
                $n = InventoryMovement::query()->whereIn('id', $idsByTable['inventory_movements'])->delete();
                $report['by_table']['inventory_movements'] = $n;
                $report['deleted']['inventory_movements'] = $idsByTable['inventory_movements'];
            }
            foreach ($diag['class_a'] as $c) {
                if (($c['table'] ?? '') === 'inventory_lots' && ($c['purge_mode'] ?? '') === 'zero_qty') {
                    InventoryLot::query()->where('id', $c['id'])->update([
                        'qty_remaining' => 0,
                        'status' => 'depleted',
                    ]);
                    $report['by_table']['inventory_lots_zeroed'] = ($report['by_table']['inventory_lots_zeroed'] ?? 0) + 1;
                }
            }

            // Recalcular qty productos si existe columna
            if (Schema::hasColumn('products', 'qty_on_hand')) {
                Product::query()->update(['qty_on_hand' => 0]);
            }

            $this->audit->log('staging_test_data_purged_6b', null, null, [
                'deleted' => $report['deleted'],
                'by_table' => $report['by_table'],
                'snapshot_count' => count($diag['class_a']),
            ], '6B limpieza movimientos de prueba (clase A)');
        });

        $report['products_after'] = Product::query()->count();
        $report['post'] = $this->postCleanupChecks();

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function postCleanupChecks(): array
    {
        $diag = $this->diagnose();

        return [
            'remaining_class_a' => $diag['counts']['A_manual_test'],
            'remaining_movements' => Movement::query()->count(),
            'remaining_ledger' => ClientLedgerEntry::query()->count(),
            'products' => Product::query()->count(),
            'expected_catalog_products' => 1230,
            'catalog_ok' => Product::query()->count() === 1230 || Product::query()->count() >= 1230,
            'class_d_remaining' => $diag['counts']['D_doubtful'],
            'historical_batches_preview' => ImportBatch::query()
                ->where('importer_kind', 'historical_movements')
                ->count(),
            'base_limpia' => $diag['counts']['A_manual_test'] === 0
                && Movement::query()->whereNull('import_batch_id')->count() === 0
                && ClientLedgerEntry::query()->count() === 0,
        ];
    }

    /**
     * @return array{class: string, reason: string}
     */
    private function classifyMovementOrigin(Movement $m): array
    {
        if ($m->import_batch_id) {
            $batch = ImportBatch::query()->find($m->import_batch_id);
            if ($batch && $batch->importer_kind === 'historical_movements') {
                return ['class' => 'B', 'reason' => 'Pertenece a import_batch histórico'];
            }
            if ($batch && $batch->importer_kind === 'supplier_catalog') {
                return ['class' => 'B', 'reason' => 'Pertenece a import_batch catálogo'];
            }

            return ['class' => 'B', 'reason' => 'Tiene import_batch_id'];
        }
        if ($m->external_id && str_starts_with((string) $m->external_id, 'hist:')) {
            return ['class' => 'B', 'reason' => 'external_id histórico'];
        }
        if ($m->is_opening_adjustment) {
            return ['class' => 'D', 'reason' => 'Ajuste de apertura sin batch — dudoso'];
        }

        // Sin batch / sin traza histórica → manual de prueba (autorizado a borrar en 6B)
        return ['class' => 'A', 'reason' => 'Movimiento manual/operativo sin import_batch (prueba inicial)'];
    }
}
