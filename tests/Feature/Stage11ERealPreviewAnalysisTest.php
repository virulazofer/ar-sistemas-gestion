<?php

/**
 * Análisis one-shot de planillas reales (preview only).
 * No confirma importación. Usa RefreshDatabase del entorno de test.
 */

use App\Models\User;
use App\Services\Imports\Historical\HistoricalMovementsPreviewService;
use App\Services\Imports\Historical\SupplierCatalogPreviewService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\InventoryCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

test('análisis real 11E-1 preview sin confirmar', function () {
    $catalogPath = 'C:/Users/Usuario/Downloads/Lis7.8.26.xlsx';
    $movementsPath = 'C:/Users/Usuario/Downloads/GASTOS MENSUALES 2026.xlsx';

    if (! is_file($catalogPath) || ! is_file($movementsPath)) {
        $this->markTestSkipped('Planillas reales no disponibles en Downloads.');
    }

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CurrencySeeder::class);
    $this->seed(InventoryCatalogSeeder::class);
    $admin = makeAdmin();

    $catalogBatch = app(SupplierCatalogPreviewService::class)->analyzePath(
        $catalogPath,
        basename($catalogPath),
        $admin->id,
        '2026-08-07'
    );

    $movementsBatch = app(HistoricalMovementsPreviewService::class)->analyzePath(
        $movementsPath,
        basename($movementsPath),
        $admin->id,
        '2026-08-15',
        '2026-01-01',
        '2026-08-14'
    );

    $report = [
        'confirm_executed' => false,
        'catalog' => [
            'batch_status' => $catalogBatch->status,
            'file_hash' => $catalogBatch->file_hash,
            'summary' => $catalogBatch->classification_summary,
        ],
        'movements' => [
            'batch_status' => $movementsBatch->status,
            'file_hash' => $movementsBatch->file_hash,
            'summary' => $movementsBatch->classification_summary,
            'reconciliation' => $movementsBatch->reconciliation_payload,
            'subscriptions' => $movementsBatch->preview_payload['subscriptions_detected'] ?? [],
            'clients' => $movementsBatch->preview_payload['masters']['clients'] ?? [],
            'suppliers' => $movementsBatch->preview_payload['masters']['suppliers'] ?? [],
            'categories' => $movementsBatch->preview_payload['masters']['categories'] ?? [],
            'financial_seen' => $movementsBatch->preview_payload['masters']['financial_seen'] ?? [],
            'unknown_accounts' => $movementsBatch->preview_payload['masters']['unknown_accounts'] ?? [],
        ],
    ];

    $out = storage_path('app/imports/reports');
    if (! is_dir($out)) {
        mkdir($out, 0775, true);
    }
    $file = $out.'/real-preview-'.date('Ymd-His').'.json';
    file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    expect($catalogBatch->status)->toBe('preview')
        ->and($movementsBatch->status)->toBe('preview')
        ->and($movementsBatch->options['confirm_enabled'] ?? true)->toBeFalse();

    // Surface path for the agent report
    fwrite(STDERR, "\nREAL_PREVIEW_REPORT={$file}\n");
    fwrite(STDERR, 'CATALOG_VALID='.($report['catalog']['summary']['products_valid'] ?? 0)."\n");
    fwrite(STDERR, 'MOV_GREEN='.($report['movements']['summary']['green'] ?? 0)."\n");
    fwrite(STDERR, 'MOV_YELLOW='.($report['movements']['summary']['yellow'] ?? 0)."\n");
    fwrite(STDERR, 'MOV_RED='.($report['movements']['summary']['red'] ?? 0)."\n");
})->group('real-preview');
