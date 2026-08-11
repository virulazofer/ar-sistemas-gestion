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
        [46025, 'Venta compleja', 'Ventas', 'MP Fer', '', '', '', 140000, '', '', 70000, 422126, 495000, 142874],
        [46026, 'Cobro CC Lidercar', 'CC', 'Lidercar', 10000, '', '', '', 10000, '', '', '', '', ''],
    ], 'Movimientos');

    $batch = app(HistoricalMovementsPreviewService::class)->analyzePath($path, 'gastos-test.xlsx', $admin->id);
    expect($batch->importer_kind)->toBe('historical_movements')
        ->and($batch->status)->toBe('preview')
        ->and($batch->rows_total)->toBeGreaterThan(0)
        ->and($batch->reconciliation_payload['model'] ?? null)->toBe('excel_vs_interpreted_v2.2')
        ->and($batch->options['confirm_enabled'] ?? true)->toBeFalse()
        ->and($batch->preview_payload['root_cause_groups'] ?? null)->not->toBeNull();

    // Venta con merca/utilidad: semántica económica, utilidad ≠ caja
    $venta = collect($batch->preview_payload['rows_sample_yellow'] ?? [])
        ->merge($batch->preview_payload['rows_sample_red'] ?? [])
        ->merge($batch->preview_payload['rows_sample_green'] ?? [])
        ->first(fn ($r) => ($r['concepto'] ?? '') === 'Venta compleja');
    expect($venta)->not->toBeNull()
        ->and((float) ($venta['interpretation']['economic_utilidad'] ?? 0))->toBe(142874.0)
        ->and((float) ($venta['interpretation']['finance_income'] ?? -1))->toBe(0.0)
        ->and((float) ($venta['interpretation']['cc_charge'] ?? 0))->toBe(140000.0);

    // CC OUT + ingreso debe interpretarse (regla inequívoca), no quedar en rojo complejo
    $cobro = collect($batch->preview_payload['rows_sample_yellow'] ?? [])
        ->merge($batch->preview_payload['rows_sample_green'] ?? [])
        ->first(fn ($r) => ($r['concepto'] ?? '') === 'Cobro CC Lidercar');
    expect($cobro)->not->toBeNull()
        ->and($cobro['review_status'] ?? '')->not->toBe('red')
        ->and((float) ($cobro['interpretation']['cc_payment'] ?? 0))->toBe(10000.0)
        ->and((float) ($cobro['interpretation']['finance_income'] ?? 0))->toBe(10000.0);

    expect(fn () => app(HistoricalMovementsPreviewService::class)->confirm($batch))
        ->toThrow(InvalidArgumentException::class);

    @unlink($path);
});

