<?php

use App\Enums\AccountType;
use App\Enums\ImportReviewStatus;
use App\Enums\UnitCondition;
use App\Enums\UnitStatus;
use App\Models\AccountHolder;
use App\Models\FinancialAccount;
use App\Models\ImportBatch;
use App\Models\InventoryUnit;
use App\Models\Product;
use App\Models\ProductSupplierCode;
use App\Services\Imports\Historical\AccountMappingService;
use App\Services\Imports\Historical\ClientDetectionService;
use App\Services\Imports\Historical\HistoricalMovementsPreviewService;
use App\Services\Imports\Historical\SupplierCatalogPreviewService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\InventoryUnitService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\InventoryCatalogSeeder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    $this->seed(CurrencySeeder::class);
    $this->seed(InventoryCatalogSeeder::class);
});

function writeTempXlsx(array $rows, string $sheetTitle = 'Hoja1'): string
{
    $ss = new Spreadsheet;
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle($sheetTitle);
    foreach ($rows as $r => $cols) {
        foreach ($cols as $c => $val) {
            $sheet->setCellValue([$c + 1, $r + 1], $val);
        }
    }
    $path = tempnam(sys_get_temp_dir(), 'arx').'.xlsx';
    (new Xlsx($ss))->save($path);
    $ss->disconnectWorksheets();

    return $path;
}

test('catálogo proveedor genera productos stock cero con sku y códigos separados', function () {
    $admin = makeAdmin();
    $path = writeTempXlsx([
        ['FAMILIA', 'FABRICANTE', 'PEDIDOS', 'ARTICULO', 'DETALLE', 'PARTNUMBER', 'Indicador de impuestos (clientes)', 'ImpInterno', 'USD S/ IVA', 'COMENTARIOS'],
        ['Placa de video', 'MSI', '', '999001', 'RTX 3060 12GB', 'RTX3060-12', '1.105', '0', '199.50', 'demo'],
        ['Placa de video', 'MSI', '', '999002', 'RTX 4070', 'RTX4070', '1.105', '0', '499.00', ''],
    ]);

    $batch = app(SupplierCatalogPreviewService::class)->analyzePath($path, 'lista-test.xlsx', $admin->id, '2026-08-07');
    expect($batch->status)->toBe('preview')
        ->and($batch->rows_valid)->toBeGreaterThan(0)
        ->and($batch->classification_summary['products_to_create'])->toBe(2);

    $this->actingAs($admin);
    $confirmed = app(SupplierCatalogPreviewService::class)->confirm($batch->fresh());
    expect($confirmed->status)->toBe('confirmed')->and($confirmed->rows_imported)->toBe(2);

    $p = Product::query()->where('sku', 'AR-999001')->first();
    expect($p)->not->toBeNull()
        ->and((float) $p->qty_on_hand)->toBe(0.0)
        ->and($p->supplier_code)->toBe('999001')
        ->and($p->part_number)->toBe('RTX3060-12')
        ->and((float) $p->reference_cost_usd)->toBe(199.5);

    expect(ProductSupplierCode::query()->where('supplier_code', '999001')->exists())->toBeTrue();

    // idempotencia: segundo preview marca duplicados
    $batch2 = app(SupplierCatalogPreviewService::class)->analyzePath($path, 'lista-test.xlsx', $admin->id, '2026-08-07');
    expect($batch2->rows_duplicate)->toBeGreaterThan(0);
    @unlink($path);
});

test('titulares Fernando/Gabi y tarjetas como pasivo', function () {
    $masters = app(AccountMappingService::class)->ensurePreviewMasters();
    expect(AccountHolder::query()->where('code', 'fernando')->exists())->toBeTrue()
        ->and(AccountHolder::query()->where('code', 'gabi')->exists())->toBeTrue();

    $visa = FinancialAccount::query()->where('alias', 'VISA')->first();
    expect($visa)->not->toBeNull()
        ->and($visa->is_liability)->toBeTrue()
        ->and($visa->type)->toBe(AccountType::CreditCard)
        ->and($visa->holder->code)->toBe('fernando');

    $mpGabi = FinancialAccount::query()->where('alias', 'MP Gabi')->first();
    expect($mpGabi->holder->code)->toBe('gabi');
    expect(count($masters['accounts']))->toBeGreaterThan(5);
});

test('detección de clientes alias y posible duplicado', function () {
    $svc = app(ClientDetectionService::class);
    expect($svc->extractFromConcept('DAASA - instalación AP'))->toBe('DAASA');

    $detected = $svc->detect(['DAASA', 'daasa', 'Daasa Server', 'Lidercar', 'Lider Carr']);
    expect($detected['clients'])->not->toBeEmpty();
    expect(collect($detected['clients'])->pluck('name'))->toContain('DAASA');
});

