<?php

use App\Enums\ImportReviewStatus;
use App\Models\Movement;
use App\Services\Imports\Historical\HistoricalImportGate;
use App\Services\Imports\Historical\HistoricalMovementsPreviewService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ExchangeRateSeeder;
use Database\Seeders\InventoryCatalogSeeder;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    $this->seed(CurrencySeeder::class);
    $this->seed(ExchangeRateSeeder::class);
    $this->seed(InventoryCatalogSeeder::class);
});

function closureTempXlsx(array $dataRows): string
{
    $ss = new Spreadsheet;
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('Movimientos');
    $headers = [
        ['2026'],
        [''],
        ['FECHA', 'CONCEPTO', 'Cuenta', 'SubCuenta', 'INGRESOS', 'EGRESOS', '', 'CC IN', 'CC OUT', 'pagos_tc'],
    ];
    $r = 1;
    foreach ($headers as $row) {
        foreach ($row as $c => $val) {
            $sheet->setCellValue([$c + 1, $r], $val);
        }
        $r++;
    }
    // Month headers + placeholders around authorized rows
    $sheet->setCellValue([1, 4], 'mayo');
    // Row 478 style pending card payment unknown — place at excel row 10 for simplicity in unit sheet
    // Build minimal: include placeholders 589-592 and a known expense for amount history
    $rows = $dataRows;
    foreach ($rows as $row) {
        foreach ($row as $c => $val) {
            $sheet->setCellValue([$c + 1, $r], $val);
        }
        $r++;
    }
    $path = tempnam(sys_get_temp_dir(), 'c11e').'.xlsx';
    (new Xlsx($ss))->save($path);
    $ss->disconnectWorksheets();

    return $path;
}

test('confirm genérico sigue bloqueado', function () {
    $admin = makeAdmin();
    $path = closureTempXlsx([
        [46023, 'Cafe', 'Comidas', 'MP Fer', '', 1500],
    ]);
    $batch = app(HistoricalMovementsPreviewService::class)->analyzePath($path, 't.xlsx', $admin->id);
    expect(fn () => app(HistoricalMovementsPreviewService::class)->confirm($batch))
        ->toThrow(InvalidArgumentException::class);
    @unlink($path);
});

test('applicator completa placeholders y crea reconstrucciones sin duplicar', function () {
    $admin = makeAdmin();
    // Real excel preferred for full closure; skip if missing
    $movementsPath = 'C:/Users/Usuario/Downloads/GASTOS MENSUALES 2026.xlsx';
    if (! is_file($movementsPath)) {
        $this->markTestSkipped('Excel real no disponible');
    }

    $batch = app(HistoricalMovementsPreviewService::class)->analyzePath(
        $movementsPath,
        basename($movementsPath),
        $admin->id,
        '2026-08-15',
        '2026-01-01',
        '2026-08-14'
    );

    $summary = $batch->classification_summary;
    expect($summary['yellow'] ?? 1)->toBe(0)
        ->and($summary['red'] ?? 1)->toBe(0)
        ->and($summary['pending_complete'] ?? 0)->toBe(2);

    $closure = $batch->preview_payload['authorized_closure'] ?? [];
    expect(count($closure['placeholders_completed'] ?? []))->toBe(7)
        ->and(count($closure['placeholders_excluded'] ?? []))->toBe(2)
        ->and(count($closure['reconstructions_created'] ?? []))->toBe(17);

    $allPath = $batch->preview_payload['rows_all_path'] ?? null;
    expect($allPath)->not->toBeNull();
    $all = json_decode(Storage::disk('local')->get($allPath), true);
    $rows = collect($all['rows'] ?? []);
    $pending = $rows->where('review_status', 'pending_complete')->pluck('source_row')->sort()->values()->all();
    expect($pending)->toBe([478, 588]);

    $r590 = $rows->first(fn ($r) => (int) ($r['source_row'] ?? 0) === 590);
    expect($r590['review_status'])->toBe(ImportReviewStatus::Inferred->value)
        ->and((float) $r590['amounts']['egresos'])->toBe(6799.0)
        ->and($r590['dato_inferido'])->toBeTrue();

    $r589 = $rows->first(fn ($r) => (int) ($r['source_row'] ?? 0) === 589);
    expect($r589['review_status'])->toBe(ImportReviewStatus::Excluded->value);

    $gate = app(HistoricalImportGate::class)->evaluate($batch->fresh());
    expect($gate['passed'])->toBeTrue();

    // Confirm authorized on local sqlite — smoke
    $this->actingAs($admin);
    $result = app(HistoricalMovementsPreviewService::class)
        ->confirmAuthorizedHistoricalImport($batch->fresh(), (string) config('historical_closure_11e.authorization_token'), $admin->id);
    expect($result['batch']->status)->toBe('confirmed')
        ->and(Movement::query()->where('import_batch_id', $result['batch']->id)->count())->toBeGreaterThan(0);

    // Idempotencia 2ª ejecución
    expect(fn () => app(HistoricalMovementsPreviewService::class)
        ->confirmAuthorizedHistoricalImport($result['batch']->fresh(), (string) config('historical_closure_11e.authorization_token'), $admin->id))
        ->toThrow(InvalidArgumentException::class);
})->group('real-preview');