test('regla de cuenta aprobada se reaplica al reprocesar', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $path = writeTempXlsx([
        ['2026', '', '', '', 'INGRESOS', 'EGRESOS', '', 'CC', '', '', 'Merca IN', 'Merca OUT', 'Venta', 'Ut'],
        ['', '', '', '', '', '', '', 'IN', 'OUT', '', '', '', '', ''],
        ['FECHA', 'CONCEPTO', 'Cuenta', 'SubCuenta', '', '', '', '', '', '', '', '', '', ''],
        [46023, 'Gasto cuenta nueva', 'Servicios', 'Brubank Extra', '', 2500, '', '', '', '', '', '', '', ''],
    ], 'Movimientos');

    $batch = app(HistoricalMovementsPreviewService::class)->analyzePath($path, 'rule-test.xlsx', $admin->id);
    expect(($batch->preview_payload['masters']['unknown_accounts']['Brubank Extra'] ?? 0))->toBeGreaterThan(0);

    app(\App\Services\Imports\Historical\HistoricalMappingRuleService::class)->approveAccountAlias('Brubank Extra', [
        'name' => 'Brubank Extra Fernando',
        'type' => 'bank',
        'currency' => 'ARS',
        'holder' => 'fernando',
        'alias' => 'Brubank Extra',
        'liability' => false,
    ]);
    // Also register in config-like masters via AccountMappingService won't know it — rule resolves it
    $re = app(HistoricalMovementsPreviewService::class)->reprocess($batch->fresh());
    $unknown = $re->preview_payload['masters']['unknown_accounts'] ?? [];
    expect($unknown['Brubank Extra'] ?? null)->toBeNull();
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

test('decision de fecha corregida y venta compleja reducen rojos en preview', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $path = writeTempXlsx([
        ['2026', '', '', '', 'INGRESOS', 'EGRESOS', '', 'CC', '', '', 'Merca IN', 'Merca OUT', 'Venta', 'Ut'],
        ['', '', '', '', '', '', '', 'IN', 'OUT', '', '', '', '', ''],
        ['FECHA', 'CONCEPTO', 'Cuenta', 'SubCuenta', '', '', '', '', '', '', '', '', '', ''],
        [44927, 'Fecha 2025', 'Servicios', 'FT', '', 100, '', '', '', '', '', '', '', ''], // 2025-01-01 approx
        [46025, 'Venta compleja', 'Ventas', 'Lidercar', '', '', '', 50000, 20000, '', '', 10000, 80000, 5000],
        [46026, 'VISA resumen', 'VISA', 'Patagonia', '', '', '', '', '', 12000, '', '', '', ''],
    ], 'Movimientos');

    $batch = app(HistoricalMovementsPreviewService::class)->analyzePath($path, 'resolve-test.xlsx', $admin->id);
    $beforeRed = (int) $batch->rows_red;
    expect($beforeRed)->toBeGreaterThan(0);

    $resolution = app(\App\Services\Imports\Historical\HistoricalResolutionService::class);
    $rows = $resolution->loadAllRows($batch);
    $dateRow = collect($rows)->first(fn ($r) => ($r['concepto'] ?? '') === 'Fecha 2025');
    $complexRow = collect($rows)->first(fn ($r) => ($r['concepto'] ?? '') === 'Venta compleja');
    $cardRow = collect($rows)->first(fn ($r) => ($r['concepto'] ?? '') === 'VISA resumen');

    $resolution->decideDate($batch, (int) $dateRow['source_row'], 'correct', '2026-01-01');
    $resolution->decideComplexSale($batch, (int) $complexRow['source_row'], [
        'venta' => 80000,
        'cobro' => 0,
        'cc_charge' => 50000,
        'cc_payment' => 20000,
        'merca_out' => 10000,
        'merca_in' => 0,
        'utilidad' => 5000,
        'finance_income' => 0,
    ]);
    $resolution->decideCard($batch, (int) $cardRow['source_row'], 'statement_payment');
    $resolution->decideScopeBulk($batch, [(int) collect($rows)->first(fn ($r) => ($r['concepto'] ?? '') === 'Fecha 2025')['source_row']], 'professional');

    $after = $resolution->reprocessWithEvolution($batch->fresh());
    expect((int) $after->rows_red)->toBeLessThan($beforeRed);
    expect($after->options['evolution']['before']['red'] ?? null)->toBe($beforeRed);
    expect(fn () => app(HistoricalMovementsPreviewService::class)->confirm($after))
        ->toThrow(InvalidArgumentException::class);

    $this->get(route('imports.historical.resolve', $after))->assertOk();
    @unlink($path);
});

