<?php

use App\Models\Client;
use App\Models\CommercialCharge;
use App\Models\ImportBatch;
use App\Models\Movement;
use App\Services\Clients\ClientCcTimelineService;
use App\Services\Imports\Historical\DaasaPost11F3ReconciliationService;
use App\Services\Imports\Historical\GlobalFormulaRepairService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ExchangeRateSeeder;
use Database\Seeders\FinancialAccountSeeder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function seedGlobalFormulaRepair(): void
{
    test()->seed(CurrencySeeder::class);
    test()->seed(FinancialAccountSeeder::class);
    test()->seed(ExchangeRateSeeder::class);
}

function seedGlobal11eBatch(): ImportBatch
{
    $admin = \App\Models\User::query()->orderBy('id')->first();

    return ImportBatch::query()->create([
        'uuid' => GlobalFormulaRepairService::IMPORT_BATCH_UUID,
        'entity_type' => 'movements',
        'importer_kind' => 'historical_movements',
        'status' => 'confirmed',
        'file_hash' => 'test-global-formula-repair',
        'original_filename' => 'GASTOS MENSUALES 2026.xlsx',
        'confirmed_at' => now(),
        'user_id' => $admin?->id ?? 1,
    ]);
}

/**
 * Minimal workbook: header + a few formula rows covering categories.
 */
function makeFormulaFixtureExcel(): string
{
    $ss = new Spreadsheet;
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('Movimientos');
    // Row 3 headers (data starts row 4)
    $sheet->fromArray(['FECHA', 'CONCEPTO', 'Cuenta', 'SubCuenta', 'Ingresos', 'Egresos', 'x', 'CC IN', 'CC OUT', 'Pagos TC', 'Merca IN', 'Merca OUT', 'Venta', 'Utilidad'], null, 'A3');

    // Row 4: personal expense formula VERDE
    $sheet->setCellValue('A4', 46040); // excel date-ish serial ignored; set date via value
    $sheet->setCellValue('B4', 'Cena test Nelly');
    $sheet->setCellValue('C4', 'Gastos Fer');
    $sheet->setCellValue('D4', 'MC');
    $sheet->setCellValue('F4', '=87000-50000');

    // Row 5: month summary ROJO (non-zero SUM so not ZERO_REAL)
    $sheet->setCellValue('D5', 'ENERO');
    $sheet->setCellValue('I5', '=SUM(100,200,300)');

    // Row 6: professional expense VERDE
    $sheet->setCellValue('B6', 'IA CURSOR');
    $sheet->setCellValue('C6', 'Servicios');
    $sheet->setCellValue('D6', 'VISA');
    $sheet->setCellValue('F6', '=20*1460');

    // Row 7: analysis-only ROJO
    $sheet->setCellValue('B7', 'Invid analysis');
    $sheet->setCellValue('C7', 'Mercaderias');
    $sheet->setCellValue('D7', 'FT');
    $sheet->setCellValue('K7', '=100*1400');

    // Row 8: ZERO_REAL
    $sheet->setCellValue('D8', 'SEPTIEMBRE');
    $sheet->setCellValue('I8', '=0*1');

    // Row 9: CC other client AMARILLO (no client)
    $sheet->setCellValue('B9', 'Charly - Actualización');
    $sheet->setCellValue('C9', 'Ventas');
    $sheet->setCellValue('D9', 'FT');
    $sheet->setCellValue('H9', '=100*1400');

    // Row 10: interest as expense AMARILLO
    $sheet->setCellValue('B10', 'Intereses MP Gabi');
    $sheet->setCellValue('C10', 'Intereses ganados');
    $sheet->setCellValue('D10', 'MP Fer');
    $sheet->setCellValue('F10', '=11.14*1471.77');

    $path = storage_path('app/testing/GLOBAL_FORMULA_FIXTURE.xlsx');
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    (new Xlsx($ss))->save($path);
    $ss->disconnectWorksheets();

    return $path;
}

