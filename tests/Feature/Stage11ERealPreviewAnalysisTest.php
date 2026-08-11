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

    $allPath = $movementsBatch->preview_payload['rows_all_path'] ?? null;
    $keyRows = [];
    if ($allPath && \Illuminate\Support\Facades\Storage::disk('local')->exists($allPath)) {
        $all = json_decode(\Illuminate\Support\Facades\Storage::disk('local')->get($allPath), true);
        $want = [5, 15, 124, 177, 236, 254, 345, 460, 461, 462, 466, 536, 637, 720, 817, 818, 819];
        $cardRowsWant = [31, 72, 73, 131, 198, 199, 253, 297, 301, 354, 355, 356, 409, 417, 478, 530, 531, 599, 657, 674, 748, 784, 789, 865];
        $ccResidualWant = [5, 15, 177, 236, 254, 466, 536, 637];
        $cardRows = [];
        $ccResidualRows = [];
        $yellowRows = [];
        $pendingRows = [];
        foreach ($all['rows'] ?? [] as $row) {
            $n = (int) ($row['source_row'] ?? 0);
            if (($row['review_status'] ?? '') === 'yellow') {
                $yellowRows[] = [
                    'source_row' => $n,
                    'concepto' => $row['concepto'] ?? null,
                    'root_cause' => $row['root_cause'] ?? null,
                    'operational_reason' => $row['operational_reason'] ?? null,
                    'flags' => $row['flags'] ?? [],
                ];
            }
            if (($row['review_status'] ?? '') === 'pending_complete') {
                $pendingRows[] = [
                    'source_row' => $n,
                    'concepto' => $row['concepto'] ?? null,
                    'root_cause' => $row['root_cause'] ?? null,
                ];
            }
            if (in_array($n, $want, true)) {
                $keyRows[$n] = [
                    'source_row' => $n,
                    'review_status' => $row['review_status'] ?? null,
                    'operational_reason' => $row['operational_reason'] ?? null,
                    'needs_human_decision' => $row['needs_human_decision'] ?? null,
                    'client' => $row['client'] ?? null,
                    'date' => $row['date'] ?? null,
                    'date_original' => $row['date_original'] ?? null,
                    'date_inferred_month_end' => $row['date_inferred_month_end'] ?? false,
                    'date_inference_rule' => $row['date_inference_rule'] ?? null,
                    'month_context' => $row['month_context'] ?? null,
                    'concepto' => $row['concepto'] ?? null,
                    'root_cause' => $row['root_cause'] ?? null,
                    'flags' => $row['flags'] ?? [],
                    'amounts' => $row['amounts'] ?? [],
                    'interpretation' => [
                        'kind' => $row['interpretation']['kind'] ?? null,
                        'cc_charge' => $row['interpretation']['cc_charge'] ?? null,
                        'cc_payment' => $row['interpretation']['cc_payment'] ?? null,
                        'excel_cc_in' => $row['interpretation']['excel_cc_in'] ?? null,
                        'excel_cc_out' => $row['interpretation']['excel_cc_out'] ?? null,
                        'finance_income' => $row['interpretation']['finance_income'] ?? null,
                        'is_opening_adjustment' => $row['interpretation']['is_opening_adjustment'] ?? null,
                        'would_generate' => $row['interpretation']['would_generate'] ?? [],
                        'notes' => $row['interpretation']['notes'] ?? [],
                        'corrections' => $row['interpretation']['corrections'] ?? [],
                    ],
                ];
            }
            if (in_array($n, $ccResidualWant, true)) {
                $ccResidualRows[$n] = $keyRows[$n] ?? [
                    'source_row' => $n,
                    'review_status' => $row['review_status'] ?? null,
                    'root_cause' => $row['root_cause'] ?? null,
                    'concepto' => $row['concepto'] ?? null,
                    'flags' => $row['flags'] ?? [],
                ];
            }
            if (in_array($n, $cardRowsWant, true)) {
                $cardRows[$n] = [
                    'source_row' => $n,
                    'review_status' => $row['review_status'] ?? null,
                    'operational_reason' => $row['operational_reason'] ?? null,
                    'needs_human_decision' => $row['needs_human_decision'] ?? null,
                    'human_decision_options' => $row['human_decision_options'] ?? [],
                    'date' => $row['date'] ?? null,
                    'concepto' => $row['concepto'] ?? null,
                    'cuenta' => $row['excel_cuenta_category'] ?? null,
                    'subcuenta' => $row['excel_subcuenta_account'] ?? null,
                    'root_cause' => $row['root_cause'] ?? null,
                    'flags' => $row['flags'] ?? [],
                    'amounts' => $row['amounts'] ?? [],
                    'interpretation' => [
                        'kind' => $row['interpretation']['kind'] ?? null,
                        'finance_expense' => $row['interpretation']['finance_expense'] ?? null,
                        'card_alias' => $row['interpretation']['card_alias'] ?? null,
                        'payment_account_alias' => $row['interpretation']['payment_account_alias'] ?? null,
                        'card_liability_decrease' => $row['interpretation']['card_liability_decrease'] ?? null,
                        'payment_account_decrease' => $row['interpretation']['payment_account_decrease'] ?? null,
                        'would_generate' => $row['interpretation']['would_generate'] ?? [],
                        'notes' => $row['interpretation']['notes'] ?? [],
                    ],
                ];
            }
        }
    }

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
            'root_cause_groups' => $movementsBatch->preview_payload['root_cause_groups'] ?? [],
            'difference_attribution' => $movementsBatch->preview_payload['difference_attribution'] ?? [],
            'applied_rules' => $movementsBatch->preview_payload['applied_rules'] ?? [],
            'sale_semantics_report' => $movementsBatch->preview_payload['sale_semantics_report'] ?? [],
            'recurring_services' => $movementsBatch->preview_payload['recurring_services'] ?? [],
            'scope_resolution' => $movementsBatch->preview_payload['scope_resolution'] ?? [],
            'key_rows' => $keyRows,
            'cc_residual_rows' => $ccResidualRows ?? [],
            'yellow_rows' => $yellowRows ?? [],
            'pending_complete_rows' => $pendingRows ?? [],
            'card_payment_rows' => $cardRows ?? [],
            'card_payment_summary' => [
                'total' => count($cardRows ?? []),
                'by_status' => array_count_values(array_map(
                    fn ($r) => (string) ($r['review_status'] ?? 'unknown'),
                    array_values($cardRows ?? [])
                )),
            ],
            'rows_all_path' => $allPath,
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
    fwrite(STDERR, 'MOV_INFERRED='.($report['movements']['summary']['inferred'] ?? 0)."\n");
    fwrite(STDERR, 'MOV_CORRECTED='.($report['movements']['summary']['corrected'] ?? 0)."\n");
    fwrite(STDERR, 'MOV_YELLOW='.($report['movements']['summary']['yellow'] ?? 0)."\n");
    fwrite(STDERR, 'MOV_RED='.($report['movements']['summary']['red'] ?? 0)."\n");
    fwrite(STDERR, 'MOV_PENDING='.($report['movements']['summary']['pending_complete'] ?? 0)."\n");
    fwrite(STDERR, 'DATES_MONTH_END='.($report['movements']['summary']['dates_inferred_month_end'] ?? 0)."\n");
    fwrite(STDERR, 'RECURRING_RECONSTRUCT='.count($report['movements']['recurring_services']['final_reconstruct_historical'] ?? [])."\n");
    fwrite(STDERR, 'RECURRING_COMPLETE_PENDING='.count($report['movements']['recurring_services']['final_complete_pending'] ?? [])."\n");
    fwrite(STDERR, 'RECURRING_AUGUST_POST='.count($report['movements']['recurring_services']['final_august_post_cutover'] ?? [])."\n");
    fwrite(STDERR, 'RECURRING_ABSORBED='.($report['movements']['recurring_services']['correction_stats']['absorbed_by_placeholders'] ?? 0)."\n");
    fwrite(STDERR, 'RECURRING_AUSA_ELIM='.($report['movements']['recurring_services']['correction_stats']['ausa_proposals_eliminated'] ?? 0)."\n");
    fwrite(STDERR, 'SCOPE_PERSONAL='.($report['movements']['scope_resolution']['to_personal'] ?? 0)."\n");
    fwrite(STDERR, 'SCOPE_PROFESSIONAL='.($report['movements']['scope_resolution']['to_professional'] ?? 0)."\n");
    fwrite(STDERR, 'SCOPE_AMBIGUOUS='.($report['movements']['scope_resolution']['still_ambiguous'] ?? 0)."\n");
    fwrite(STDERR, 'MOV_EXCLUDED='.($report['movements']['summary']['excluded'] ?? 0)."\n");
    fwrite(STDERR, 'DATES_APPLIED='.($report['movements']['summary']['dates_applied_preview'] ?? 0)."\n");
    fwrite(STDERR, 'CARD_ROWS='.($report['movements']['card_payment_summary']['total'] ?? 0)."\n");
    fwrite(STDERR, 'CARD_BY_STATUS='.json_encode($report['movements']['card_payment_summary']['by_status'] ?? [])."\n");
})->group('real-preview');