test('fila 533: CC Excel=utilidad se interpreta como deuda=venta con flag de corrección', function () {
    $admin = makeAdmin();
    $path = writeTempXlsx([
        ['2026', '', '', '', 'INGRESOS', 'EGRESOS', '', 'CC', '', '', 'Merca IN', 'Merca OUT', 'Venta', 'Ut'],
        ['', '', '', '', '', '', '', 'IN', 'OUT', '', '', '', '', ''],
        ['FECHA', 'CONCEPTO', 'Cuenta', 'SubCuenta', '', '', '', '', '', '', '', '', '', ''],
        // source_row = 4 in this synthetic sheet; override uses concepto fingerprint + auto margin rule
        [46040, 'Lidercar - Reparacion PC - SSD', 'Ventas', 'Lidercar', '', '', '', 110000, 110000, '', '', 20000, 130000, 110000],
        [46041, 'Lidercar - Reparacion PC - Fernando Fuente', 'Ventas', 'Lidercar', '', '', '', 40000, '', '', '', 20000, 60000, 40000],
    ], 'Movimientos');

    $batch = app(HistoricalMovementsPreviewService::class)->analyzePath($path, 'cc-margin-fix.xlsx', $admin->id);
    // Corrección CC/utilidad queda en corrected (listo para importar), no en yellow.
    $all = collect($batch->preview_payload['rows_sample_corrected'] ?? [])
        ->merge($batch->preview_payload['rows_sample_inferred'] ?? [])
        ->merge($batch->preview_payload['rows_sample_yellow'] ?? [])
        ->merge($batch->preview_payload['rows_sample_red'] ?? [])
        ->merge($batch->preview_payload['rows_sample_green'] ?? []);

    $ssd = $all->first(fn ($r) => str_contains((string) ($r['concepto'] ?? ''), 'SSD'));
    expect($ssd)->not->toBeNull()
        ->and(($ssd['operational_status'] ?? $ssd['review_status'] ?? ''))->toBe('corrected')
        ->and((float) ($ssd['interpretation']['cc_charge'] ?? 0))->toBe(130000.0)
        ->and((float) ($ssd['interpretation']['cc_payment'] ?? 0))->toBe(130000.0)
        ->and((float) ($ssd['interpretation']['excel_cc_in'] ?? 0))->toBe(110000.0)
        ->and((float) ($ssd['interpretation']['finance_income'] ?? -1))->toBe(0.0)
        ->and($ssd['flags'] ?? [])->toContain('valor_historico_corregido_por_interpretacion');

    $fuente = $all->first(fn ($r) => str_contains((string) ($r['concepto'] ?? ''), 'Fuente'));
    expect($fuente)->not->toBeNull()
        ->and((float) ($fuente['interpretation']['cc_charge'] ?? 0))->toBe(60000.0)
        ->and((float) ($fuente['interpretation']['cc_payment'] ?? 0))->toBe(0.0)
        ->and((float) ($fuente['interpretation']['excel_cc_in'] ?? 0))->toBe(40000.0);

    $explained = $batch->reconciliation_payload['explained_differences'] ?? [];
    expect($explained)->not->toBeEmpty();
    expect((float) ($batch->reconciliation_payload['literal_column_sums']['venta'] ?? 0))->toBe(190000.0);

    @unlink($path);
});

test('fila 5 DAASA CC Inicial: saldo de apertura confirmado (no venta/cobro), listo corregido', function () {
    $admin = makeAdmin();
    // Filas 1-3 cabecera; fila Excel 4 = filler; fila Excel 5 = DAASA (source_row=5)
    $path = writeTempXlsx([
        ['2026', '', '', '', 'INGRESOS', 'EGRESOS', '', 'CC', '', 'Pagos TC', 'Merca IN', 'Merca OUT', 'Venta', 'Ut'],
        ['', '', '', '', '', '', '', 'IN', 'OUT', '', '', '', '', ''],
        ['FECHA', 'CONCEPTO', 'Cuenta', 'SubCuenta', '', '', '', '', '', '', '', '', '', ''],
        [46023, 'Lidercar CC Inicial', 'CC', 'Lidercar', '', '', '', 1000000, '', '', '', '', '', ''],
        [46023, 'DAASA CC Inicial', 'CC', 'DAASA', '', '', '', 50000, 50000, '', '', '', '', ''],
    ], 'Movimientos');

    $batch = app(HistoricalMovementsPreviewService::class)->analyzePath($path, 'daasa-apertura.xlsx', $admin->id);
    $all = collect($batch->preview_payload['rows_sample_corrected'] ?? [])
        ->merge($batch->preview_payload['rows_sample_green'] ?? [])
        ->merge($batch->preview_payload['rows_sample_inferred'] ?? [])
        ->merge($batch->preview_payload['rows_sample_yellow'] ?? [])
        ->merge($batch->preview_payload['rows_sample_red'] ?? []);

    $daasa = $all->first(fn ($r) => (int) ($r['source_row'] ?? 0) === 5
        || str_contains((string) ($r['concepto'] ?? ''), 'DAASA CC Inicial'));
    expect($daasa)->not->toBeNull()
        ->and($daasa['review_status'] ?? '')->toBe('corrected')
        ->and($daasa['needs_human_decision'] ?? true)->toBeFalse()
        ->and($daasa['human_decision_options'] ?? ['x'])->toBe([])
        ->and($daasa['client'] ?? '')->toBe('DAASA')
        ->and($daasa['root_cause'] ?? '')->toBe('cc_apertura_confirmada')
        ->and($daasa['interpretation']['kind'] ?? '')->toBe('saldo_apertura_cc')
        ->and((float) ($daasa['interpretation']['cc_charge'] ?? 0))->toBe(50000.0)
        ->and((float) ($daasa['interpretation']['cc_payment'] ?? -1))->toBe(0.0)
        ->and((float) ($daasa['interpretation']['excel_cc_in'] ?? 0))->toBe(50000.0)
        ->and((float) ($daasa['interpretation']['excel_cc_out'] ?? 0))->toBe(50000.0)
        ->and((float) ($daasa['interpretation']['finance_income'] ?? -1))->toBe(0.0)
        ->and((float) ($daasa['interpretation']['finance_expense'] ?? -1))->toBe(0.0)
        ->and((float) ($daasa['interpretation']['economic_venta'] ?? -1))->toBe(0.0)
        ->and($daasa['flags'] ?? [])->toContain('cc_apertura_confirmada')
        ->and($daasa['flags'] ?? [])->toContain('confirmed_opening_cc_balance');

    // Lidercar (fila 4) NO confirmada: no aplicar la misma resolución automática
    $lider = $all->first(fn ($r) => str_contains((string) ($r['concepto'] ?? ''), 'Lidercar CC Inicial'));
    expect($lider)->not->toBeNull()
        ->and($lider['flags'] ?? [])->not->toContain('cc_apertura_confirmada');

    expect(fn () => app(HistoricalMovementsPreviewService::class)->confirm($batch))
        ->toThrow(InvalidArgumentException::class);

    @unlink($path);
});