test('preview movimientos clasifica verde amarillo rojo y bloquea confirm', function () {
    $admin = makeAdmin();
    // Build minimal Movimientos-like sheet: rows 1-3 headers, data from row 4
    $path = writeTempXlsx([
        ['2026', '', '', '', 'INGRESOS', 'EGRESOS', '', 'CC', '', '', 'Merca IN', 'Merca OUT', 'Venta', 'Ut'],
        ['', '', '', '', '', '', '', 'IN', 'OUT', '', '', '', '', ''],
        ['FECHA', 'CONCEPTO', 'Cuenta', 'SubCuenta', '', '', '', '', '', '', '', '', '', ''],
        [46023, 'Cafe simple', 'Comidas', 'MP Fer', '', 1500, '', '', '', '', '', '', '', ''],
        [46024, 'Abono DAASA', 'Abonos', 'MP Fer', 50000, '', '', '', '', '', '', '', '', ''],
        [44900, 'Fecha 2025 sospechosa', 'Servicios', 'FT', '', 100, '', '', '', '', '', '', '', ''],
        [46025, 'Venta compleja', 'Ventas', 'MP Fer', 495000, '', '', 140000, '', '', '', 422126, 495000, 142874],
        [46026, 'Cobro CC Lidercar', 'CC', 'Lidercar', 10000, '', '', '', 10000, '', '', '', '', ''],
    ], 'Movimientos');

    $batch = app(HistoricalMovementsPreviewService::class)->analyzePath($path, 'gastos-test.xlsx', $admin->id);
    expect($batch->importer_kind)->toBe('historical_movements')
        ->and($batch->status)->toBe('preview')
        ->and($batch->rows_total)->toBeGreaterThan(0)
        ->and($batch->rows_red)->toBeGreaterThan(0)
        ->and($batch->reconciliation_payload['excel']['ingresos_ars'])->toBeGreaterThan(0)
        ->and($batch->options['confirm_enabled'] ?? true)->toBeFalse();

    expect(fn () => app(HistoricalMovementsPreviewService::class)->confirm($batch))
        ->toThrow(InvalidArgumentException::class);

    @unlink($path);
});

test('unidad sin serial con condición y estado e historial', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $product = app(\App\Services\Catalog\ProductService::class)->create([
        'sku' => 'AR-UNIT-1',
        'name' => 'Equipo demo unidad',
        'type' => 'physical',
        'tracks_units' => true,
    ]);

    $svc = app(InventoryUnitService::class);
    $unit = $svc->create($product, [
        'condition' => UnitCondition::New->value,
        'status' => UnitStatus::Available->value,
    ]);
    expect($unit->internal_code)->toStartWith('UNI-')
        ->and($unit->manufacturer_serial)->toBeNull()
        ->and($unit->events()->count())->toBe(1);

    $unit = $svc->transition($unit, UnitCondition::Used, UnitStatus::InUse, 'Puesta en uso');
    expect($unit->condition)->toBe(UnitCondition::Used)
        ->and($unit->status)->toBe(UnitStatus::InUse)
        ->and($unit->first_used_at)->not->toBeNull()
        ->and($unit->events()->count())->toBe(2);
});

test('ajuste de apertura de stock es auditado y no edita saldo directo', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $product = app(\App\Services\Catalog\ProductService::class)->create([
        'sku' => 'AR-OPEN-1',
        'name' => 'Stock apertura',
        'type' => 'physical',
    ]);

    $mov = app(InventoryService::class)->openingAdjustmentIn($product, [
        'quantity' => 3,
        'unit_cost' => 10,
        'currency_code' => 'USD',
        'reason' => 'Inventario físico 2026-08-15',
        'movement_date' => '2026-08-15',
    ]);

    expect($mov->type->value)->toBe('opening_in')
        ->and((float) $product->fresh()->qty_on_hand)->toBe(3.0);

    expect(fn () => app(InventoryService::class)->openingAdjustmentIn($product, [
        'quantity' => 1,
        'reason' => '',
    ]))->toThrow(InvalidArgumentException::class);
});

test('proveedor relevante vs comercio ocasional', function () {
    $relevant = config('historical_import.relevant_suppliers');
    expect($relevant)->toContain('INVID')->toContain('LatinCloud');

    $patterns = config('historical_import.occasional_commerce_patterns');
    $isOccasional = false;
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, 'Compra Carrefour vianda')) {
            $isOccasional = true;
        }
    }
    expect($isOccasional)->toBeTrue();
});

test('idempotencia por file hash en import batch', function () {
    $admin = makeAdmin();
    $path = writeTempXlsx([
        ['FAMILIA', 'FABRICANTE', 'PEDIDOS', 'ARTICULO', 'DETALLE', 'PARTNUMBER', 'Indicador de impuestos (clientes)', 'ImpInterno', 'USD S/ IVA', 'COMENTARIOS'],
        ['Router', 'TP-Link', '', '111', 'Router AX', 'AX55', '1.105', '0', '40', ''],
    ]);
    $h1 = hash_file('sha256', $path);
    $b1 = app(SupplierCatalogPreviewService::class)->analyzePath($path, 'a.xlsx', $admin->id);
    $b2 = app(SupplierCatalogPreviewService::class)->analyzePath($path, 'a.xlsx', $admin->id);
    expect($b1->file_hash)->toBe($h1)->and($b2->file_hash)->toBe($h1);
    expect(ImportBatch::query()->where('file_hash', $h1)->count())->toBe(2);
    @unlink($path);
});