test('global formula classification covers verde amarillo rojo zero_real', function () {
    $admin = makeAdmin();
    seedGlobalFormulaRepair();
    $this->actingAs($admin);
    seedGlobal11eBatch();

    $path = makeFormulaFixtureExcel();
    $svc = app(GlobalFormulaRepairService::class);
    $report = $svc->classify($path);

    $byRow = collect($report['rows'])->keyBy('row');

    expect($byRow[4]['classification'])->toBe('VERDE_REPAIR')
        ->and($byRow[5]['classification'])->toBe('ROJO_BLOCK')
        ->and($byRow[6]['classification'])->toBe('VERDE_REPAIR')
        ->and($byRow[7]['classification'])->toBe('ROJO_BLOCK')
        ->and($byRow[8]['classification'])->toBe('ZERO_REAL')
        ->and($byRow[9]['classification'])->toBe('AMARILLO_REVIEW')
        ->and($byRow[10]['classification'])->toBe('AMARILLO_REVIEW');

    expect($report['verde_count'])->toBeGreaterThanOrEqual(2)
        ->and($report['amarillo_count'])->toBeGreaterThanOrEqual(2)
        ->and($report['rojo_count'])->toBeGreaterThanOrEqual(2);
});

test('global formula verde apply is idempotent and does not touch daasa', function () {
    $admin = makeAdmin();
    seedGlobalFormulaRepair();
    $this->actingAs($admin);
    seedGlobal11eBatch();

    $daasa = Client::query()->create([
        'code' => 3,
        'name' => 'DAASA',
        'status' => 'active',
    ]);
    app(\App\Services\Commercial\CommercialChargeService::class)->create([
        'client_id' => $daasa->id,
        'charge_type' => 'sale',
        'concept' => 'Hugo Ferreyra para Manu',
        'amount' => '1308450.00',
        'currency_code' => 'ARS',
        'charged_on' => '2026-04-16',
        'documental_status' => 'not_required',
        'notes' => DaasaPost11F3ReconciliationService::BATCH_NAME.':row:404:cc_in | reason=test',
        'create_cc' => true,
        'apply_available_credit' => false,
    ]);

    $path = makeFormulaFixtureExcel();
    $svc = app(GlobalFormulaRepairService::class);

    $first = $svc->run($path, dryRun: false);
    $created = collect($first['actions'])->where('status', 'created')->count();
    expect($created)->toBeGreaterThanOrEqual(2);

    $second = $svc->idempotencyCheck($path);
    expect($second['all_idempotent'])->toBeTrue();

    $daasaCheck = $svc->daasaReadonlyCheck();
    expect($daasaCheck['untouched_by_global'] ?? false)->toBeTrue()
        ->and(CommercialCharge::query()->where('notes', 'like', '%GLOBAL_FORMULA_REPAIR_%')->count())->toBe(0);

    $movCount = Movement::query()->where('external_id', 'like', 'global_formula_repair:20260811:%')->count();
    expect($movCount)->toBe($created);
});

test('timeline origin label is unified for global formula repair charges', function () {
    $admin = makeAdmin();
    seedGlobalFormulaRepair();
    $this->actingAs($admin);

    $client = Client::query()->create([
        'code' => 99,
        'name' => 'Timeline Client',
        'status' => 'active',
    ]);

    $charge = app(\App\Services\Commercial\CommercialChargeService::class)->create([
        'client_id' => $client->id,
        'charge_type' => 'sale',
        'concept' => 'Test global repair charge',
        'amount' => '1000.00',
        'currency_code' => 'ARS',
        'charged_on' => '2026-05-01',
        'documental_status' => 'not_required',
        'notes' => 'GLOBAL_FORMULA_REPAIR_20260811:row:999:cc_in | reason=test',
        'create_cc' => true,
        'apply_available_credit' => false,
    ]);

    $timeline = app(ClientCcTimelineService::class)->buildTimeline($client);
    $row = $timeline->first(fn ($r) => ($r['related']['charge_id'] ?? null) === $charge->id);
    expect($row)->not->toBeNull()
        ->and($row['origin_label'])->toBe('Cargo reconciliación');
});