test('pagos_tc se interpreta como pago de resumen (sin Compra vs Pago) y excepciones reales quedan amarillas', function () {
    $admin = makeAdmin();
    $path = writeTempXlsx([
        ['2026', '', '', '', 'INGRESOS', 'EGRESOS', '', 'CC', '', '', 'Merca IN', 'Merca OUT', 'Venta', 'Ut'],
        ['', '', '', '', '', '', '', 'IN', 'OUT', '', '', '', '', ''],
        ['FECHA', 'CONCEPTO', 'Cuenta', 'SubCuenta', '', '', '', '', '', '', '', '', '', ''],
        [46026, 'Pago VISA', 'VISA', 'Patagonia', '', '', '', '', '', 12000, '', '', '', ''],
        [46027, 'Pago MCMP', 'MC', 'MP Fer', '', '', '', '', '', 8000, '', '', '', ''],
        [46028, 'Pago VISA sin monto', 'VISA', 'Patagonia', '', '', '', '', '', '', '', '', '', ''],
        [46029, 'Pago VISA sin cuenta', 'VISA', 'VISA', '', '', '', '', '', 5000, '', '', '', ''],
        [46030, 'Pago VISA formula', 'VISA', 'Patagonia', '', '', '', '', '', '=10*100', '', '', '', ''],
    ], 'Movimientos');

    $batch = app(HistoricalMovementsPreviewService::class)->analyzePath($path, 'pago-resumen.xlsx', $admin->id);
    $all = collect($batch->preview_payload['rows_sample_corrected'] ?? [])
        ->merge($batch->preview_payload['rows_sample_green'] ?? [])
        ->merge($batch->preview_payload['rows_sample_inferred'] ?? [])
        ->merge($batch->preview_payload['rows_sample_yellow'] ?? [])
        ->merge($batch->preview_payload['rows_sample_red'] ?? []);

    $ok = $all->first(fn ($r) => ($r['concepto'] ?? '') === 'Pago VISA');
    expect($ok)->not->toBeNull()
        ->and($ok['review_status'] ?? '')->toBe('corrected')
        ->and($ok['interpretation']['kind'] ?? '')->toBe('card_statement_payment')
        ->and((float) ($ok['interpretation']['finance_expense'] ?? -1))->toBe(0.0)
        ->and((float) ($ok['interpretation']['card_liability_decrease'] ?? 0))->toBe(12000.0)
        ->and((float) ($ok['interpretation']['payment_account_decrease'] ?? 0))->toBe(12000.0)
        ->and($ok['flags'] ?? [])->toContain('pago_resumen_tarjeta_confirmado')
        ->and($ok['needs_human_decision'] ?? true)->toBeFalse()
        ->and($ok['human_decision_options'] ?? ['x'])->toBe([]);

    $mcmp = $all->first(fn ($r) => ($r['concepto'] ?? '') === 'Pago MCMP');
    expect($mcmp)->not->toBeNull()
        ->and($mcmp['review_status'] ?? '')->toBe('corrected')
        ->and($mcmp['interpretation']['card_alias'] ?? '')->toBe('MCMP');

    $sinMonto = $all->first(fn ($r) => ($r['concepto'] ?? '') === 'Pago VISA sin monto');
    expect($sinMonto)->not->toBeNull()
        ->and($sinMonto['review_status'] ?? '')->toBe('yellow')
        ->and($sinMonto['flags'] ?? [])->toContain('pago_tarjeta_sin_importe')
        ->and($sinMonto['needs_human_decision'] ?? false)->toBeTrue()
        ->and(implode(' | ', $sinMonto['human_decision_options'] ?? []))->not->toContain('Compra con tarjeta');

    $sinCuenta = $all->first(fn ($r) => ($r['concepto'] ?? '') === 'Pago VISA sin cuenta');
    expect($sinCuenta)->not->toBeNull()
        ->and($sinCuenta['review_status'] ?? '')->toBe('yellow')
        ->and($sinCuenta['flags'] ?? [])->toContain('pago_tarjeta_sin_cuenta_pago')
        ->and((float) ($sinCuenta['amounts']['pagos_tc'] ?? 0))->toBe(5000.0);

    $formula = $all->first(fn ($r) => ($r['concepto'] ?? '') === 'Pago VISA formula');
    expect($formula)->not->toBeNull()
        ->and($formula['review_status'] ?? '')->toBe('corrected')
        ->and((float) ($formula['amounts']['pagos_tc'] ?? 0))->toBe(1000.0)
        ->and((float) ($formula['interpretation']['card_liability_decrease'] ?? 0))->toBe(1000.0);

    expect(fn () => app(HistoricalMovementsPreviewService::class)->confirm($batch))
        ->toThrow(InvalidArgumentException::class);

    @unlink($path);
});

