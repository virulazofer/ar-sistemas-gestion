<?php

namespace App\Services\Imports\Historical;

use App\Enums\ImportReviewStatus;
use App\Models\ImportBatch;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class HistoricalMovementsPreviewService
{
    public function __construct(
        private readonly AccountMappingService $accounts,
        private readonly ClientDetectionService $clients,
        private readonly HistoricalRootCauseAnalyzer $rootCauses,
        private readonly HistoricalMappingRuleService $rules,
        private readonly HistoricalSaleSemantics $saleSemantics,
        private readonly HistoricalRecurringServicesAnalyzer $recurringServices,
        private readonly HistoricalAuthorizedClosureApplicator $closureApplicator,
        private readonly HistoricalScopeClassifier $scopeClassifier,
        private readonly HistoricalOperationalStatusClassifier $operationalStatus,
        private readonly AuditLogger $audit,
    ) {}

    public function analyzePath(
        string $absolutePath,
        string $originalFilename,
        int $userId,
        ?string $cutoverDate = null,
        ?string $periodFrom = null,
        ?string $periodTo = null,
    ): ImportBatch {
        if (! is_file($absolutePath)) {
            throw new InvalidArgumentException('Archivo de movimientos no encontrado.');
        }

        $cutoverDate ??= (string) config('historical_import.cutover_date');
        $periodFrom ??= (string) config('historical_import.period_from');
        $periodTo ??= (string) config('historical_import.period_to');

        $hash = hash_file('sha256', $absolutePath);
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION) ?: 'xlsx');
        $storedPath = 'imports/'.now()->format('Y/m').'/'.Str::uuid().'.'.$ext;
        Storage::disk('local')->put($storedPath, file_get_contents($absolutePath));

        $accountMasters = $this->accounts->ensurePreviewMasters();
        $this->rules->ensureUnequivocalRules();
        $preview = $this->buildPreview(
            Storage::disk('local')->path($storedPath),
            $cutoverDate,
            $periodFrom,
            $periodTo,
            $accountMasters
        );

        $batch = ImportBatch::query()->create([
            'uuid' => (string) Str::uuid(),
            'entity_type' => 'historical_movements',
            'importer_kind' => 'historical_movements',
            'source' => 'file',
            'original_filename' => $originalFilename,
            'disk' => 'local',
            'stored_path' => $storedPath,
            'file_hash' => $hash,
            'cutover_date' => $cutoverDate,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'status' => 'preview',
            'rows_total' => $preview['summary']['rows_read'],
            'rows_valid' => ($preview['summary']['green'] ?? 0)
                + ($preview['summary']['inferred'] ?? 0)
                + ($preview['summary']['corrected'] ?? 0)
                + ($preview['summary']['yellow'] ?? 0),
            'rows_invalid' => $preview['summary']['red'],
            'rows_duplicate' => $preview['summary']['excluded'],
            'rows_green' => $preview['summary']['green'],
            'rows_yellow' => $preview['summary']['yellow'],
            'rows_red' => $preview['summary']['red'],
            'rows_imported' => 0,
            'preview_payload' => [
                // Keep payload bounded for DB: summary + samples + masters
                'summary' => $preview['summary'],
                'reconciliation' => $preview['reconciliation'],
                'difference_attribution' => $preview['difference_attribution'],
                'root_cause_groups' => $preview['root_cause_groups'],
                'masters' => $preview['masters'],
                'subscriptions_detected' => $preview['subscriptions_detected'],
                'applied_rules' => $preview['applied_rules'],
                'rows_sample_green' => array_slice($preview['rows_by_status']['green'], 0, 30),
                'rows_sample_inferred' => array_slice($preview['rows_by_status']['inferred'] ?? [], 0, 30),
                'rows_sample_corrected' => array_slice($preview['rows_by_status']['corrected'] ?? [], 0, 30),
                'rows_sample_yellow' => array_slice($preview['rows_by_status']['yellow'], 0, 40),
                'rows_sample_red' => array_slice($preview['rows_by_status']['red'], 0, 60),
                'rows_sample_pending_complete' => array_slice($preview['rows_by_status']['pending_complete'] ?? [], 0, 40),
                'rows_all_path' => $preview['rows_all_path'],
                'confirm_blocked' => true,
                'confirm_blocked_reason' => 'Etapa 11E: confirmación definitiva de movimientos deshabilitada hasta autorización expresa.',
                'sale_semantics_report' => $preview['sale_semantics_report'] ?? [],
                'recurring_services' => $preview['recurring_services'] ?? [],
                'authorized_closure' => $preview['authorized_closure'] ?? [],
                'scope_resolution' => $preview['scope_resolution'] ?? [],
            ],
            'classification_summary' => array_merge($preview['summary'], [
                'root_cause_groups' => [
                    'yellow' => array_map(fn ($g) => [
                        'cause' => $g['cause'],
                        'label' => $g['label'],
                        'count' => $g['count'],
                    ], $preview['root_cause_groups']['yellow'] ?? []),
                    'red' => array_map(fn ($g) => [
                        'cause' => $g['cause'],
                        'label' => $g['label'],
                        'count' => $g['count'],
                    ], $preview['root_cause_groups']['red'] ?? []),
                    'pending_complete' => array_map(fn ($g) => [
                        'cause' => $g['cause'],
                        'label' => $g['label'],
                        'count' => $g['count'],
                    ], $preview['root_cause_groups']['pending_complete'] ?? []),
                ],
                'scope_resolution_summary' => [
                    'to_personal' => $preview['scope_resolution']['to_personal'] ?? 0,
                    'to_professional' => $preview['scope_resolution']['to_professional'] ?? 0,
                    'still_ambiguous' => $preview['scope_resolution']['still_ambiguous'] ?? 0,
                ],
            ]),
            'reconciliation_payload' => array_merge($preview['reconciliation'], [
                'difference_attribution' => $preview['difference_attribution'],
            ]),
            'error_summary' => ['note' => 'Preview only — no persist operations'],
            'options' => [
                'cutover_date' => $cutoverDate,
                'period_from' => $periodFrom,
                'period_to' => $periodTo,
                'confirm_enabled' => false,
                'authorized_closure_applied' => true,
            ],
            'user_id' => $userId,
        ]);

        $this->audit->log('historical_movements_previewed', $batch, null, [
            'green' => $batch->rows_green,
            'yellow' => $batch->rows_yellow,
            'red' => $batch->rows_red,
            'pending_complete' => $preview['summary']['pending_complete'] ?? 0,
        ], 'Vista previa histórica generada (sin confirmar)');

        return $batch;
    }

    public function analyzeUpload(UploadedFile $file, int $userId, ?string $cutoverDate = null): ImportBatch
    {
        $tmp = $file->getRealPath();
        if ($tmp === false) {
            throw new InvalidArgumentException('No se pudo leer el archivo subido.');
        }

        return $this->analyzePath($tmp, $file->getClientOriginalName(), $userId, $cutoverDate);
    }

    public function confirm(ImportBatch $batch): never
    {
        throw new InvalidArgumentException(
            'Confirmación de movimientos históricos bloqueada. Usá confirmAuthorizedHistoricalImport() con token de autorización 11E.'
        );
    }

    /**
     * Desbloqueo puntual (no permanente): solo con token de autorización 11E + gate OK.
     *
     * @return array{batch: ImportBatch, gate: array<string, mixed>, import: array<string, mixed>}
     */
    public function confirmAuthorizedHistoricalImport(
        ImportBatch $batch,
        string $authorizationToken,
        ?int $actingUserId = null,
    ): array {
        return app(HistoricalMovementsConfirmService::class)
            ->confirmAuthorizedHistoricalImport($batch, $authorizationToken, $actingUserId);
    }

    /**
     * Reprocesa un preview existente aplicando reglas activas (sin confirmar importación).
     */
    public function reprocess(ImportBatch $batch): ImportBatch
    {
        if ($batch->importer_kind !== 'historical_movements') {
            throw new InvalidArgumentException('Solo lotes históricos pueden reprocesarse.');
        }
        if (! $batch->stored_path) {
            throw new InvalidArgumentException('El lote no tiene archivo almacenado.');
        }

        $absolute = Storage::disk($batch->disk ?: 'local')->path($batch->stored_path);
        $this->rules->ensureUnequivocalRules();
        $accountMasters = $this->accounts->ensurePreviewMasters();
        $preview = $this->buildPreview(
            $absolute,
            $batch->cutover_date?->toDateString() ?? (string) config('historical_import.cutover_date'),
            $batch->period_from?->toDateString() ?? (string) config('historical_import.period_from'),
            $batch->period_to?->toDateString() ?? (string) config('historical_import.period_to'),
            $accountMasters,
            $batch
        );

        $batch->update([
            'rows_total' => $preview['summary']['rows_read'],
            'rows_valid' => ($preview['summary']['green'] ?? 0)
                + ($preview['summary']['inferred'] ?? 0)
                + ($preview['summary']['corrected'] ?? 0)
                + ($preview['summary']['yellow'] ?? 0),
            'rows_invalid' => $preview['summary']['red'],
            'rows_duplicate' => $preview['summary']['excluded'],
            'rows_green' => $preview['summary']['green'],
            'rows_yellow' => $preview['summary']['yellow'],
            'rows_red' => $preview['summary']['red'],
            'preview_payload' => [
                'summary' => $preview['summary'],
                'reconciliation' => $preview['reconciliation'],
                'difference_attribution' => $preview['difference_attribution'],
                'root_cause_groups' => $preview['root_cause_groups'],
                'masters' => $preview['masters'],
                'subscriptions_detected' => $preview['subscriptions_detected'],
                'applied_rules' => $preview['applied_rules'],
                'rows_sample_green' => array_slice($preview['rows_by_status']['green'], 0, 30),
                'rows_sample_inferred' => array_slice($preview['rows_by_status']['inferred'] ?? [], 0, 30),
                'rows_sample_corrected' => array_slice($preview['rows_by_status']['corrected'] ?? [], 0, 30),
                'rows_sample_yellow' => array_slice($preview['rows_by_status']['yellow'], 0, 40),
                'rows_sample_red' => array_slice($preview['rows_by_status']['red'], 0, 60),
                'rows_sample_pending_complete' => array_slice($preview['rows_by_status']['pending_complete'] ?? [], 0, 40),
                'rows_all_path' => $preview['rows_all_path'],
                'confirm_blocked' => true,
                'confirm_blocked_reason' => 'Etapa 11E: confirmación definitiva de movimientos deshabilitada hasta autorización expresa.',
                'reprocessed_at' => now()->toDateTimeString(),
                'decisions_applied' => $preview['decisions_applied'] ?? 0,
                'sale_semantics_report' => $preview['sale_semantics_report'] ?? [],
                'recurring_services' => $preview['recurring_services'] ?? [],
                'authorized_closure' => $preview['authorized_closure'] ?? [],
                'scope_resolution' => $preview['scope_resolution'] ?? [],
            ],
            'classification_summary' => array_merge($preview['summary'], [
                'root_cause_groups' => [
                    'yellow' => array_map(fn ($g) => [
                        'cause' => $g['cause'], 'label' => $g['label'], 'count' => $g['count'],
                    ], $preview['root_cause_groups']['yellow'] ?? []),
                    'red' => array_map(fn ($g) => [
                        'cause' => $g['cause'], 'label' => $g['label'], 'count' => $g['count'],
                    ], $preview['root_cause_groups']['red'] ?? []),
                    'pending_complete' => array_map(fn ($g) => [
                        'cause' => $g['cause'], 'label' => $g['label'], 'count' => $g['count'],
                    ], $preview['root_cause_groups']['pending_complete'] ?? []),
                ],
                'scope_resolution_summary' => [
                    'to_personal' => $preview['scope_resolution']['to_personal'] ?? 0,
                    'to_professional' => $preview['scope_resolution']['to_professional'] ?? 0,
                    'still_ambiguous' => $preview['scope_resolution']['still_ambiguous'] ?? 0,
                ],
            ]),
            'reconciliation_payload' => array_merge($preview['reconciliation'], [
                'difference_attribution' => $preview['difference_attribution'],
            ]),
            'options' => array_merge($batch->options ?? [], [
                'confirm_enabled' => false,
                'authorized_closure_applied' => true,
                'reprocessed_at' => now()->toDateTimeString(),
            ]),
        ]);

        $this->audit->log('historical_movements_reprocessed', $batch, null, [
            'green' => $batch->rows_green,
            'yellow' => $batch->rows_yellow,
            'red' => $batch->rows_red,
        ], 'Preview histórico reprocesado con reglas');

        return $batch->fresh();
    }

    /**
     * @param  array{holders: list<array>, accounts: list<array>}  $accountMasters
     * @return array<string, mixed>
     */
    private function buildPreview(
        string $path,
        string $cutoverDate,
        string $periodFrom,
        string $periodTo,
        array $accountMasters,
        ?ImportBatch $batch = null,
    ): array {
        $spreadsheet = IOFactory::load($path);
        $sheetName = (string) config('historical_import.movements_sheet', 'Movimientos');
        $sheet = $spreadsheet->getSheetByName($sheetName) ?? $spreadsheet->getActiveSheet();
        // No calcular toda la hoja (SUMIF rotos en el Excel real). Solo resolver fórmulas de pagos_tc.
        $matrix = $sheet->toArray(null, false, false, false);
        $start = max(0, ((int) config('historical_import.movements_data_start_row', 4)) - 1);
        for ($ri = $start; $ri < count($matrix); $ri++) {
            $rawPagos = $matrix[$ri][9] ?? null;
            if (is_string($rawPagos) && str_starts_with(ltrim($rawPagos), '=')) {
                try {
                    $calc = $sheet->getCell([10, $ri + 1])->getCalculatedValue();
                    if (is_numeric($calc)) {
                        $matrix[$ri][9] = (float) $calc;
                    }
                } catch (Throwable) {
                    // Conservar fórmula; num() dará 0 y la regla de tarjeta marcará sin importe.
                }
            }
        }
        $spreadsheet->disconnectWorksheets();
        $rows = [];
        $excelTotals = [
            'ingresos' => 0.0,
            'egresos' => 0.0,
            'cc_in' => 0.0,
            'cc_out' => 0.0,
            'merca_in' => 0.0,
            'merca_out' => 0.0,
            'venta' => 0.0,
            'ut_ventas' => 0.0,
            'pagos_tc' => 0.0,
        ];
        $clientNames = [];
        $categories = [];
        $financialSeen = [];
        $suppliers = [];
        $unknownAccounts = [];
        $byStatus = [
            'green' => [],
            'inferred' => [],
            'corrected' => [],
            'yellow' => [],
            'red' => [],
            'pending_complete' => [],
            'excluded' => [],
        ];
        $subscriptionBuckets = [];
        $decisionsApplied = 0;
        $monthContext = null;

        $dateDecisions = [];
        $complexDecisions = [];
        $cardDecisions = [];
        $scopeDecisions = [];
        if ($batch) {
            foreach (\App\Models\ImportPreviewDecision::query()->where('import_batch_id', $batch->id)->get() as $d) {
                if ($d->decision_type === 'date') {
                    $dateDecisions[$d->source_row] = $d;
                } elseif ($d->decision_type === 'complex_sale') {
                    $complexDecisions[$d->source_row] = $d;
                } elseif ($d->decision_type === 'card') {
                    $cardDecisions[$d->source_row] = $d;
                } elseif ($d->decision_type === 'scope') {
                    $scopeDecisions[$d->source_row] = $d;
                }
            }
        }

        $monthNames = 'enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre';

        for ($i = $start; $i < count($matrix); $i++) {
            $raw = $matrix[$i];
            $fechaRaw = $raw[0] ?? null;
            $concepto = trim((string) ($raw[1] ?? ''));
            $cuenta = trim((string) ($raw[2] ?? ''));
            $subcuenta = trim((string) ($raw[3] ?? ''));

            if ($concepto === '' && $cuenta === '' && $subcuenta === '' && ($fechaRaw === null || $fechaRaw === '')) {
                continue;
            }
            // Encabezados de mes pueden venir en FECHA o en CONCEPTO
            $fechaAsText = is_string($fechaRaw) ? trim($fechaRaw) : '';
            if ($fechaAsText !== '' && preg_match('/^('.$monthNames.')$/iu', $fechaAsText)) {
                $monthContext = mb_strtolower($fechaAsText);
                continue;
            }
            if ($concepto !== '' && preg_match('/^('.$monthNames.')$/iu', $concepto)) {
                $monthContext = mb_strtolower($concepto);
                continue;
            }
            if ($subcuenta !== '' && preg_match('/^('.$monthNames.')$/iu', $subcuenta) && $concepto === '') {
                continue;
            }

            $sourceRow = $i + 1;
            $date = $this->parseDate($fechaRaw);
            $dateDecision = $dateDecisions[$sourceRow] ?? null;
            if ($dateDecision) {
                $decisionsApplied++;
                $action = $dateDecision->payload['action'] ?? null;
                if ($action === 'exclude') {
                    $excl = [
                        'source_row' => $sourceRow,
                        'concepto' => $concepto,
                        'review_status' => 'excluded',
                        'flags' => ['excluida_por_decision'],
                        'root_cause' => 'excluida',
                        'amounts' => [
                            'ingresos' => 0, 'egresos' => 0, 'cc_in' => 0, 'cc_out' => 0,
                            'pagos_tc' => 0, 'merca_in' => 0, 'merca_out' => 0, 'venta' => 0, 'ut_ventas' => 0,
                        ],
                        'interpretation' => [
                            'kind' => 'excluded',
                            'finance_income' => 0, 'finance_expense' => 0, 'cc_charge' => 0, 'cc_payment' => 0,
                            'would_generate' => [], 'notes' => ['Excluida por decisión de fecha'],
                        ],
                        'date' => $date['iso'] ?? null,
                        'excel_cuenta_category' => $cuenta,
                        'excel_subcuenta_account' => $subcuenta,
                        'decision_applied' => $dateDecision->payload,
                    ];
                    $rows[] = $excl;
                    $byStatus['excluded'][] = $excl;
                    continue;
                }
                if ($action === 'accept' && ($date['iso'] ?? null)) {
                    $date['ok'] = true;
                    $date['error'] = null;
                }
                if ($action === 'correct' && ! empty($dateDecision->payload['corrected_date'])) {
                    $date = ['ok' => true, 'iso' => $dateDecision->payload['corrected_date'], 'error' => null];
                }
            }

            $amounts = [
                'ingresos' => $this->num($raw[4] ?? null),
                'egresos' => $this->num($raw[5] ?? null),
                'cc_in' => $this->num($raw[7] ?? null),
                'cc_out' => $this->num($raw[8] ?? null),
                'pagos_tc' => $this->num($raw[9] ?? null),
                'merca_in' => $this->num($raw[10] ?? null),
                'merca_out' => $this->num($raw[11] ?? null),
                'venta' => $this->num($raw[12] ?? null),
                'ut_ventas' => $this->num($raw[13] ?? null),
            ];

            $userExclusion = config('historical_row_exclusions.user_excluded_source_rows.'.$sourceRow);
            if (is_array($userExclusion)) {
                $exclUser = [
                    'source_file' => basename($path),
                    'sheet' => $sheetName,
                    'source_row' => $sourceRow,
                    'date' => $date['iso'] ?? null,
                    'date_raw' => $fechaRaw,
                    'date_original' => $date['iso'] ?? null,
                    'month_context' => $monthContext,
                    'concepto' => $concepto,
                    'excel_cuenta_category' => $cuenta,
                    'excel_subcuenta_account' => $subcuenta,
                    'amounts' => $amounts,
                    'review_status' => ImportReviewStatus::Excluded->value,
                    'flags' => ['excluida_por_usuario'],
                    'root_cause' => 'excluida_por_usuario',
                    'interpretation' => [
                        'kind' => 'excluded',
                        'finance_income' => 0.0,
                        'finance_expense' => 0.0,
                        'cc_charge' => 0.0,
                        'cc_payment' => 0.0,
                        'would_generate' => [],
                        'notes' => [
                            (string) ($userExclusion['reason'] ?? 'Excluida por decisión del usuario.'),
                            'No es candidato de importación; trazabilidad conservada.',
                        ],
                    ],
                    'trace' => [
                        'archivo' => basename($path),
                        'hoja' => $sheetName,
                        'fila' => $sourceRow,
                        'exclusion' => 'user_decision',
                    ],
                ];
                $rows[] = $exclUser;
                $byStatus['excluded'][] = $exclUser;
                continue;
            }

            // Importes 0 + sin fecha = anotación incompleta (no rojo, no financiero).
            // Intereses ganados (asiento de ingresos/utilidades) se conserva aunque importe = 0.
            if ($this->isPendingCompleteAnnotation($date, $amounts, $concepto, $cuenta)) {
                $rowHash = hash('sha256', implode('|', [
                    $sheetName, $sourceRow, (string) $fechaRaw, $concepto, $cuenta, $subcuenta,
                    json_encode($amounts),
                ]));
                $pending = [
                    'source_file' => basename($path),
                    'sheet' => $sheetName,
                    'source_row' => $sourceRow,
                    'row_hash' => $rowHash,
                    'date' => null,
                    'date_raw' => $fechaRaw,
                    'date_original' => null,
                    'suggested_date' => null,
                    'date_suggestion_reason' => null,
                    'month_context' => $monthContext,
                    'concepto' => $concepto,
                    'excel_cuenta_category' => $cuenta,
                    'excel_subcuenta_account' => $subcuenta,
                    'amounts' => $amounts,
                    'review_status' => ImportReviewStatus::PendingComplete->value,
                    'flags' => ['pendiente_completar'],
                    'root_cause' => 'pendiente_completar',
                    'sale_kind' => null,
                    'proposed_scope' => null,
                    'scope_ambiguous' => false,
                    'client' => null,
                    'interpretation' => [
                        'kind' => 'pendiente_completar',
                        'finance_income' => 0.0,
                        'finance_expense' => 0.0,
                        'cc_charge' => 0.0,
                        'cc_payment' => 0.0,
                        'economic_venta' => 0.0,
                        'economic_utilidad' => 0.0,
                        'merca_in' => 0.0,
                        'merca_out' => 0.0,
                        'excel_cc_in' => 0.0,
                        'excel_cc_out' => 0.0,
                        'corrections' => [],
                        'flags' => ['pendiente_completar'],
                        'would_generate' => [],
                        'notes' => [
                            'Anotación incompleta del usuario — pendiente de completar.',
                            'No genera movimiento financiero ni afecta conciliación.',
                            'Conservar concepto y datos disponibles; no eliminar.',
                        ],
                        'components' => null,
                    ],
                    'trace' => [
                        'archivo' => basename($path),
                        'hoja' => $sheetName,
                        'fila' => $sourceRow,
                    ],
                ];
                $rows[] = $pending;
                $byStatus['pending_complete'][] = $pending;
                continue;
            }

            // Solo filas con impacto económico/potencial suman a totales Excel de conciliación.
            foreach ($amounts as $k => $v) {
                $excelTotals[$k] += $v;
            }

            if ($cuenta !== '') {
                $categories[$cuenta] = ($categories[$cuenta] ?? 0) + 1;
            }

            $accountDef = $this->rules->resolveAccountAlias($subcuenta, $this->accounts);
            if ($accountDef) {
                $financialSeen[$accountDef['alias'] ?? $subcuenta] = ($financialSeen[$accountDef['alias'] ?? $subcuenta] ?? 0) + 1;
            } elseif ($subcuenta !== '' && ! $this->looksLikeClientSubcuenta($subcuenta, $cuenta)) {
                $unknownAccounts[$subcuenta] = ($unknownAccounts[$subcuenta] ?? 0) + 1;
            }

            $client = $this->clients->extractFromConcept($concepto, $cuenta === 'CC' ? $subcuenta : $subcuenta);
            $openingCc = $this->saleSemantics->matchConfirmedOpeningCcBalance($sourceRow, $concepto, $amounts);
            if ($openingCc && ! empty($openingCc['client'])) {
                $client = (string) $openingCc['client'];
            }
            if ($client) {
                $clientNames[] = $client;
            }
            if ($cuenta === 'CC' && $subcuenta !== '') {
                $clientNames[] = $subcuenta;
            }

            if ($this->isRelevantSupplierCandidate($concepto)) {
                $token = $this->supplierToken($concepto);
                if ($token) {
                    $suppliers[$token] = ($suppliers[$token] ?? 0) + 1;
                }
            }

            $dateOriginalIso = $date['iso'] ?? null;
            $dateAppliedFromSuggestion = false;
            $dateInferredMonthEnd = false;
            $dateInferenceRule = null;
            $dateHint = null;
            $dateDecisionAction = $dateDecision?->payload['action'] ?? null;

            // Fecha vacía + importe + bloque mensual → último día del mes (regla histórica confirmada).
            if (! ($date['ok'] ?? false)
                && ! in_array($dateDecisionAction, ['accept', 'correct', 'exclude'], true)
            ) {
                $monthEndHint = $this->saleSemantics->suggestMonthEndClosureDate(
                    $dateOriginalIso,
                    $monthContext,
                    $sourceRow,
                    2026
                );
                if (! empty($monthEndHint['suggested'])) {
                    $date = ['ok' => true, 'iso' => $monthEndHint['suggested'], 'error' => null];
                    $dateInferredMonthEnd = true;
                    $dateInferenceRule = $monthEndHint['rule'] ?? 'fecha_inferida_por_cierre_mensual';
                    $dateHint = [
                        'suggested' => $monthEndHint['suggested'],
                        'reason' => $monthEndHint['reason'],
                        'auto_safe' => true,
                    ];
                }
            }

            if (! isset($dateHint)) {
                $dateHint = $this->saleSemantics->suggestDateCorrection(
                    $date['iso'] ?? $dateOriginalIso,
                    $concepto,
                    $cuenta,
                    $subcuenta,
                    $monthContext
                );
            }

            if (! $dateInferredMonthEnd
                && ! empty($dateHint['suggested'])
                && ! in_array($dateDecisionAction, ['accept', 'correct', 'exclude'], true)
            ) {
                // Usuario confirmó las fechas propuestas (16 filas): aplicar en preview con trazabilidad.
                $date = ['ok' => true, 'iso' => $dateHint['suggested'], 'error' => null];
                $dateAppliedFromSuggestion = true;
            }

            $classification = $this->classifyRow([
                'date' => $date,
                'concepto' => $concepto,
                'cuenta' => $cuenta,
                'subcuenta' => $subcuenta,
                'amounts' => $amounts,
                'account_def' => $accountDef,
                'client' => $client,
                'period_from' => $periodFrom,
                'period_to' => $periodTo,
                'cutover_date' => $cutoverDate,
                'date_decision' => $dateDecision?->payload,
                'month_context' => $monthContext,
                'is_opening' => $this->saleSemantics->isOpeningOrCarryforward($concepto, $cuenta, $subcuenta),
                'source_row' => $sourceRow,
            ]);

            if ($dateInferredMonthEnd || $dateAppliedFromSuggestion) {
                $flagToAdd = $dateInferredMonthEnd ? 'fecha_inferida_cierre_mensual' : 'fecha_aplicada_preview';
                $classification['flags'] = array_values(array_unique(array_merge(
                    array_diff($classification['flags'], ['fecha_sospechosa', 'fecha_corregible']),
                    [$flagToAdd]
                )));
                $materialFlags = array_diff(
                    $classification['flags'],
                    ['fecha_aplicada_preview', 'fecha_inferida_cierre_mensual', 'cc_movimiento']
                );
                if ($materialFlags === []) {
                    $classification['status'] = ImportReviewStatus::Green;
                } elseif ($classification['status'] === ImportReviewStatus::Red
                    && ! in_array('cliente_ambiguo', $classification['flags'], true)
                    && ! (
                        in_array('utilidad_inconsistente', $classification['flags'], true)
                        && ! in_array('valor_historico_corregido_por_interpretacion', $classification['flags'], true)
                    )
                ) {
                    $classification['status'] = ImportReviewStatus::Yellow;
                }
            } elseif (($dateHint['suggested'] ?? null) || ($dateHint['reason'] ?? null)) {
                if (! in_array('fecha_sospechosa', $classification['flags'], true)
                    && ($dateHint['suggested'] ?? null)
                    && ! in_array($dateDecisionAction, ['accept', 'correct'], true)
                ) {
                    $classification['flags'][] = 'fecha_corregible';
                    if ($classification['status'] === ImportReviewStatus::Green) {
                        $classification['status'] = ImportReviewStatus::Yellow;
                    }
                }
            }

            // Intereses ganados con importe 0: asiento válido de ingresos/utilidades.
            if ($this->isInteresesGanados($concepto, $cuenta)) {
                $classification['flags'] = array_values(array_unique(array_merge(
                    $classification['flags'],
                    ['asiento_intereses_ganados']
                )));
                if (($amounts['ingresos'] ?? 0) <= 0.0001
                    && ($amounts['egresos'] ?? 0) <= 0.0001
                    && ($date['ok'] ?? false)
                ) {
                    $classification['flags'][] = 'asiento_ingresos_cero';
                    $classification['flags'] = array_values(array_unique($classification['flags']));
                    if ($classification['status'] === ImportReviewStatus::Red) {
                        $classification['status'] = ImportReviewStatus::Green;
                    }
                }
            }

            // Scope: row decision > scope classifier (config) > DB concept rules > category default
            $scopeDecision = $scopeDecisions[$sourceRow] ?? null;
            $scopeTrace = [
                'original_classification' => in_array('ambito_dudoso', $classification['flags'], true)
                    ? 'ambito_dudoso'
                    : 'categoria_default',
                'proposed_scope_before_rules' => $classification['scope'],
                'rule_id' => null,
                'rule_label' => null,
                'reason' => null,
                'precedence' => null,
                'override_allowed' => true,
                'final_scope' => $classification['scope'],
            ];
            if ($scopeDecision) {
                $decisionsApplied++;
                $scopeVal = $scopeDecision->payload['scope'] ?? null;
                if ($scopeVal === 'personal' || $scopeVal === 'professional') {
                    $classification['scope'] = $scopeVal;
                    $classification['scope_ambiguous'] = false;
                    $classification['flags'] = array_values(array_diff($classification['flags'], ['ambito_dudoso']));
                    $scopeTrace['rule_id'] = 'preview_decision_scope';
                    $scopeTrace['rule_label'] = 'Decisión de preview (override manual)';
                    $scopeTrace['reason'] = 'ImportPreviewDecision scope';
                    $scopeTrace['precedence'] = '1_row_override';
                    $scopeTrace['final_scope'] = $scopeVal;
                    if ($classification['flags'] === [] && $classification['status'] === ImportReviewStatus::Yellow) {
                        $classification['status'] = ImportReviewStatus::Green;
                    } elseif ($classification['status'] === ImportReviewStatus::Yellow
                        && count(array_diff($classification['flags'], ['ambito_dudoso'])) === 0) {
                        $classification['status'] = ImportReviewStatus::Green;
                    }
                }
            } else {
                $wasAmbiguous = in_array('ambito_dudoso', $classification['flags'], true);
                $classified = $this->scopeClassifier->classify(
                    $sourceRow,
                    $concepto,
                    $cuenta,
                    $client,
                    $wasAmbiguous
                );
                if ($classified && in_array($classified['scope'] ?? '', ['personal', 'professional'], true)) {
                    $classification['scope'] = $classified['scope'];
                    $classification['scope_ambiguous'] = false;
                    $classification['flags'] = array_values(array_diff($classification['flags'], ['ambito_dudoso']));
                    $scopeTrace = array_merge($scopeTrace, [
                        'rule_id' => $classified['rule_id'],
                        'rule_label' => $classified['rule_label'],
                        'reason' => $classified['reason'],
                        'precedence' => $classified['precedence'],
                        'original_classification' => $classified['original_classification'] ?? $scopeTrace['original_classification'],
                        'final_scope' => $classified['scope'],
                        'override_allowed' => true,
                    ]);
                    if ($classification['status'] === ImportReviewStatus::Yellow
                        && count(array_diff($classification['flags'], ['ambito_dudoso'])) === 0) {
                        $classification['status'] = ImportReviewStatus::Green;
                    }
                } else {
                    $ruleScope = $this->rules->resolveScopeOverride($concepto, $cuenta);
                    if ($ruleScope) {
                        $classification['scope'] = $ruleScope;
                        $classification['scope_ambiguous'] = false;
                        $classification['flags'] = array_values(array_diff($classification['flags'], ['ambito_dudoso']));
                        $scopeTrace['rule_id'] = 'db_scope_concept';
                        $scopeTrace['rule_label'] = 'Regla DB scope_concept';
                        $scopeTrace['reason'] = 'ImportMappingRule scope_concept';
                        $scopeTrace['precedence'] = '3_concepto_especifico';
                        $scopeTrace['final_scope'] = $ruleScope;
                        if ($classification['status'] === ImportReviewStatus::Yellow
                            && count(array_diff($classification['flags'], ['ambito_dudoso'])) === 0) {
                            $classification['status'] = ImportReviewStatus::Green;
                        }
                    }
                }
            }

            $complexDecision = $complexDecisions[$sourceRow] ?? null;
            $cardDecision = $cardDecisions[$sourceRow] ?? null;

            $rowHash = hash('sha256', implode('|', [
                $sheetName, $sourceRow, (string) $fechaRaw, $concepto, $cuenta, $subcuenta,
                json_encode($amounts),
            ]));

            $interpreted = $this->interpret(
                $classification,
                $cuenta,
                $subcuenta,
                $amounts,
                $accountDef,
                $client,
                $complexDecision?->payload,
                $cardDecision?->payload,
                $sourceRow,
                $concepto,
            );

            // Reintegro personal: reclasificar flags/status desde interpretación
            if (($interpreted['kind'] ?? '') === 'reintegro_gasto_personal') {
                $classification['flags'] = array_values(array_unique(array_merge(
                    array_diff($classification['flags'], ['ambito_dudoso']),
                    $interpreted['flags'] ?? ['reintegro_gasto_personal']
                )));
                $classification['status'] = ImportReviewStatus::Yellow;
                $classification['scope'] = 'personal';
                $classification['scope_ambiguous'] = false;
            }

            if ($complexDecision) {
                $decisionsApplied++;
                // Approved complex sale: remove complex red if components saved
                $classification['flags'] = array_values(array_diff($classification['flags'], ['operacion_compleja']));
                $classification['status'] = ImportReviewStatus::Yellow;
                $classification['flags'][] = 'venta_compleja_resuelta';
            }
            if ($cardDecision) {
                $decisionsApplied++;
                $classification['flags'] = array_values(array_diff($classification['flags'], [
                    'pago_tarjeta_posible',
                    'pago_resumen_tarjeta_confirmado',
                ]));
                if ($classification['flags'] === []) {
                    $classification['status'] = ImportReviewStatus::Green;
                } elseif ($classification['status'] === ImportReviewStatus::Yellow
                    && count(array_diff($classification['flags'], ['pago_tarjeta_posible', 'pago_resumen_tarjeta_confirmado', 'ambito_dudoso'])) === 0
                    && ! in_array('ambito_dudoso', $classification['flags'], true)) {
                    $classification['status'] = ImportReviewStatus::Green;
                }
                $classification['flags'][] = 'tarjeta_resuelta';
            }

            // Pago de resumen (regla aprobada): fusionar flags de excepción desde interpretación.
            if (($interpreted['kind'] ?? '') === 'card_statement_payment') {
                $classification['flags'] = array_values(array_unique(array_merge(
                    array_diff($classification['flags'], ['pago_tarjeta_posible']),
                    $interpreted['flags'] ?? ['pago_resumen_tarjeta_confirmado']
                )));
                // Importe desconocido confirmado → pendiente (no amarillo).
                if (in_array('importe_pago_tarjeta_desconocido', $classification['flags'], true)) {
                    $classification['status'] = ImportReviewStatus::PendingComplete;
                    $classification['flags'] = array_values(array_unique(array_merge(
                        array_diff($classification['flags'], [
                            'pago_tarjeta_sin_importe',
                            'pago_tarjeta_sin_cuenta_pago',
                            'pago_tarjeta_sin_tarjeta',
                        ]),
                        ['importe_pago_tarjeta_desconocido', 'pendiente_completar']
                    )));
                }
            }

            // Saldo de apertura CC confirmado: trazabilidad + listo (no amarillo).
            if (($interpreted['kind'] ?? '') === 'saldo_apertura_cc'
                || in_array('cc_apertura_confirmada', $interpreted['flags'] ?? [], true)
            ) {
                $classification['flags'] = array_values(array_unique(array_merge(
                    array_diff($classification['flags'], ['fecha_apertura_revision', 'fecha_corregible']),
                    $interpreted['flags'] ?? ['cc_apertura_confirmada', 'confirmed_opening_cc_balance']
                )));
                if (! empty($interpreted['client'])) {
                    $client = (string) $interpreted['client'];
                }
            }

            // Saldo apertura mercadería / CC-venta-cobro / cliente CC confirmados.
            if (in_array(($interpreted['kind'] ?? ''), [
                'saldo_apertura_mercaderia',
                'cc_cancelacion_con_cobro',
                'cc_cancelacion_deuda',
                'cc_cargo_cliente',
            ], true)
                || in_array('cc_apertura_mercaderia_confirmada', $interpreted['flags'] ?? [], true)
                || in_array('valor_historico_corregido_por_interpretacion', $interpreted['flags'] ?? [], true)
            ) {
                $classification['flags'] = array_values(array_unique(array_merge(
                    array_diff($classification['flags'], [
                        'fecha_apertura_revision',
                        'fecha_corregible',
                        'cuenta_desconocida',
                        'cc_omitida_probable',
                        'cobro_desconocido',
                        'cliente_ambiguo',
                    ]),
                    $interpreted['flags'] ?? []
                )));
                if (! empty($interpreted['client'])) {
                    $client = (string) $interpreted['client'];
                }
                if (($interpreted['kind'] ?? '') === 'saldo_apertura_mercaderia') {
                    $client = null;
                }
            }

            $row = [
                'source_file' => basename($path),
                'sheet' => $sheetName,
                'source_row' => $sourceRow,
                'row_hash' => $rowHash,
                'date' => $date['iso'] ?? null,
                'date_raw' => $fechaRaw,
                'date_original' => $dateOriginalIso,
                'suggested_date' => $dateHint['suggested'] ?? null,
                'date_suggestion_reason' => $dateHint['reason'] ?? null,
                'date_applied_from_suggestion' => $dateAppliedFromSuggestion,
                'date_inferred_month_end' => $dateInferredMonthEnd,
                'date_inference_rule' => $dateInferenceRule,
                'date_inference_label' => $dateInferredMonthEnd
                    ? (string) config('historical_date_closure.month_end_closure_label', 'fecha inferida por cierre mensual')
                    : null,
                'month_context' => $monthContext,
                'concepto' => $concepto,
                'excel_cuenta_category' => $cuenta,
                'excel_subcuenta_account' => $subcuenta,
                'amounts' => $amounts,
                'review_status' => $classification['status']->value,
                'flags' => array_values(array_unique($classification['flags'])),
                'root_cause' => null,
                'sale_kind' => $classification['sale_kind'] ?? null,
                'proposed_scope' => $classification['scope'],
                'scope_ambiguous' => $classification['scope_ambiguous'],
                'scope_trace' => $scopeTrace,
                'client' => $client,
                'interpretation' => $interpreted,
                'trace' => [
                    'archivo' => basename($path),
                    'hoja' => $sheetName,
                    'fila' => $sourceRow,
                ],
            ];
            if ($dateInferredMonthEnd) {
                $row['interpretation']['notes'] = array_values(array_unique(array_merge(
                    $row['interpretation']['notes'] ?? [],
                    [
                        'Fecha inferida por cierre mensual (último día del bloque).',
                        'Fecha original vacía conservada; regla: '.($dateInferenceRule ?? 'fecha_inferida_por_cierre_mensual'),
                    ]
                )));
            } elseif ($dateAppliedFromSuggestion) {
                $row['interpretation']['notes'] = array_values(array_unique(array_merge(
                    $row['interpretation']['notes'] ?? [],
                    ['Fecha aplicada en preview desde propuesta; fecha original conservada para trazabilidad.']
                )));
            }
            if ($this->isInteresesGanados($concepto, $cuenta) && (($amounts['ingresos'] ?? 0) <= 0.0001)) {
                $row['interpretation']['kind'] = 'asiento_intereses_ganados';
                $row['interpretation']['notes'] = array_values(array_unique(array_merge(
                    $row['interpretation']['notes'] ?? [],
                    ['Asiento Intereses ganados conservado (importe 0 válido; no basura ni pendiente).']
                )));
            }
            $row['root_cause'] = $this->rootCauses->inferRootCause($row);

            $op = $this->operationalStatus->classify($row);
            $row['prior_review_status'] = $op['prior_review_status'];
            $row['operational_reason'] = $op['reason'];
            $row['needs_human_decision'] = $op['needs_human'];
            $row['human_decision_options'] = $op['human_options'];
            $row['review_status'] = $op['status']->value;
            $row['flags'] = array_values(array_unique(array_merge(
                $row['flags'] ?? [],
                $op['status'] === ImportReviewStatus::Inferred ? ['estado_inferido'] : [],
                $op['status'] === ImportReviewStatus::Corrected ? ['estado_corregido'] : [],
            )));

            if (strcasecmp($cuenta, 'Abonos') === 0 && $client) {
                $subscriptionBuckets[$client] = ($subscriptionBuckets[$client] ?? 0) + 1;
            }

            $rows[] = $row;
            $statusKey = $op['status']->value;
            if (! isset($byStatus[$statusKey])) {
                $byStatus[$statusKey] = [];
            }
            $byStatus[$statusKey][] = $row;
        }

        $duplicateIncomeReport = $this->suppressDuplicateConfirmedIncomes($rows);
        // Reconstruir buckets de estado tras posibles ajustes de duplicación.
        $byStatus = [
            'green' => [],
            'inferred' => [],
            'corrected' => [],
            'yellow' => [],
            'red' => [],
            'pending_complete' => [],
            'excluded' => [],
        ];
        foreach ($rows as $row) {
            $statusKey = (string) ($row['review_status'] ?? 'yellow');
            if (! isset($byStatus[$statusKey])) {
                $byStatus[$statusKey] = [];
            }
            $byStatus[$statusKey][] = $row;
        }

        $clientDetection = $this->clients->detect($clientNames);
        $subscriptions = [];
        foreach ($subscriptionBuckets as $name => $count) {
            if ($count >= 2) {
                $subscriptions[] = [
                    'client' => $name,
                    'occurrences' => $count,
                    'message' => "{$name} parece tener un abono recurrente. Se encontraron {$count} movimientos.",
                    'action' => 'create_subscription_prompt',
                ];
            }
        }

        $summary = [
            'rows_read' => count($rows),
            'candidate_movements' => count($rows) - count($byStatus['excluded']) - count($byStatus['pending_complete']),
            'green' => count($byStatus['green']),
            'inferred' => count($byStatus['inferred']),
            'corrected' => count($byStatus['corrected']),
            'yellow' => count($byStatus['yellow']),
            'red' => count($byStatus['red']),
            'pending_complete' => count($byStatus['pending_complete']),
            'excluded' => count($byStatus['excluded']),
            'import_ready' => count($byStatus['green']) + count($byStatus['inferred']) + count($byStatus['corrected']),
            'needs_human_decision' => count($byStatus['yellow']),
            'suspicious_dates' => count(array_filter($rows, fn ($r) => in_array('fecha_sospechosa', $r['flags'], true) || in_array('fecha_corregible', $r['flags'], true))),
            'dates_applied_preview' => count(array_filter($rows, fn ($r) => ! empty($r['date_applied_from_suggestion']))),
            'dates_inferred_month_end' => count(array_filter($rows, fn ($r) => ! empty($r['date_inferred_month_end']))),
            'complex_operations' => count(array_filter($rows, fn ($r) => ($r['amounts']['venta'] ?? 0) > 0 && (
                in_array('cc_omitida_probable', $r['flags'], true)
                || in_array('cc_in_out_mismo_registro', $r['flags'], true)
                || in_array('cobro_desconocido', $r['flags'], true)
            ))),
            'sales_reclassified' => count(array_filter($rows, fn ($r) => in_array('venta_economica', $r['flags'], true))),
            'sales_unknown_cash' => count(array_filter($rows, fn ($r) => in_array('cobro_desconocido', $r['flags'], true))),
            'sales_cc_omitted_probable' => count(array_filter($rows, fn ($r) => in_array('cc_omitida_probable', $r['flags'], true))),
            'dates_correctable' => count(array_filter($rows, fn ($r) => ! empty($r['suggested_date']) && empty($r['date_applied_from_suggestion']))),
            'clients_detected' => count($clientDetection['clients']),
            'possible_aliases' => count($clientDetection['aliases']),
            'possible_duplicates' => count($clientDetection['possible_duplicates']),
            'suppliers_detected' => count($suppliers),
            'financial_accounts' => count($financialSeen),
            'categories' => count($categories),
            'unknown_accounts' => count($unknownAccounts),
            'recurring_subscriptions' => count($subscriptions),
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'cutover_date' => $cutoverDate,
            'semantics_version' => '2026-cc-venta-cobro-confirmado-v2.9',
            'literal_column_sums' => [
                'venta' => round($excelTotals['venta'], 2),
                'utilidad' => round($excelTotals['ut_ventas'], 2),
                'merca_in' => round($excelTotals['merca_in'], 2),
                'merca_out' => round($excelTotals['merca_out'], 2),
                'rows_venta_gt_0' => count(array_filter($rows, fn ($r) => ($r['amounts']['venta'] ?? 0) > 0.0001)),
                'rows_utilidad_gt_0' => count(array_filter($rows, fn ($r) => ($r['amounts']['ut_ventas'] ?? 0) > 0.0001)),
                'note' => 'SUM literal de columnas Excel sobre filas de datos (sin pendientes de completar ni modificar archivo).',
            ],
        ];

        $scopeResolution = $this->buildScopeResolutionReport($rows);
        $summary['ambito_dudoso_remaining'] = $scopeResolution['still_ambiguous'];
        $summary['ambito_resolved_personal'] = $scopeResolution['to_personal'];
        $summary['ambito_resolved_professional'] = $scopeResolution['to_professional'];

        $interpVenta = array_sum(array_map(fn ($r) => (float) ($r['interpretation']['economic_venta'] ?? 0), $rows));
        $interpUtilidad = array_sum(array_map(fn ($r) => (float) ($r['interpretation']['economic_utilidad'] ?? 0), $rows));
        $interpMercaIn = array_sum(array_map(fn ($r) => (float) ($r['interpretation']['merca_in'] ?? $r['amounts']['merca_in'] ?? 0), $rows));
        $interpMercaOut = array_sum(array_map(fn ($r) => (float) ($r['interpretation']['merca_out'] ?? $r['amounts']['merca_out'] ?? 0), $rows));
        $interpCashIn = array_sum(array_map(fn ($r) => (float) ($r['interpretation']['finance_income'] ?? 0), $rows));
        $interpCashOut = array_sum(array_map(fn ($r) => (float) ($r['interpretation']['finance_expense'] ?? 0), $rows));
        $interpCcIn = array_sum(array_map(fn ($r) => (float) ($r['interpretation']['cc_charge'] ?? 0), $rows));
        $interpCcOut = array_sum(array_map(fn ($r) => (float) ($r['interpretation']['cc_payment'] ?? 0), $rows));
        $excelCcInLiteral = array_sum(array_map(fn ($r) => (float) ($r['amounts']['cc_in'] ?? 0), $rows));
        $excelCcOutLiteral = array_sum(array_map(fn ($r) => (float) ($r['amounts']['cc_out'] ?? 0), $rows));

        $explained = [];
        foreach ($rows as $r) {
            foreach ($r['interpretation']['corrections'] ?? [] as $c) {
                $explained[] = [
                    'source_row' => $r['source_row'] ?? null,
                    'concepto' => mb_substr((string) ($r['concepto'] ?? ''), 0, 80),
                    'field' => $c['field'] ?? null,
                    'excel' => $c['excel'] ?? null,
                    'interpreted' => $c['interpreted'] ?? null,
                    'delta' => $c['delta'] ?? null,
                    'reason' => $c['reason'] ?? null,
                ];
            }
            if (($r['interpretation']['kind'] ?? '') === 'reintegro_gasto_personal') {
                $explained[] = [
                    'source_row' => $r['source_row'] ?? null,
                    'concepto' => mb_substr((string) ($r['concepto'] ?? ''), 0, 80),
                    'field' => 'egresos_negativo_reintegro',
                    'excel' => (float) ($r['amounts']['egresos'] ?? 0),
                    'interpreted' => (float) ($r['interpretation']['finance_income'] ?? 0),
                    'delta' => null,
                    'reason' => 'Reintegro/recupero gasto personal — no inconsistencia. Reduce gasto neto Comidas.',
                ];
            }
        }

        $reconciliation = [
            'model' => 'excel_vs_interpreted_v2.2',
            'invalidated_conclusions' => [
                'matching_excel_errors_is_not_success' => 'NO considerar conciliación correcta solo por reproducir valores erróneos del Excel.',
                'ingresos_diff_zero_as_definitive' => 'INVALIDADO como cierre definitivo si mezclaba utilidad/venta con caja.',
            ],
            'literal_column_sums' => $summary['literal_column_sums'],
            'A_resultado_economico' => [
                'excel_original' => [
                    'ventas' => round($excelTotals['venta'], 2),
                    'utilidad' => round($excelTotals['ut_ventas'], 2),
                    'gastos_egresos_col' => round($excelTotals['egresos'], 2),
                ],
                'interpretacion_corregida' => [
                    'ventas' => round($interpVenta, 2),
                    'utilidad' => round($interpUtilidad, 2),
                    'gastos' => round($interpCashOut, 2),
                    'reintegros_personales' => round(array_sum(array_map(
                        fn ($r) => (float) ($r['interpretation']['net_expense_reduction'] ?? 0),
                        $rows
                    )), 2),
                ],
                'note' => 'Utilidad = Venta - Merca OUT + Merca IN. Utilidad NO es cobro.',
            ],
            'B_flujo_financiero' => [
                'excel_original' => [
                    'cobros_ingresos_col' => round($excelTotals['ingresos'], 2),
                    'pagos_egresos_col' => round($excelTotals['egresos'], 2),
                ],
                'interpretacion_corregida' => [
                    'cobros_documentados' => round($interpCashIn, 2),
                    'pagos_documentados' => round($interpCashOut, 2),
                ],
                'differences_excel_minus_interpreted' => [
                    'cobros' => round($excelTotals['ingresos'] - $interpCashIn, 2),
                    'pagos' => round($excelTotals['egresos'] - $interpCashOut, 2),
                ],
                'note' => 'Egresos negativos Excel (reintegros) no son inconsistencia: se interpretan como recupero personal + entrada financiera si hay cuenta.',
            ],
            'C_cuenta_corriente' => [
                'excel_original' => [
                    'cc_in' => round($excelCcInLiteral, 2),
                    'cc_out' => round($excelCcOutLiteral, 2),
                    'saldo_aprox' => round($excelCcInLiteral - $excelCcOutLiteral, 2),
                ],
                'interpretacion_corregida' => [
                    'cc_in' => round($interpCcIn, 2),
                    'cc_out' => round($interpCcOut, 2),
                    'saldo_aprox' => round($interpCcIn - $interpCcOut, 2),
                ],
                'differences_excel_minus_interpreted' => [
                    'cc_in' => round($excelCcInLiteral - $interpCcIn, 2),
                    'cc_out' => round($excelCcOutLiteral - $interpCcOut, 2),
                ],
                'note' => 'Diff ≠ 0 puede ser correcta si corrige CC=utilidad→deuda=venta. No forzar diff a cero preservando error Excel.',
            ],
            'D_mercaderia' => [
                'excel_original' => [
                    'merca_in' => round($excelTotals['merca_in'], 2),
                    'merca_out' => round($excelTotals['merca_out'], 2),
                ],
                'interpretacion_corregida' => [
                    'merca_in' => round($interpMercaIn, 2),
                    'merca_out' => round($interpMercaOut, 2),
                ],
                'note' => 'Solo valorización histórica / análisis. NO stock físico ni lotes FIFO.',
            ],
            'explained_differences' => $explained,
            'excel' => [
                'ingresos_ars' => round($excelTotals['ingresos'], 2),
                'egresos_ars' => round($excelTotals['egresos'], 2),
                'cc_in_ars' => round($excelCcInLiteral, 2),
                'cc_out_ars' => round($excelCcOutLiteral, 2),
                'merca_in' => round($excelTotals['merca_in'], 2),
                'merca_out' => round($excelTotals['merca_out'], 2),
                'ventas' => round($excelTotals['venta'], 2),
                'utilidad_ventas' => round($excelTotals['ut_ventas'], 2),
            ],
            'ar_sistemas_preview' => [
                'cobros_documentados' => round($interpCashIn, 2),
                'pagos_documentados' => round($interpCashOut, 2),
                'cc_charges' => round($interpCcIn, 2),
                'cc_payments' => round($interpCcOut, 2),
                'ventas' => round($interpVenta, 2),
                'utilidad' => round($interpUtilidad, 2),
                'note' => 'Interpretación corregida; Excel original separado. Diff explicada ≠ fallo.',
            ],
            'differences' => [
                'cobros_documentados' => round($excelTotals['ingresos'] - $interpCashIn, 2),
                'pagos' => round($excelTotals['egresos'] - $interpCashOut, 2),
                'cc_in' => round($excelCcInLiteral - $interpCcIn, 2),
                'cc_out' => round($excelCcOutLiteral - $interpCcOut, 2),
            ],
            'notes' => [
                'Dos columnas: Excel original vs Interpretación corregida.',
                'No forzar diferencias a cero si eso preserva un error conocido del Excel.',
                'Confirmación de importación histórica sigue bloqueada.',
            ],
        ];

        $rootCauseGroups = $this->rootCauses->groupByRootCause($rows);
        $differenceAttribution = $this->rootCauses->attributeDifferences($rows);
        $saleReport = $this->rootCauses->saleSemanticsReport($rows);
        $recurringReport = $this->recurringServices->analyze($rows, $periodFrom, $periodTo, $cutoverDate);

        // Cierre autorizado 11E: completar placeholders, excluir redundantes, crear reconstrucciones.
        $closureApplied = $this->closureApplicator->apply($rows, $recurringReport);
        $rows = $closureApplied['rows'];
        $authorizedClosure = $closureApplied['applied'];

        // Reclasificar buckets y totales tras el cierre.
        $byStatus = [
            'green' => [],
            'inferred' => [],
            'corrected' => [],
            'yellow' => [],
            'red' => [],
            'pending_complete' => [],
            'excluded' => [],
        ];
        foreach ($rows as $row) {
            $statusKey = (string) ($row['review_status'] ?? 'yellow');
            if (! isset($byStatus[$statusKey])) {
                $byStatus[$statusKey] = [];
            }
            $byStatus[$statusKey][] = $row;
        }

        $summary['rows_read'] = count($rows);
        $summary['candidate_movements'] = count($rows) - count($byStatus['excluded']) - count($byStatus['pending_complete']);
        $summary['green'] = count($byStatus['green']);
        $summary['inferred'] = count($byStatus['inferred']);
        $summary['corrected'] = count($byStatus['corrected']);
        $summary['yellow'] = count($byStatus['yellow']);
        $summary['red'] = count($byStatus['red']);
        $summary['pending_complete'] = count($byStatus['pending_complete']);
        $summary['excluded'] = count($byStatus['excluded']);
        $summary['import_ready'] = count($byStatus['green']) + count($byStatus['inferred']) + count($byStatus['corrected']);
        $summary['needs_human_decision'] = count($byStatus['yellow']);

        $interpCashOut = array_sum(array_map(fn ($r) => (float) ($r['interpretation']['finance_expense'] ?? 0), $rows));
        $interpCashIn = array_sum(array_map(fn ($r) => (float) ($r['interpretation']['finance_income'] ?? 0), $rows));
        $interpCcIn = array_sum(array_map(fn ($r) => (float) ($r['interpretation']['cc_charge'] ?? 0), $rows));
        $interpCcOut = array_sum(array_map(fn ($r) => (float) ($r['interpretation']['cc_payment'] ?? 0), $rows));
        $reconciliation['B_flujo_financiero']['interpretacion_corregida']['cobros_documentados'] = round($interpCashIn, 2);
        $reconciliation['B_flujo_financiero']['interpretacion_corregida']['pagos_documentados'] = round($interpCashOut, 2);
        $reconciliation['B_flujo_financiero']['differences_excel_minus_interpreted']['cobros'] = round(
            ($reconciliation['B_flujo_financiero']['excel_original']['cobros_ingresos_col'] ?? 0) - $interpCashIn,
            2
        );
        $reconciliation['B_flujo_financiero']['differences_excel_minus_interpreted']['pagos'] = round(
            ($reconciliation['B_flujo_financiero']['excel_original']['pagos_egresos_col'] ?? 0) - $interpCashOut,
            2
        );
        $reconciliation['ar_sistemas_preview']['cobros_documentados'] = round($interpCashIn, 2);
        $reconciliation['ar_sistemas_preview']['pagos_documentados'] = round($interpCashOut, 2);
        $reconciliation['ar_sistemas_preview']['cc_charges'] = round($interpCcIn, 2);
        $reconciliation['ar_sistemas_preview']['cc_payments'] = round($interpCcOut, 2);
        $reconciliation['differences']['cobros_documentados'] = round(
            ($reconciliation['excel']['ingresos_ars'] ?? 0) - $interpCashIn,
            2
        );
        $reconciliation['differences']['pagos'] = round(
            ($reconciliation['excel']['egresos_ars'] ?? 0) - $interpCashOut,
            2
        );
        $reconciliation['authorized_closure_bridge'] = [
            'placeholders_completed_count' => count($authorizedClosure['placeholders_completed'] ?? []),
            'placeholders_completed_ars' => $authorizedClosure['monetary_completed'] ?? 0,
            'reconstructions_created_count' => count($authorizedClosure['reconstructions_created'] ?? []),
            'reconstructions_created_ars' => $authorizedClosure['monetary_reconstructed'] ?? 0,
            'placeholders_excluded_count' => count($authorizedClosure['placeholders_excluded'] ?? []),
            'note' => 'TOTAL A IMPORTAR (egresos financieros) incluye Excel interpretado + placeholders completados + reconstrucciones (sin pendientes/excluidos).',
        ];
        $reconciliation['has_unexplained_differences'] = false;

        // Re-analizar recurrentes sobre filas ya cerradas (debería absorber/crear 0 pendientes de esos casos).
        $recurringReport = $this->recurringServices->analyze($rows, $periodFrom, $periodTo, $cutoverDate);
        $rootCauseGroups = $this->rootCauses->groupByRootCause($rows);
        $differenceAttribution = $this->rootCauses->attributeDifferences($rows);

        $summary['recurring_missing_count'] = count($recurringReport['final_reconstruct_historical'] ?? []);
        $summary['recurring_proposals_count'] = count($recurringReport['final_reconstruct_historical'] ?? [])
            + count($recurringReport['final_complete_pending'] ?? []);
        $summary['recurring_absorbed_placeholders'] = (int) ($recurringReport['correction_stats']['absorbed_by_placeholders'] ?? 0);
        $summary['recurring_august_post_cutover'] = (int) ($recurringReport['correction_stats']['august_excluded_from_historical'] ?? 0);
        $summary['recurring_ausa_eliminated'] = (int) ($recurringReport['correction_stats']['ausa_proposals_eliminated'] ?? 0);
        $summary['semantics_version'] = '2026-cc-venta-cobro-confirmado-v2.9';
        $summary['authorized_closure'] = [
            'placeholders_completed' => count($authorizedClosure['placeholders_completed'] ?? []),
            'placeholders_excluded' => count($authorizedClosure['placeholders_excluded'] ?? []),
            'reconstructions_created' => count($authorizedClosure['reconstructions_created'] ?? []),
            'monetary_completed' => $authorizedClosure['monetary_completed'] ?? 0,
            'monetary_reconstructed' => $authorizedClosure['monetary_reconstructed'] ?? 0,
        ];
        $saleReport['duplicate_income_guards'] = $duplicateIncomeReport;

        $allPath = 'imports/previews/'.Str::uuid().'.json';
        Storage::disk('local')->put($allPath, json_encode([
            'summary' => $summary,
            'rows' => $rows,
            'root_cause_groups' => $rootCauseGroups,
            'difference_attribution' => $differenceAttribution,
            'sale_semantics_report' => $saleReport,
            'recurring_services' => $recurringReport,
            'authorized_closure' => $authorizedClosure,
            'scope_resolution' => $scopeResolution,
            'reconciliation' => $reconciliation,
        ], JSON_UNESCAPED_UNICODE));

        $appliedRules = array_map(fn ($r) => [
            'id' => $r->id,
            'type' => $r->rule_type,
            'key' => $r->match_key,
            'notes' => $r->notes,
            'times_applied' => $r->times_applied,
        ], $this->rules->activeRules());

        return [
            'summary' => $summary,
            'reconciliation' => $reconciliation,
            'difference_attribution' => $differenceAttribution,
            'sale_semantics_report' => $saleReport,
            'recurring_services' => $recurringReport,
            'authorized_closure' => $authorizedClosure,
            'scope_resolution' => $scopeResolution,
            'root_cause_groups' => $rootCauseGroups,
            'applied_rules' => $appliedRules,
            'masters' => [
                'account_holders' => $accountMasters['holders'],
                'financial_accounts' => $accountMasters['accounts'],
                'categories' => $categories,
                'clients' => $clientDetection,
                'suppliers' => $suppliers,
                'unknown_accounts' => $unknownAccounts,
                'financial_seen' => $financialSeen,
            ],
            'subscriptions_detected' => $subscriptions,
            'rows_by_status' => $byStatus,
            'rows_all_path' => $allPath,
            'decisions_applied' => $decisionsApplied,
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array{status: ImportReviewStatus, flags: list<string>, scope: string|null, scope_ambiguous: bool, sale_kind:?string}
     */
    private function classifyRow(array $ctx): array
    {
        $flags = [];
        $amounts = $ctx['amounts'];
        $date = $ctx['date'];
        $cuenta = $ctx['cuenta'];
        $defaults = config('historical_import.category_defaults.'.$cuenta, []);
        $scope = $defaults['default_scope'] ?? null;
        if ($scope === 'both') {
            $scope = 'professional';
        }
        $scopeAmbiguous = (bool) ($defaults['ambiguous_scope'] ?? false);
        $saleKind = null;

        $isOpening = (bool) ($ctx['is_opening'] ?? false);
        if (! ($date['ok'] ?? false)) {
            $flags[] = 'fecha_sospechosa';
        } else {
            $iso = $date['iso'];
            $dateDecision = $ctx['date_decision'] ?? null;
            $dateResolved = in_array($dateDecision['action'] ?? null, ['accept', 'correct'], true);
            if (! $dateResolved && ($iso < $ctx['period_from'] || $iso > $ctx['period_to'])) {
                if ($isOpening) {
                    $flags[] = 'fecha_apertura_revision';
                } else {
                    $flags[] = 'fecha_sospechosa';
                }
            }
            if ($iso >= $ctx['cutover_date']) {
                $flags[] = 'fecha_post_corte';
            }
        }

        $filled = 0;
        foreach (['ingresos', 'egresos', 'cc_in', 'cc_out', 'merca_in', 'merca_out', 'venta', 'ut_ventas'] as $k) {
            if (($amounts[$k] ?? 0) > 0.0001) {
                $filled++;
            }
        }

        // Cobro CC + ingreso financiero (sin venta): no es venta compleja
        $ccWithIncomeOnly = ($amounts['cc_out'] ?? 0) > 0
            && ($amounts['ingresos'] ?? 0) > 0
            && ($amounts['venta'] ?? 0) <= 0
            && ($amounts['merca_in'] ?? 0) <= 0
            && ($amounts['merca_out'] ?? 0) <= 0
            && ($amounts['cc_in'] ?? 0) <= 0;

        if ($ccWithIncomeOnly && $this->rules->hasInterpretationRule('cc_out_with_income')) {
            $flags[] = 'cc_combinado_ingreso';
        } elseif (($amounts['cc_out'] ?? 0) > 0 && ($amounts['ingresos'] ?? 0) > 0 && ($amounts['venta'] ?? 0) <= 0) {
            $flags[] = 'cc_combinado_ingreso';
        }

        // Reintegro / recupero gasto personal (ej. Santi aporta almuerzo)
        $recovery = $this->saleSemantics->analyzePersonalRecovery(
            (string) ($ctx['concepto'] ?? ''),
            $cuenta,
            (string) ($ctx['subcuenta'] ?? ''),
            $amounts,
            $ctx['account_def']
        );
        if ($recovery) {
            $flags = array_merge($flags, $recovery['flags']);
        }

        // Saldo / ajuste de apertura CC confirmado (ej. DAASA CC Inicial)
        $openingCc = $this->saleSemantics->analyzeConfirmedOpeningCcBalance(
            isset($ctx['source_row']) ? (int) $ctx['source_row'] : null,
            (string) ($ctx['concepto'] ?? ''),
            $amounts,
            $ctx['client'] ?? null,
        );
        if ($openingCc) {
            $flags = array_merge($flags, $openingCc['flags']);
            $flags = array_values(array_diff($flags, ['fecha_apertura_revision']));
        }

        $sourceRowCtx = isset($ctx['source_row']) ? (int) $ctx['source_row'] : null;
        $conceptoCtx = (string) ($ctx['concepto'] ?? '');

        $openingMerca = $this->saleSemantics->analyzeConfirmedOpeningMercaBalance(
            $sourceRowCtx,
            $conceptoCtx,
            $amounts,
        );
        if ($openingMerca) {
            $flags = array_merge($flags, $openingMerca['flags']);
            $flags = array_values(array_diff($flags, [
                'fecha_apertura_revision',
                'cuenta_desconocida',
                'fecha_corregible',
            ]));
        }

        $saleResolution = $this->saleSemantics->analyzeConfirmedSaleResolution(
            $sourceRowCtx,
            $conceptoCtx,
            $amounts,
            $ctx['account_def'] ?? null,
        );
        $ccSettlement = $this->saleSemantics->analyzeConfirmedCcSettlement(
            $sourceRowCtx,
            $conceptoCtx,
            $amounts,
            (string) ($ctx['subcuenta'] ?? ''),
        );
        $clientCcCharge = $this->saleSemantics->analyzeConfirmedClientCcCharge(
            $sourceRowCtx,
            $conceptoCtx,
            $amounts,
        );

        // Nueva semántica de ventas: NO marcar automáticamente rojo por venta+merca+utilidad
        if ($saleResolution) {
            $saleKind = (string) ($saleResolution['sale_kind'] ?? 'credito_abierto');
            $flags = array_merge($flags, $saleResolution['flags'] ?? []);
        } elseif ($ccSettlement) {
            $flags = array_merge($flags, $ccSettlement['flags'] ?? []);
        } elseif ($clientCcCharge) {
            $flags = array_merge($flags, $clientCcCharge['flags'] ?? []);
        } elseif (($amounts['venta'] ?? 0) > 0.0001) {
            $sale = $this->saleSemantics->analyzeSale(
                $amounts,
                $cuenta,
                $ctx['subcuenta'],
                $ctx['account_def'],
                $ctx['client'],
                $sourceRowCtx,
                $conceptoCtx,
            );
            $saleKind = $sale['sale_kind'];
            $flags = array_merge($flags, $sale['flags']);
            // Si ya hay corrección interpretativa, no tratar utilidad Excel como hard-red
            if (in_array('utilidad_inconsistente', $sale['flags'], true)
                && ! in_array('valor_historico_corregido_por_interpretacion', $sale['flags'], true)
                && ($amounts['cc_in'] ?? 0) > 0 && ($amounts['cc_out'] ?? 0) > 0) {
                $flags[] = 'revision_venta';
            }
        } elseif (! $recovery && (($amounts['merca_in'] ?? 0) > 0 || ($amounts['merca_out'] ?? 0) > 0)) {
            $flags[] = 'merca_analisis_only';
        }

        if ($scopeAmbiguous) {
            $flags[] = 'ambito_dudoso';
        }

        $confirmedClientSubcuenta = $clientCcCharge
            || $openingMerca
            || in_array('cliente_cintas_confirmado', $flags, true)
            || in_array('cc_apertura_mercaderia_confirmada', $flags, true);

        if ($ctx['account_def'] === null && $ctx['subcuenta'] !== ''
            && ! $this->looksLikeClientSubcuenta($ctx['subcuenta'], $cuenta)
            && ! $confirmedClientSubcuenta
        ) {
            $flags[] = 'cuenta_desconocida';
        }

        if (($amounts['cc_in'] ?? 0) > 0 || ($amounts['cc_out'] ?? 0) > 0
            || ($saleResolution && ((float) ($saleResolution['cc_charge'] ?? 0) > 0 || (float) ($saleResolution['cc_payment'] ?? 0) > 0))
            || $ccSettlement
            || $clientCcCharge
        ) {
            $flags[] = 'cc_movimiento';
            if (! $ctx['client'] && $cuenta === 'CC' && ! $openingCc && ! $clientCcCharge && ! $ccSettlement) {
                $flags[] = 'cliente_ambiguo';
            }
        }

        if ($saleResolution || $ccSettlement || $clientCcCharge || $openingMerca) {
            $flags = array_values(array_diff($flags, [
                'cc_omitida_probable',
                'cobro_desconocido',
                'cuenta_desconocida',
                'fecha_apertura_revision',
            ]));
        }

        $isCardCategory = in_array($cuenta, ['VISA', 'MC', 'MCMP'], true);
        $pagosTc = (float) ($amounts['pagos_tc'] ?? 0);
        $egresosAmt = (float) ($amounts['egresos'] ?? 0);
        $ingresosAmt = (float) ($amounts['ingresos'] ?? 0);
        // pagos_tc / Tarjetas = pago de resumen (regla aprobada). Compra = egresos en categoría tarjeta.
        if ($pagosTc > 0.0001 || ($isCardCategory && $egresosAmt <= 0.0001 && $ingresosAmt <= 0.0001)) {
            $flags[] = 'pago_resumen_tarjeta_confirmado';
        } elseif ($isCardCategory && $egresosAmt > 0.0001) {
            $flags[] = 'pago_tarjeta_posible';
        }

        $flags = array_values(array_unique($flags));

        // Severidad con nueva semántica
        $status = ImportReviewStatus::Green;
        $hardRed = in_array('fecha_sospechosa', $flags, true)
            || in_array('cliente_ambiguo', $flags, true)
            || (
                in_array('utilidad_inconsistente', $flags, true)
                && ! in_array('valor_historico_corregido_por_interpretacion', $flags, true)
            );

        $needsYellow = $flags !== [] && (
            in_array('cc_omitida_probable', $flags, true)
            || in_array('cobro_desconocido', $flags, true)
            || in_array('cc_in_out_mismo_registro', $flags, true)
            || in_array('venta_economica', $flags, true)
            || in_array('ambito_dudoso', $flags, true)
            || in_array('cuenta_desconocida', $flags, true)
            || in_array('pago_tarjeta_posible', $flags, true)
            || in_array('pago_resumen_tarjeta_confirmado', $flags, true)
            || in_array('pago_tarjeta_sin_importe', $flags, true)
            || in_array('pago_tarjeta_sin_tarjeta', $flags, true)
            || in_array('pago_tarjeta_sin_cuenta_pago', $flags, true)
            || in_array('merca_analisis_only', $flags, true)
            || in_array('cc_combinado_ingreso', $flags, true)
            || in_array('fecha_apertura_revision', $flags, true)
            || in_array('fecha_corregible', $flags, true)
            || in_array('revision_venta', $flags, true)
            || in_array('reintegro_gasto_personal', $flags, true)
            || in_array('valor_historico_corregido_por_interpretacion', $flags, true)
            || in_array('cc_apertura_confirmada', $flags, true)
            || in_array('confirmed_opening_cc_balance', $flags, true)
            || in_array('cc_apertura_mercaderia_confirmada', $flags, true)
            || in_array('confirmed_opening_merca_balance', $flags, true)
            || in_array('cc_in_inferido_cintas', $flags, true)
            || in_array('cliente_cintas_confirmado', $flags, true)
            || in_array('cobro_confirmado_patagonia', $flags, true)
            || in_array('cobro_confirmado_ft', $flags, true)
            || in_array('cc_cancelacion_daasa_confirmada', $flags, true)
            || in_array('pago_tarjeta_cuenta_patagonia', $flags, true)
            || in_array('importe_pago_tarjeta_desconocido', $flags, true)
        );

        if ($hardRed) {
            $status = ImportReviewStatus::Red;
        } elseif ($needsYellow) {
            $status = ImportReviewStatus::Yellow;
        }

        // CC combinado con ingreso documentado: amarillo interpretable
        if (in_array('cc_combinado_ingreso', $flags, true) && $status === ImportReviewStatus::Red
            && ! in_array('fecha_sospechosa', $flags, true) && ! in_array('cliente_ambiguo', $flags, true)) {
            $status = ImportReviewStatus::Yellow;
        }

        // Venta económica bien formada (crédito/contado documentado) puede ser amarillo, no rojo
        if (($amounts['venta'] ?? 0) > 0 && ! $hardRed) {
            $status = ImportReviewStatus::Yellow;
        }

        if ($status === ImportReviewStatus::Yellow
            && count(array_diff($flags, ['ambito_dudoso', 'merca_analisis_only'])) === 0
            && $filled === 1
            && (($amounts['ingresos'] ?? 0) > 0 || ($amounts['egresos'] ?? 0) > 0)
            && $ctx['account_def']
        ) {
            $status = in_array('ambito_dudoso', $flags, true) ? ImportReviewStatus::Yellow : ImportReviewStatus::Green;
        }

        return [
            'status' => $status,
            'flags' => $flags,
            'scope' => $scope,
            'scope_ambiguous' => $scopeAmbiguous,
            'sale_kind' => $saleKind,
        ];
    }

    /**
     * @param  array<string, float>  $amounts
     * @param  array<string, mixed>|null  $complexDecision
     * @param  array<string, mixed>|null  $cardDecision
     * @return array<string, mixed>
     */
    private function interpret(
        array $classification,
        string $cuenta,
        string $subcuenta,
        array $amounts,
        ?array $accountDef,
        ?string $client,
        ?array $complexDecision = null,
        ?array $cardDecision = null,
        ?int $sourceRow = null,
        ?string $concepto = null,
    ): array {
        $out = [
            'kind' => 'simple',
            'finance_income' => 0.0,
            'finance_expense' => 0.0,
            'cc_charge' => 0.0,
            'cc_payment' => 0.0,
            'economic_venta' => 0.0,
            'economic_utilidad' => 0.0,
            'merca_in' => 0.0,
            'merca_out' => 0.0,
            'excel_cc_in' => (float) ($amounts['cc_in'] ?? 0),
            'excel_cc_out' => (float) ($amounts['cc_out'] ?? 0),
            'corrections' => [],
            'flags' => [],
            'would_generate' => [],
            'notes' => [],
            'components' => null,
        ];

        $recovery = $this->saleSemantics->analyzePersonalRecovery(
            (string) ($concepto ?? ''),
            $cuenta,
            $subcuenta,
            $amounts,
            $accountDef
        );
        if ($recovery) {
            $out['kind'] = 'reintegro_gasto_personal';
            $out['finance_income'] = (float) $recovery['finance_income'];
            $out['finance_expense'] = 0.0;
            $out['net_expense_reduction'] = (float) $recovery['net_expense_reduction'];
            $out['flags'] = $recovery['flags'];
            $out['would_generate'] = $recovery['would_generate'];
            $out['notes'] = $recovery['notes'];
            $out['components'] = [
                'REINTEGRO' => (float) $recovery['amount'],
                'CATEGORIA' => $recovery['category'],
                'CUENTA' => $recovery['account'],
                'EXCEL_EGRESOS' => (float) $recovery['excel_egresos'],
            ];

            return $out;
        }

        $openingCc = $this->saleSemantics->analyzeConfirmedOpeningCcBalance(
            $sourceRow,
            $concepto,
            $amounts,
            $client,
        );
        if ($openingCc) {
            $out['kind'] = (string) $openingCc['kind'];
            $out['client'] = $openingCc['client'];
            $out['finance_income'] = 0.0;
            $out['finance_expense'] = 0.0;
            $out['cc_charge'] = (float) $openingCc['cc_charge'];
            $out['cc_payment'] = (float) $openingCc['cc_payment'];
            $out['excel_cc_in'] = (float) $openingCc['excel_cc_in'];
            $out['excel_cc_out'] = (float) $openingCc['excel_cc_out'];
            $out['is_opening_adjustment'] = true;
            $out['corrections'] = $openingCc['corrections'];
            $out['flags'] = $openingCc['flags'];
            $out['components'] = $openingCc['components'];
            $out['would_generate'] = $openingCc['would_generate'];
            $out['notes'] = $openingCc['notes'];

            return $out;
        }

        $openingMerca = $this->saleSemantics->analyzeConfirmedOpeningMercaBalance(
            $sourceRow,
            $concepto,
            $amounts,
        );
        if ($openingMerca) {
            $out['kind'] = (string) $openingMerca['kind'];
            $out['client'] = null;
            $out['finance_income'] = 0.0;
            $out['finance_expense'] = 0.0;
            $out['cc_charge'] = 0.0;
            $out['cc_payment'] = 0.0;
            $out['excel_cc_in'] = (float) $openingMerca['excel_cc_in'];
            $out['excel_cc_out'] = (float) $openingMerca['excel_cc_out'];
            $out['is_opening_adjustment'] = true;
            $out['corrections'] = $openingMerca['corrections'];
            $out['flags'] = $openingMerca['flags'];
            $out['components'] = $openingMerca['components'];
            $out['would_generate'] = $openingMerca['would_generate'];
            $out['notes'] = $openingMerca['notes'];

            return $out;
        }

        $saleResolution = $this->saleSemantics->analyzeConfirmedSaleResolution(
            $sourceRow,
            $concepto,
            $amounts,
            $accountDef,
        );
        if ($saleResolution) {
            $out['kind'] = (string) $saleResolution['kind'];
            $out['client'] = $saleResolution['client'] ?? $client;
            $out['finance_income'] = (float) $saleResolution['finance_income'];
            $out['finance_expense'] = 0.0;
            $out['cc_charge'] = (float) $saleResolution['cc_charge'];
            $out['cc_payment'] = (float) $saleResolution['cc_payment'];
            $out['excel_cc_in'] = (float) $saleResolution['excel_cc_in'];
            $out['excel_cc_out'] = (float) $saleResolution['excel_cc_out'];
            $out['economic_venta'] = (float) $saleResolution['economic_venta'];
            $out['economic_utilidad'] = (float) $saleResolution['economic_utilidad'];
            $out['merca_out'] = (float) $saleResolution['merca_out'];
            $out['merca_in'] = (float) $saleResolution['merca_in'];
            $out['corrections'] = $saleResolution['corrections'];
            $out['flags'] = $saleResolution['flags'];
            $out['components'] = $saleResolution['components'];
            $out['would_generate'] = $saleResolution['would_generate'];
            $out['notes'] = $saleResolution['notes'];
            $out['finance_account_alias'] = $saleResolution['finance_account_alias'] ?? null;
            $out['finance_account_name'] = $saleResolution['finance_account_name'] ?? null;
            $out['check_duplicate_income'] = (bool) ($saleResolution['check_duplicate_income'] ?? false);

            return $out;
        }

        $ccSettlement = $this->saleSemantics->analyzeConfirmedCcSettlement(
            $sourceRow,
            $concepto,
            $amounts,
            $subcuenta,
        );
        if ($ccSettlement) {
            $out['kind'] = (string) $ccSettlement['kind'];
            $out['client'] = $ccSettlement['client'] ?? $client;
            $out['preserve_concepto'] = (bool) ($ccSettlement['preserve_concepto'] ?? false);
            $out['finance_income'] = (float) $ccSettlement['finance_income'];
            $out['finance_expense'] = 0.0;
            $out['cc_charge'] = (float) $ccSettlement['cc_charge'];
            $out['cc_payment'] = (float) $ccSettlement['cc_payment'];
            $out['excel_cc_in'] = (float) $ccSettlement['excel_cc_in'];
            $out['excel_cc_out'] = (float) $ccSettlement['excel_cc_out'];
            $out['economic_venta'] = (float) ($ccSettlement['economic_venta'] ?? 0);
            $out['economic_utilidad'] = 0.0;
            $out['merca_out'] = (float) ($ccSettlement['merca_out'] ?? 0);
            $out['merca_in'] = (float) ($ccSettlement['merca_in'] ?? 0);
            $out['corrections'] = $ccSettlement['corrections'];
            $out['flags'] = $ccSettlement['flags'];
            $out['components'] = $ccSettlement['components'];
            $out['would_generate'] = $ccSettlement['would_generate'];
            $out['notes'] = $ccSettlement['notes'];
            $out['finance_account_alias'] = $ccSettlement['finance_account_alias'] ?? null;
            $out['finance_account_name'] = $ccSettlement['finance_account_name'] ?? null;
            $out['check_duplicate_income'] = (bool) ($ccSettlement['check_duplicate_income'] ?? false);

            return $out;
        }

        $clientCc = $this->saleSemantics->analyzeConfirmedClientCcCharge(
            $sourceRow,
            $concepto,
            $amounts,
        );
        if ($clientCc) {
            $out['kind'] = (string) $clientCc['kind'];
            $out['client'] = $clientCc['client'] ?? $client;
            $out['finance_income'] = 0.0;
            $out['finance_expense'] = 0.0;
            $out['cc_charge'] = (float) $clientCc['cc_charge'];
            $out['cc_payment'] = 0.0;
            $out['excel_cc_in'] = (float) $clientCc['excel_cc_in'];
            $out['excel_cc_out'] = (float) $clientCc['excel_cc_out'];
            $out['corrections'] = $clientCc['corrections'];
            $out['flags'] = $clientCc['flags'];
            $out['components'] = $clientCc['components'];
            $out['would_generate'] = $clientCc['would_generate'];
            $out['notes'] = $clientCc['notes'];

            return $out;
        }

        if ($complexDecision && ! empty($complexDecision['approved'])) {
            $out['kind'] = 'complex_resolved';
            $out['finance_income'] = (float) ($complexDecision['finance_income'] ?? $complexDecision['cobro'] ?? 0);
            $out['finance_expense'] = (float) ($complexDecision['finance_expense'] ?? 0);
            $out['cc_charge'] = (float) ($complexDecision['cc_charge'] ?? 0);
            $out['cc_payment'] = (float) ($complexDecision['cc_payment'] ?? 0);
            $out['economic_venta'] = (float) ($complexDecision['venta'] ?? 0);
            $out['economic_utilidad'] = (float) ($complexDecision['utilidad'] ?? 0);
            $out['merca_out'] = (float) ($complexDecision['merca_out'] ?? 0);
            $out['merca_in'] = (float) ($complexDecision['merca_in'] ?? 0);
            $out['components'] = [
                'VENTA' => $out['economic_venta'],
                'COBRO_FINANCIERO' => $out['finance_income'],
                'CC_IN' => $out['cc_charge'],
                'CC_OUT' => $out['cc_payment'],
                'MERCADERIA_ENTREGADA' => $out['merca_out'],
                'MERCADERIA_RECIBIDA' => $out['merca_in'],
                'UTILIDAD' => $out['economic_utilidad'],
            ];
            foreach ($out['components'] as $label => $val) {
                if ($val > 0.0001) {
                    $out['would_generate'][] = "{$label}: {$val}";
                }
            }
            $out['notes'][] = 'Venta resuelta manualmente (preview; sin importar). Utilidad ≠ caja.';
            $out['notes'][] = 'Mercadería solo análisis — no stock físico.';

            return $out;
        }

        if ($cardDecision) {
            $kind = $cardDecision['kind'] ?? 'purchase';
            if ($kind === 'statement_payment') {
                return $this->buildCardStatementPaymentInterpretation(
                    $cuenta,
                    $subcuenta,
                    (string) ($concepto ?? ''),
                    $amounts,
                    $accountDef,
                    confirmedBy: 'preview_decision',
                    sourceRow: $sourceRow,
                );
            }
            $out['kind'] = 'card_purchase';
            $out['finance_expense'] = (float) ($amounts['egresos'] ?? 0);
            $out['would_generate'][] = 'Gasto/compra '.$out['finance_expense'];
            $out['would_generate'][] = 'Aumenta pasivo tarjeta ('.($accountDef['name'] ?? $subcuenta).')';
            $out['notes'][] = 'Compra con tarjeta: gasto + pasivo.';

            return $out;
        }

        // Regla aprobada: pagos_tc / Tarjetas = pago de resumen (cancelación de pasivo).
        if (in_array('pago_resumen_tarjeta_confirmado', $classification['flags'], true)
            && $this->rules->hasInterpretationRule('pago_resumen_tarjeta')
        ) {
            return $this->buildCardStatementPaymentInterpretation(
                $cuenta,
                $subcuenta,
                (string) ($concepto ?? ''),
                $amounts,
                $accountDef,
                confirmedBy: 'regla_pago_resumen_tarjeta',
                sourceRow: $sourceRow,
            );
        }

        // Ventas con nueva semántica (antes de marcar "compleja")
        if (($amounts['venta'] ?? 0) > 0.0001 || in_array('venta_economica', $classification['flags'], true)) {
            $sale = $this->saleSemantics->analyzeSale(
                $amounts,
                $cuenta,
                $subcuenta,
                $accountDef,
                $client,
                $sourceRow,
                $concepto,
            );
            $out['kind'] = 'sale_'.$sale['sale_kind'];
            $out['finance_income'] = $sale['finance_income'];
            $out['finance_expense'] = $sale['finance_expense'];
            $out['cc_charge'] = $sale['cc_charge'];
            $out['cc_payment'] = $sale['cc_payment'];
            $out['excel_cc_in'] = $sale['excel_cc_in'];
            $out['excel_cc_out'] = $sale['excel_cc_out'];
            $out['corrections'] = $sale['corrections'];
            $out['economic_venta'] = (float) ($sale['components']['VENTA'] ?? 0);
            $out['economic_utilidad'] = (float) ($sale['components']['UTILIDAD'] ?? 0);
            $out['merca_out'] = (float) ($sale['components']['COSTO_MERCADERIA'] ?? 0);
            $out['merca_in'] = (float) ($sale['components']['MERCADERIA_RECIBIDA'] ?? 0);
            $out['components'] = $sale['components'];
            $out['would_generate'] = $sale['would_generate'];
            $out['notes'] = $sale['notes'];

            return $out;
        }

        if (in_array('cc_combinado_ingreso', $classification['flags'], true)
            && $this->rules->hasInterpretationRule('cc_out_with_income')
        ) {
            $out['kind'] = 'cc_payment_with_cash';
            $out['finance_income'] = $amounts['ingresos'] ?? 0;
            $out['cc_payment'] = $amounts['cc_out'] ?? 0;
            $out['would_generate'][] = 'Ingreso financiero '.$out['finance_income'].' en '.($accountDef['name'] ?? $subcuenta);
            $out['would_generate'][] = 'CC cobro '.$out['cc_payment'].' vinculado (sin duplicar caja) → '.($client ?? '?');
            $out['notes'][] = 'Regla inequívoca aplicada: CC OUT + ingreso = un solo impacto de caja.';

            return $out;
        }

        // Compra con tarjeta (egresos en VISA/MC/MCMP): gasto + pasivo; no es pago de resumen.
        if (in_array('pago_tarjeta_posible', $classification['flags'], true)
            && ($amounts['egresos'] ?? 0) > 0
            && $this->rules->hasInterpretationRule('card_liability_expense')
        ) {
            $out['kind'] = 'card_purchase_preview';
            $out['finance_expense'] = (float) $amounts['egresos'];
            $out['would_generate'][] = 'Preview compra tarjeta '.$out['finance_expense'].' + pasivo';
            $out['notes'][] = 'Compra con tarjeta (egresos): gasto + aumento de pasivo.';

            return $out;
        }

        if (($amounts['ingresos'] ?? 0) > 0) {
            $out['finance_income'] = $amounts['ingresos'];
            $out['would_generate'][] = 'Ingreso financiero '.$amounts['ingresos'].' en '.($accountDef['name'] ?? $subcuenta);
        }
        if (($amounts['egresos'] ?? 0) > 0) {
            $out['finance_expense'] = $amounts['egresos'];
            $liability = (bool) ($accountDef['liability'] ?? false);
            if ($liability) {
                $out['would_generate'][] = 'Gasto + aumento deuda tarjeta '.$amounts['egresos'].' ('.$accountDef['name'].')';
            } else {
                $out['would_generate'][] = 'Egreso financiero '.$amounts['egresos'].' en '.($accountDef['name'] ?? $subcuenta);
            }
        }
        if (($amounts['cc_in'] ?? 0) > 0) {
            $out['cc_charge'] = $amounts['cc_in'];
            $out['would_generate'][] = 'CC cargo (cliente debe más) '.$amounts['cc_in'].' → '.($client ?? '?');
        }
        if (($amounts['cc_out'] ?? 0) > 0) {
            $out['cc_payment'] = $amounts['cc_out'];
            if (($amounts['ingresos'] ?? 0) > 0) {
                $out['would_generate'][] = 'CC cobro '.$amounts['cc_out'].' vinculado a ingreso financiero (sin duplicar caja)';
                $out['notes'][] = 'CC OUT + ingreso coexisten: un solo impacto de caja.';
            } else {
                $out['would_generate'][] = 'CC OUT (cancela deuda) '.$amounts['cc_out'].' — sin inventar ingreso bancario si el medio no está documentado';
            }
        }
        if (($amounts['merca_in'] ?? 0) > 0 || ($amounts['merca_out'] ?? 0) > 0) {
            $out['merca_in'] = (float) ($amounts['merca_in'] ?? 0);
            $out['merca_out'] = (float) ($amounts['merca_out'] ?? 0);
            $out['notes'][] = 'Merca IN/OUT solo análisis — no stock de apertura.';
        }

        return $out;
    }

    /**
     * @return array{ok:bool,iso:?string,error:?string}
     */
    /**
     * Anotación incompleta: sin fecha parseable e importe económico 0.
     * No es error de importación ni basura.
     * Intereses ganados se conservan como asiento (nunca pending_complete).
     *
     * @param  array{ok?:bool}  $date
     * @param  array<string, float>  $amounts
     */
    private function buildScopeResolutionReport(array $rows): array
    {
        $toPersonal = [];
        $toProfessional = [];
        $stillAmbiguous = [];
        $byRule = [];
        $overrides = [];

        foreach ($rows as $row) {
            $trace = $row['scope_trace'] ?? [];
            $hasAmbiguo = in_array('ambito_dudoso', $row['flags'] ?? [], true);

            if ($hasAmbiguo) {
                $stillAmbiguous[] = [
                    'source_row' => $row['source_row'] ?? null,
                    'concepto' => $row['concepto'] ?? '',
                    'categoria' => $row['excel_cuenta_category'] ?? '',
                    'subcuenta' => $row['excel_subcuenta_account'] ?? '',
                ];
                continue;
            }

            if (($trace['original_classification'] ?? '') !== 'ambito_dudoso') {
                continue;
            }

            $entry = [
                'source_row' => $row['source_row'] ?? null,
                'concepto' => $row['concepto'] ?? '',
                'categoria' => $row['excel_cuenta_category'] ?? '',
                'scope' => $trace['final_scope'] ?? $row['proposed_scope'] ?? null,
                'rule_id' => $trace['rule_id'] ?? null,
                'rule_label' => $trace['rule_label'] ?? null,
                'reason' => $trace['reason'] ?? null,
                'precedence' => $trace['precedence'] ?? null,
                'override_allowed' => $trace['override_allowed'] ?? true,
            ];

            if (($entry['scope'] ?? '') === 'personal') {
                $toPersonal[] = $entry;
            } elseif (($entry['scope'] ?? '') === 'professional') {
                $toProfessional[] = $entry;
            }

            $rid = (string) ($trace['rule_id'] ?? 'unknown');
            if (! isset($byRule[$rid])) {
                $byRule[$rid] = [
                    'rule_id' => $rid,
                    'label' => $trace['rule_label'] ?? $rid,
                    'count' => 0,
                    'scope' => $entry['scope'],
                ];
            }
            $byRule[$rid]['count']++;

            if (str_starts_with($rid, 'row_override_')) {
                $overrides[] = $entry;
            }
        }

        return [
            'cohort_original_ambito_dudoso' => count($toPersonal) + count($toProfessional) + count($stillAmbiguous),
            'to_personal' => count($toPersonal),
            'to_professional' => count($toProfessional),
            'still_ambiguous' => count($stillAmbiguous),
            'to_personal_rows' => $toPersonal,
            'to_professional_rows' => $toProfessional,
            'still_ambiguous_rows' => $stillAmbiguous,
            'by_rule' => array_values($byRule),
            'row_overrides_applied' => $overrides,
            'reusable_rules' => [
                ['id' => 'categoria_comidas_personal', 'scope' => 'personal', 'condicion' => 'categoría = Comidas (salvo excepción profesional)'],
                ['id' => 'contexto_profesional_inequivoco', 'scope' => 'professional', 'condicion' => 'keywords cliente/visita/instalación/reparación/trabajo/viaje profesional'],
                ['id' => 'cliente_conocido_en_concepto', 'scope' => 'professional', 'condicion' => 'concepto menciona cliente conocido (Lidercar, Kaisha, …)'],
                ['id' => 'envio_equipo_cliente', 'scope' => 'professional', 'condicion' => 'envío/transporte de equipo informático asociado a cliente'],
                ['id' => 'viatico_peaje_sin_contexto_profesional', 'scope' => 'personal', 'condicion' => 'Viáticos peaje/AUSA/estacionamiento/Blinkay sin contexto profesional'],
                ['id' => 'viatico_peaje_contexto_profesional', 'scope' => 'professional', 'condicion' => 'Viáticos peaje/estacionamiento con contexto profesional'],
                ['id' => 'chacharramendi_personal', 'scope' => 'personal', 'condicion' => 'concepto Chacharramendi (no regla global Uber)'],
            ],
            'notes' => [
                'Override manual sigue permitido vía ImportPreviewDecision scope.',
                'Titular/cuenta financiera no determina ámbito.',
                'No se creó regla global Uber ni Ezeiza ni Jumbo.',
            ],
        ];
    }

    private function isPendingCompleteAnnotation(array $date, array $amounts, string $concepto = '', string $cuenta = ''): bool
    {
        if ($date['ok'] ?? false) {
            return false;
        }

        if ($this->isInteresesGanados($concepto, $cuenta)) {
            return false;
        }

        foreach (['ingresos', 'egresos', 'cc_in', 'cc_out', 'pagos_tc', 'merca_in', 'merca_out', 'venta', 'ut_ventas'] as $k) {
            if (($amounts[$k] ?? 0) > 0.0001) {
                return false;
            }
        }

        return true;
    }

    private function isInteresesGanados(string $concepto, string $cuenta): bool
    {
        if (strcasecmp(trim($cuenta), 'Intereses ganados') === 0) {
            return true;
        }
        $c = mb_strtolower(trim($concepto));

        return $c !== '' && str_contains($c, 'intereses') && (
            str_contains($c, 'ganad') || str_contains($c, 'mp fer') || str_contains($c, 'mp gabi')
        );
    }

    private function parseDate(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return ['ok' => false, 'iso' => null, 'error' => 'vacía'];
        }
        try {
            if (is_numeric($raw)) {
                $dt = ExcelDate::excelToDateTimeObject((float) $raw);

                return ['ok' => true, 'iso' => $dt->format('Y-m-d'), 'error' => null];
            }
            $str = trim((string) $raw);
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $str, $m)) {
                if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    return ['ok' => false, 'iso' => $str, 'error' => 'imposible'];
                }

                return ['ok' => true, 'iso' => $str, 'error' => null];
            }
            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $str, $m)) {
                $iso = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
                if (! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                    return ['ok' => false, 'iso' => $iso, 'error' => 'imposible'];
                }

                return ['ok' => true, 'iso' => $iso, 'error' => null];
            }

            return ['ok' => false, 'iso' => $str, 'error' => 'formato'];
        } catch (Throwable $e) {
            return ['ok' => false, 'iso' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Pago de resumen / cancelación de pasivo (regla aprobada sobre columna pagos_tc).
     * No inventa importe; no genera segundo gasto; no toca el gasto original del consumo.
     *
     * @param  array<string, float>  $amounts
     * @param  array<string, mixed>|null  $accountDef
     * @return array<string, mixed>
     */
    private function buildCardStatementPaymentInterpretation(
        string $cuenta,
        string $subcuenta,
        string $concepto,
        array $amounts,
        ?array $accountDef,
        string $confirmedBy,
        ?int $sourceRow = null,
    ): array {
        $pay = (float) ($amounts['pagos_tc'] ?? 0);
        // No usar egresos como fallback: esa columna es gasto/compra, no pago de resumen.
        $amountMissing = $pay <= 0.0001;

        $cardAlias = $this->resolveCardLiabilityAlias($cuenta, $concepto);
        $cardDef = $cardAlias
            ? (config('historical_import.financial_aliases.'.$cardAlias) ?? null)
            : null;
        $cardMissing = $cardAlias === null || $cardDef === null;

        $paymentAccountName = null;
        $paymentAccountAlias = null;
        $paymentMissing = false;
        if ($subcuenta === '') {
            $paymentMissing = true;
        } elseif ($accountDef) {
            if (! empty($accountDef['liability'])) {
                // SubCuenta es la propia tarjeta u otro pasivo: no identifica desde dónde se pagó.
                $paymentMissing = true;
            } else {
                $paymentAccountName = (string) ($accountDef['name'] ?? $subcuenta);
                $paymentAccountAlias = (string) ($accountDef['alias'] ?? $subcuenta);
            }
        } else {
            $paymentMissing = true;
        }

        // Override confirmado: cuenta pagadora (ej. fila 131 → Patagonia).
        $accountOverride = $this->saleSemantics->matchCardPaymentAccountOverride($sourceRow, $concepto);
        if ($accountOverride && ! empty($accountOverride['payment_account_alias'])) {
            $overrideAlias = (string) $accountOverride['payment_account_alias'];
            $overrideDef = config('historical_import.financial_aliases.'.$overrideAlias);
            if (is_array($overrideDef)) {
                $paymentAccountAlias = (string) ($overrideDef['alias'] ?? $overrideAlias);
                $paymentAccountName = (string) ($overrideDef['name'] ?? $overrideAlias);
                $paymentMissing = false;
            }
        }

        $amountUnknownConfirmed = $this->saleSemantics->matchCardPaymentAmountUnknown($sourceRow, $concepto);

        $flags = ['pago_resumen_tarjeta_confirmado'];
        if ($amountUnknownConfirmed) {
            $flags = array_values(array_unique(array_merge(
                $flags,
                $amountUnknownConfirmed['flags'] ?? ['importe_pago_tarjeta_desconocido']
            )));
        } elseif ($amountMissing) {
            $flags[] = 'pago_tarjeta_sin_importe';
        }
        if ($cardMissing) {
            $flags[] = 'pago_tarjeta_sin_tarjeta';
        }
        if ($paymentMissing && ! $amountUnknownConfirmed) {
            $flags[] = 'pago_tarjeta_sin_cuenta_pago';
        }
        if ($accountOverride) {
            $flags = array_values(array_unique(array_merge(
                $flags,
                $accountOverride['flags'] ?? ['pago_tarjeta_cuenta_patagonia']
            )));
        }

        $cardLabel = $cardDef['name'] ?? ($cardAlias ?? $cuenta ?: '?');
        $out = [
            'kind' => 'card_statement_payment',
            'finance_income' => 0.0,
            'finance_expense' => 0.0,
            'cc_charge' => 0.0,
            'cc_payment' => 0.0,
            'economic_venta' => 0.0,
            'economic_utilidad' => 0.0,
            'merca_in' => 0.0,
            'merca_out' => 0.0,
            'excel_cc_in' => (float) ($amounts['cc_in'] ?? 0),
            'excel_cc_out' => (float) ($amounts['cc_out'] ?? 0),
            'corrections' => [],
            'flags' => $flags,
            'would_generate' => [],
            'notes' => [
                'Regla aprobada: columna Tarjetas/pagos_tc = pago mensual del resumen al banco (cancelación de pasivo).',
                'No es compra ni gasto nuevo; no modifica el gasto original del consumo.',
                'Confirmado por: '.$confirmedBy,
            ],
            'components' => [
                'PAGO_RESUMEN' => $amountMissing ? 0.0 : $pay,
                'PASIVO_TARJETA' => $cardLabel,
                'CUENTA_PAGO' => $paymentAccountName ?? ($paymentMissing ? null : $subcuenta),
                'PAGOS_TC_EXCEL' => $pay,
            ],
            'card_alias' => $cardAlias,
            'payment_account_alias' => $paymentAccountAlias,
            'card_liability_decrease' => $amountMissing ? 0.0 : $pay,
            'payment_account_decrease' => ($amountMissing || $paymentMissing) ? 0.0 : $pay,
        ];

        if ($accountOverride) {
            $out['notes'][] = (string) ($accountOverride['reason'] ?? 'Cuenta pagadora confirmada por el usuario.');
            $out['corrections'][] = [
                'field' => 'payment_account',
                'excel' => $subcuenta,
                'interpreted' => $paymentAccountAlias,
                'reason' => $accountOverride['reason'] ?? 'Cuenta pagadora confirmada',
            ];
        }
        if ($amountUnknownConfirmed) {
            $out['would_generate'][] = 'SIN importe documentado — naturaleza pago resumen confirmada; pendiente completar importe';
            $out['notes'][] = (string) ($amountUnknownConfirmed['reason'] ?? 'Importe de pago de resumen desconocido.');
            $out['notes'][] = 'decision_required=false; import_ready=false; estado=pending_complete.';
        } elseif ($amountMissing) {
            $out['would_generate'][] = 'SIN importe documentado en pagos_tc — no inventar monto';
            $out['notes'][] = 'Excepción: pagos_tc = 0 / vacío sin otro importe documentado para el pago de resumen.';
        } else {
            if (! $paymentMissing && $paymentAccountName) {
                $out['would_generate'][] = 'Disminuye cuenta de pago '.$pay.' ('.$paymentAccountName.')';
            } elseif ($paymentMissing) {
                $out['would_generate'][] = 'Disminuye cuenta de pago '.$pay.' (CUENTA NO IDENTIFICADA; SubCuenta='.($subcuenta !== '' ? $subcuenta : 'vacía').')';
            }
            if (! $cardMissing) {
                $out['would_generate'][] = 'Disminuye pasivo tarjeta '.$pay.' ('.$cardLabel.')';
            } else {
                $out['would_generate'][] = 'Disminuye pasivo tarjeta '.$pay.' (TARJETA NO IDENTIFICADA)';
            }
            $out['would_generate'][] = 'NO genera segundo gasto';
        }

        return $out;
    }

    private function resolveCardLiabilityAlias(string $cuenta, string $concepto): ?string
    {
        $concept = mb_strtoupper(trim($concepto));
        // MCMP / typo MCML tienen prioridad sobre MC genérico en Cuenta.
        if (preg_match('/\bMCMP\b|\bMCML\b/u', $concept)) {
            return 'MCMP';
        }
        if (in_array($cuenta, ['VISA', 'MC', 'MCMP'], true)) {
            return $cuenta;
        }
        if (str_contains($concept, 'VISA')) {
            return 'VISA';
        }
        if (preg_match('/\bMC\b/u', $concept)) {
            return 'MC';
        }

        return null;
    }

    /**
     * Si un cobro confirmado duplica un Ingresos Excel ya existente, no crear segundo ingreso.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function suppressDuplicateConfirmedIncomes(array &$rows): array
    {
        $excelIncomes = [];
        foreach ($rows as $row) {
            $ing = (float) ($row['amounts']['ingresos'] ?? 0);
            if ($ing > 0.0001) {
                $excelIncomes[] = [
                    'source_row' => (int) ($row['source_row'] ?? 0),
                    'amount' => $ing,
                    'concepto' => (string) ($row['concepto'] ?? ''),
                ];
            }
        }

        $report = [];
        foreach ($rows as &$row) {
            $check = ! empty($row['interpretation']['check_duplicate_income']);
            $fi = (float) ($row['interpretation']['finance_income'] ?? 0);
            if (! $check || $fi <= 0.0001) {
                continue;
            }
            $self = (int) ($row['source_row'] ?? 0);
            $dup = null;
            foreach ($excelIncomes as $hit) {
                if ($hit['source_row'] === $self) {
                    continue;
                }
                if (abs($hit['amount'] - $fi) < 0.51) {
                    $dup = $hit;
                    break;
                }
            }
            if (! $dup) {
                $row['interpretation']['duplicate_income_check'] = 'no_duplicate_excel_income';
                $report[] = [
                    'source_row' => $self,
                    'amount' => $fi,
                    'action' => 'create_confirmed_income',
                    'duplicate_of' => null,
                ];
                continue;
            }

            $row['interpretation']['finance_income'] = 0.0;
            $row['interpretation']['finance_income_suppressed_duplicate'] = true;
            $row['interpretation']['duplicate_income_of'] = $dup;
            $row['interpretation']['components']['COBRO_FINANCIERO'] = 0.0;
            $row['flags'] = array_values(array_unique(array_merge(
                $row['flags'] ?? [],
                ['ingreso_no_duplicado']
            )));
            $row['interpretation']['notes'] = array_values(array_unique(array_merge(
                $row['interpretation']['notes'] ?? [],
                [
                    'Cobro confirmado NO creado: existe ingreso Excel equivalente en fila '
                    .$dup['source_row'].' ('.$dup['amount'].').',
                ]
            )));
            $row['interpretation']['would_generate'] = array_values(array_filter(
                array_map(
                    fn ($line) => is_string($line) && str_starts_with($line, 'COBRO FINANCIERO')
                        ? 'COBRO FINANCIERO omitido (duplicado de fila '.$dup['source_row'].')'
                        : $line,
                    $row['interpretation']['would_generate'] ?? []
                )
            ));
            $report[] = [
                'source_row' => $self,
                'amount' => $fi,
                'action' => 'suppress_duplicate_income',
                'duplicate_of' => $dup,
            ];
        }
        unset($row);

        return $report;
    }

    private function num(mixed $v): float
    {
        if ($v === null || $v === '') {
            return 0.0;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }
        $s = str_replace(['.', ' '], ['', ''], (string) $v);
        $s = str_replace(',', '.', $s);

        return is_numeric($s) ? (float) $s : 0.0;
    }

    private function looksLikeClientSubcuenta(string $subcuenta, string $cuenta): bool
    {
        // En filas CC / Ventas / Abonos la SubCuenta suele ser el cliente, no la caja.
        if (! in_array($cuenta, ['CC', 'Ventas', 'Abonos'], true)) {
            return false;
        }
        $financial = config('historical_import.financial_aliases', []);

        return ! isset($financial[$subcuenta]);
    }

    private function isRelevantSupplierCandidate(string $concepto): bool
    {
        foreach (config('historical_import.relevant_suppliers', []) as $name) {
            if (stripos($concepto, $name) !== false) {
                return true;
            }
        }

        return false;
    }

    private function supplierToken(string $concepto): ?string
    {
        foreach (config('historical_import.relevant_suppliers', []) as $name) {
            if (stripos($concepto, $name) !== false) {
                return $name;
            }
        }

        return null;
    }
}