test('Santi aporta almuerzo se interpreta como reintegro personal, no inconsistencia', function () {
    $admin = makeAdmin();
    $path = writeTempXlsx([
        ['2026', '', '', '', 'INGRESOS', 'EGRESOS', '', 'CC', '', '', 'Merca IN', 'Merca OUT', 'Venta', 'Ut'],
        ['', '', '', '', '', '', '', 'IN', 'OUT', '', '', '', '', ''],
        ['FECHA', 'CONCEPTO', 'Cuenta', 'SubCuenta', '', '', '', '', '', '', '', '', '', ''],
        [46050, 'Santi aporta almuerzo', 'Comidas', 'MP Fer', '', -25000, '', '', '', '', '', '', '', ''],
    ], 'Movimientos');

    $batch = app(HistoricalMovementsPreviewService::class)->analyzePath($path, 'santi-reintegro.xlsx', $admin->id);
    $row = collect($batch->preview_payload['rows_sample_yellow'] ?? [])
        ->merge($batch->preview_payload['rows_sample_green'] ?? [])
        ->first(fn ($r) => str_contains((string) ($r['concepto'] ?? ''), 'Santi'));

    expect($row)->not->toBeNull()
        ->and($row['interpretation']['kind'] ?? '')->toBe('reintegro_gasto_personal')
        ->and((float) ($row['interpretation']['finance_income'] ?? 0))->toBe(25000.0)
        ->and((float) ($row['interpretation']['net_expense_reduction'] ?? 0))->toBe(25000.0)
        ->and($row['flags'] ?? [])->toContain('reintegro_gasto_personal')
        ->and($row['review_status'] ?? '')->not->toBe('red');

    $recoveries = $batch->preview_payload['sale_semantics_report']['personal_recoveries'] ?? [];
    expect($recoveries)->not->toBeEmpty();

    @unlink($path);
});

test('fila 15 saldo mercadería 2025: apertura confirmada corregida sin cuenta Saldo Inicial', function () {
    $admin = makeAdmin();
    $blank = ['', '', '', '', '', '', '', '', '', '', '', '', '', ''];
    $rows = [
        ['2026', '', '', '', 'INGRESOS', 'EGRESOS', '', 'CC', '', '', 'Merca IN', 'Merca OUT', 'Venta', 'Ut'],
        ['', '', '', '', '', '', '', 'IN', 'OUT', '', '', '', '', ''],
        ['FECHA', 'CONCEPTO', 'Cuenta', 'SubCuenta', '', '', '', '', '', '', '', '', '', ''],
    ];
    for ($i = 4; $i <= 14; $i++) {
        $rows[] = $blank;
    }
    $rows[] = [45660, 'Saldo de mercadería 2025', 'Mercaderias', 'Saldo Inicial', '', '', '', '', '', '', '', '', '', ''];
    $path = writeTempXlsx($rows, 'Movimientos');

    $batch = app(HistoricalMovementsPreviewService::class)->analyzePath($path, 'merca-apertura.xlsx', $admin->id);
    $all = collect($batch->preview_payload['rows_sample_corrected'] ?? [])
        ->merge($batch->preview_payload['rows_sample_yellow'] ?? [])
        ->merge($batch->preview_payload['rows_sample_green'] ?? []);
    $row = $all->first(fn ($r) => (int) ($r['source_row'] ?? 0) === 15);

    expect($row)->not->toBeNull()
        ->and($row['review_status'] ?? '')->toBe('corrected')
        ->and($row['needs_human_decision'] ?? true)->toBeFalse()
        ->and($row['interpretation']['kind'] ?? '')->toBe('saldo_apertura_mercaderia')
        ->and((float) ($row['interpretation']['finance_income'] ?? -1))->toBe(0.0)
        ->and((float) ($row['interpretation']['economic_venta'] ?? -1))->toBe(0.0)
        ->and($row['flags'] ?? [])->toContain('cc_apertura_mercaderia_confirmada')
        ->and($row['flags'] ?? [])->not->toContain('cuenta_desconocida');

    @unlink($path);
});

test('fila 177 Cintas Bibi: CC IN inferido, sin cobro financiero inventado', function () {
    $admin = makeAdmin();
    $blank = ['', '', '', '', '', '', '', '', '', '', '', '', '', ''];
    $rows = [
        ['2026', '', '', '', 'INGRESOS', 'EGRESOS', '', 'CC', '', '', 'Merca IN', 'Merca OUT', 'Venta', 'Ut'],
        ['', '', '', '', '', '', '', 'IN', 'OUT', '', '', '', '', ''],
        ['FECHA', 'CONCEPTO', 'Cuenta', 'SubCuenta', '', '', '', '', '', '', '', '', '', ''],
    ];
    for ($i = 4; $i <= 176; $i++) {
        $rows[] = $blank;
    }
    $rows[] = [46064, 'Cintas Industriales - Actualización Bibi', 'Ventas', 'MP Fer', '', '', '', '', '', '', '', '', 560000, ''];
    $path = writeTempXlsx($rows, 'Movimientos');

    $batch = app(HistoricalMovementsPreviewService::class)->analyzePath($path, 'cintas-bibi.xlsx', $admin->id);
    $all = collect($batch->preview_payload['rows_sample_corrected'] ?? [])
        ->merge($batch->preview_payload['rows_sample_yellow'] ?? []);
    $row = $all->first(fn ($r) => (int) ($r['source_row'] ?? 0) === 177);

    expect($row)->not->toBeNull()
        ->and($row['review_status'] ?? '')->toBe('corrected')
        ->and($row['client'] ?? '')->toBe('Cintas')
        ->and((float) ($row['interpretation']['cc_charge'] ?? 0))->toBe(560000.0)
        ->and((float) ($row['interpretation']['finance_income'] ?? -1))->toBe(0.0)
        ->and($row['flags'] ?? [])->toContain('cc_in_inferido_cintas')
        ->and($row['flags'] ?? [])->not->toContain('cobro_desconocido')
        ->and($row['needs_human_decision'] ?? true)->toBeFalse();

    @unlink($path);
});

test('filas 236/254: cobro confirmado separado de utilidad; 466 suprime duplicado si existe ingreso', function () {
    $admin = makeAdmin();
    $blank = ['', '', '', '', '', '', '', '', '', '', '', '', '', ''];
    $rows = [
        ['2026', '', '', '', 'INGRESOS', 'EGRESOS', '', 'CC', '', '', 'Merca IN', 'Merca OUT', 'Venta', 'Ut'],
        ['', '', '', '', '', '', '', 'IN', 'OUT', '', '', '', '', ''],
        ['FECHA', 'CONCEPTO', 'Cuenta', 'SubCuenta', '', '', '', '', '', '', '', '', '', ''],
    ];
    for ($i = 4; $i <= 235; $i++) {
        $rows[] = $blank;
    }
    $rows[] = [46081, 'DAASA gabinete + TUYA server', 'Ventas', 'Patagonia', '', '', '', '', '', '', '', 33000, 133000, 100000]; // 236
    for ($i = 237; $i <= 253; $i++) {
        $rows[] = $blank;
    }
    $rows[] = [46083, 'Xiaomi reloj Grimbo', 'Ventas', 'MP Fer', '', '', '', '', '', '', '', 75000, 78000, '']; // 254
    for ($i = 255; $i <= 465; $i++) {
        $rows[] = $blank;
    }
    $rows[] = [46143, 'Hugo Ferreyra', 'Ventas', 'MP Fer', '', '', '', '', 1308450, '', '', '', '', '']; // 466
    $rows[] = [46144, 'Ingreso duplicado test', 'Ventas', 'Patagonia', 1308450, '', '', '', '', '', '', '', '', '']; // 467

    $path = writeTempXlsx($rows, 'Movimientos');

    $batch = app(HistoricalMovementsPreviewService::class)->analyzePath($path, 'cobros-confirmados.xlsx', $admin->id);
    $all = collect($batch->preview_payload['rows_sample_corrected'] ?? [])
        ->merge($batch->preview_payload['rows_sample_green'] ?? [])
        ->merge($batch->preview_payload['rows_sample_yellow'] ?? []);

    $daasa = $all->first(fn ($r) => (int) ($r['source_row'] ?? 0) === 236);
    expect($daasa)->not->toBeNull()
        ->and($daasa['review_status'] ?? '')->toBe('corrected')
        ->and((float) ($daasa['interpretation']['finance_income'] ?? 0))->toBe(133000.0)
        ->and($daasa['flags'] ?? [])->toContain('cobro_confirmado_patagonia')
        ->and($daasa['needs_human_decision'] ?? true)->toBeFalse();

    $xiaomi = $all->first(fn ($r) => (int) ($r['source_row'] ?? 0) === 254);
    expect($xiaomi)->not->toBeNull()
        ->and($xiaomi['review_status'] ?? '')->toBe('corrected')
        ->and((float) ($xiaomi['interpretation']['finance_income'] ?? 0))->toBe(78000.0)
        ->and($xiaomi['interpretation']['finance_account_alias'] ?? '')->toBe('FT')
        ->and($xiaomi['flags'] ?? [])->toContain('cobro_confirmado_ft');

    $hugo = $all->first(fn ($r) => (int) ($r['source_row'] ?? 0) === 466);
    expect($hugo)->not->toBeNull()
        ->and($hugo['review_status'] ?? '')->toBe('corrected')
        ->and($hugo['client'] ?? '')->toBe('DAASA')
        ->and((float) ($hugo['interpretation']['cc_payment'] ?? 0))->toBe(1308450.0)
        ->and((float) ($hugo['interpretation']['finance_income'] ?? -1))->toBe(0.0)
        ->and($hugo['flags'] ?? [])->toContain('ingreso_no_duplicado');

    $guards = $batch->preview_payload['sale_semantics_report']['duplicate_income_guards'] ?? [];
    expect(collect($guards)->firstWhere('action', 'suppress_duplicate_income'))->not->toBeNull();

    @unlink($path);
});

test('fila 536 Cintas es cliente CC; 637 cancelación DAASA; 131 Patagonia; 478 pendiente sin amarillo', function () {
    $admin = makeAdmin();
    $blank = ['', '', '', '', '', '', '', '', '', '', '', '', '', ''];
    $rows = [
        ['2026', '', '', '', 'INGRESOS', 'EGRESOS', '', 'CC', '', 'Pagos TC', 'Merca IN', 'Merca OUT', 'Venta', 'Ut'],
        ['', '', '', '', '', '', '', 'IN', 'OUT', '', '', '', '', ''],
        ['FECHA', 'CONCEPTO', 'Cuenta', 'SubCuenta', '', '', '', '', '', '', '', '', '', ''],
    ];
    // Relleno hasta fila 130; fila 131 = Pago VISA con cuenta confirmada Patagonia.
    for ($i = 4; $i <= 130; $i++) {
        $rows[] = $blank;
    }
    $rows[] = [46055, 'Pago VISA', 'VISA', 'VISA', '', '', '', '', '', 513853.4, '', '', '', '']; // 131
    for ($i = 132; $i <= 477; $i++) {
        $rows[] = $blank;
    }
    $rows[] = [46146, 'Pago VISA', 'VISA', 'Patagonia', '', '', '', '', '', '', '', '', '', '']; // 478
    for ($i = 479; $i <= 535; $i++) {
        $rows[] = $blank;
    }
    $rows[] = [46156, 'Cintas Industriales - Reparación MUCAD', 'Reparaciones', 'Cintas', '', '', '', 200000, '', '', '', '', '', '']; // 536
    for ($i = 537; $i <= 636; $i++) {
        $rows[] = $blank;
    }
    $rows[] = [46183, 'DAASA Cable', 'Ventas', 'Patagonia', '', '', '', '', 464000, '', '', 329800, '', '']; // 637

    $path = writeTempXlsx($rows, 'Movimientos');

    $batch = app(HistoricalMovementsPreviewService::class)->analyzePath($path, 'cc-tarjeta-final.xlsx', $admin->id);
    $all = collect($batch->preview_payload['rows_sample_corrected'] ?? [])
        ->merge($batch->preview_payload['rows_sample_pending_complete'] ?? [])
        ->merge($batch->preview_payload['rows_sample_yellow'] ?? [])
        ->merge($batch->preview_payload['rows_sample_green'] ?? []);

    $pagoOk = $all->first(fn ($r) => (int) ($r['source_row'] ?? 0) === 131);
    expect($pagoOk)->not->toBeNull()
        ->and($pagoOk['review_status'] ?? '')->toBe('corrected')
        ->and($pagoOk['interpretation']['payment_account_alias'] ?? '')->toBe('Patagonia')
        ->and($pagoOk['flags'] ?? [])->toContain('pago_tarjeta_cuenta_patagonia')
        ->and($pagoOk['needs_human_decision'] ?? true)->toBeFalse();

    $pagoPend = $all->first(fn ($r) => (int) ($r['source_row'] ?? 0) === 478);
    expect($pagoPend)->not->toBeNull()
        ->and($pagoPend['review_status'] ?? '')->toBe('pending_complete')
        ->and($pagoPend['needs_human_decision'] ?? true)->toBeFalse()
        ->and($pagoPend['flags'] ?? [])->toContain('importe_pago_tarjeta_desconocido')
        ->and($pagoPend['root_cause'] ?? '')->toBe('importe_pago_tarjeta_desconocido');

    $cintas = $all->first(fn ($r) => str_contains((string) ($r['concepto'] ?? ''), 'MUCAD'));
    expect($cintas)->not->toBeNull()
        ->and($cintas['review_status'] ?? '')->toBe('corrected')
        ->and($cintas['client'] ?? '')->toBe('Cintas')
        ->and((float) ($cintas['interpretation']['cc_charge'] ?? 0))->toBe(200000.0)
        ->and($cintas['flags'] ?? [])->toContain('cliente_cintas_confirmado')
        ->and($cintas['flags'] ?? [])->not->toContain('cuenta_desconocida');

    $cable = $all->first(fn ($r) => ($r['concepto'] ?? '') === 'DAASA Cable');
    expect($cable)->not->toBeNull()
        ->and($cable['review_status'] ?? '')->toBe('corrected')
        ->and($cable['client'] ?? '')->toBe('DAASA')
        ->and((float) ($cable['interpretation']['cc_payment'] ?? 0))->toBe(464000.0)
        ->and((float) ($cable['interpretation']['economic_venta'] ?? -1))->toBe(0.0)
        ->and((float) ($cable['interpretation']['finance_income'] ?? 0))->toBe(464000.0)
        ->and($cable['flags'] ?? [])->toContain('cc_cancelacion_daasa_confirmada');

    expect(($batch->classification_summary['yellow'] ?? -1))->toBe(0);

    @unlink($path);
});
