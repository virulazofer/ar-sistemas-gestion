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
            'rows_valid' => $preview['summary']['green'] + $preview['summary']['yellow'],
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
                'rows_sample_yellow' => array_slice($preview['rows_by_status']['yellow'], 0, 40),
                'rows_sample_red' => array_slice($preview['rows_by_status']['red'], 0, 60),
                'rows_all_path' => $preview['rows_all_path'],
                'confirm_blocked' => true,
                'confirm_blocked_reason' => 'Etapa 11E: confirmación definitiva de movimientos deshabilitada hasta autorización expresa.',
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
            ],
            'user_id' => $userId,
        ]);

        $this->audit->log('historical_movements_previewed', $batch, null, [
            'green' => $batch->rows_green,
            'yellow' => $batch->rows_yellow,
            'red' => $batch->rows_red,
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
            'Confirmación de movimientos históricos bloqueada en esta etapa. Se requiere autorización expresa.'
        );
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
            'rows_valid' => $preview['summary']['green'] + $preview['summary']['yellow'],
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
                'rows_sample_yellow' => array_slice($preview['rows_by_status']['yellow'], 0, 40),
                'rows_sample_red' => array_slice($preview['rows_by_status']['red'], 0, 60),
                'rows_all_path' => $preview['rows_all_path'],
                'confirm_blocked' => true,
                'confirm_blocked_reason' => 'Etapa 11E: confirmación definitiva de movimientos deshabilitada hasta autorización expresa.',
                'reprocessed_at' => now()->toDateTimeString(),
                'decisions_applied' => $preview['decisions_applied'] ?? 0,
            ],
            'classification_summary' => array_merge($preview['summary'], [
                'root_cause_groups' => [
                    'yellow' => array_map(fn ($g) => [
                        'cause' => $g['cause'], 'label' => $g['label'], 'count' => $g['count'],
                    ], $preview['root_cause_groups']['yellow'] ?? []),
                    'red' => array_map(fn ($g) => [
                        'cause' => $g['cause'], 'label' => $g['label'], 'count' => $g['count'],
                    ], $preview['root_cause_groups']['red'] ?? []),
                ],
            ]),
            'reconciliation_payload' => array_merge($preview['reconciliation'], [
                'difference_attribution' => $preview['difference_attribution'],
            ]),
            'options' => array_merge($batch->options ?? [], [
                'confirm_enabled' => false,
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
        $matrix = $sheet->toArray(null, false, false, false);
        $spreadsheet->disconnectWorksheets();

        $start = max(0, ((int) config('historical_import.movements_data_start_row', 4)) - 1);
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
        $byStatus = ['green' => [], 'yellow' => [], 'red' => [], 'excluded' => []];
        $subscriptionBuckets = [];
        $decisionsApplied = 0;

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
            if ($concepto !== '' && preg_match('/^('.$monthNames.')$/iu', $concepto)) {
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
            ]);

            // Scope: row decision overrides concept rule overrides category default
            $scopeDecision = $scopeDecisions[$sourceRow] ?? null;
            if ($scopeDecision) {
                $decisionsApplied++;
                $scopeVal = $scopeDecision->payload['scope'] ?? null;
                if ($scopeVal === 'personal' || $scopeVal === 'professional') {
                    $classification['scope'] = $scopeVal;
                    $classification['scope_ambiguous'] = false;
                    $classification['flags'] = array_values(array_diff($classification['flags'], ['ambito_dudoso']));
                    if ($classification['flags'] === [] && $classification['status'] === ImportReviewStatus::Yellow) {
                        $classification['status'] = ImportReviewStatus::Green;
                    } elseif ($classification['status'] === ImportReviewStatus::Yellow
                        && count(array_diff($classification['flags'], ['ambito_dudoso'])) === 0) {
                        $classification['status'] = ImportReviewStatus::Green;
                    }
                }
            } else {
                $ruleScope = $this->rules->resolveScopeOverride($concepto, $cuenta);
                if ($ruleScope) {
                    $classification['scope'] = $ruleScope;
                    $classification['scope_ambiguous'] = false;
                    $classification['flags'] = array_values(array_diff($classification['flags'], ['ambito_dudoso']));
                    if ($classification['status'] === ImportReviewStatus::Yellow
                        && count(array_diff($classification['flags'], ['ambito_dudoso'])) === 0) {
                        $classification['status'] = ImportReviewStatus::Green;
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
            );

            if ($complexDecision) {
                $decisionsApplied++;
                // Approved complex sale: remove complex red if components saved
                $classification['flags'] = array_values(array_diff($classification['flags'], ['operacion_compleja']));
                $classification['status'] = ImportReviewStatus::Yellow;
                $classification['flags'][] = 'venta_compleja_resuelta';
            }
            if ($cardDecision) {
                $decisionsApplied++;
                $classification['flags'] = array_values(array_diff($classification['flags'], ['pago_tarjeta_posible']));
                if ($classification['flags'] === []) {
                    $classification['status'] = ImportReviewStatus::Green;
                } elseif ($classification['status'] === ImportReviewStatus::Yellow
                    && count(array_diff($classification['flags'], ['pago_tarjeta_posible', 'ambito_dudoso'])) === 0
                    && ! in_array('ambito_dudoso', $classification['flags'], true)) {
                    $classification['status'] = ImportReviewStatus::Green;
                }
                $classification['flags'][] = 'tarjeta_resuelta';
            }

            $row = [
                'source_file' => basename($path),
                'sheet' => $sheetName,
                'source_row' => $sourceRow,
                'row_hash' => $rowHash,
                'date' => $date['iso'] ?? null,
                'date_raw' => $fechaRaw,
                'concepto' => $concepto,
                'excel_cuenta_category' => $cuenta,
                'excel_subcuenta_account' => $subcuenta,
                'amounts' => $amounts,
                'review_status' => $classification['status']->value,
                'flags' => array_values(array_unique($classification['flags'])),
                'root_cause' => null,
                'proposed_scope' => $classification['scope'],
                'scope_ambiguous' => $classification['scope_ambiguous'],
                'client' => $client,
                'interpretation' => $interpreted,
                'trace' => [
                    'archivo' => basename($path),
                    'hoja' => $sheetName,
                    'fila' => $sourceRow,
                ],
            ];
            $row['root_cause'] = $this->rootCauses->inferRootCause($row);

            if (strcasecmp($cuenta, 'Abonos') === 0 && $client) {
                $subscriptionBuckets[$client] = ($subscriptionBuckets[$client] ?? 0) + 1;
            }

            $rows[] = $row;
            $byStatus[$classification['status']->value][] = $row;
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
            'candidate_movements' => count($rows) - count($byStatus['excluded']),
            'green' => count($byStatus['green']),
            'yellow' => count($byStatus['yellow']),
            'red' => count($byStatus['red']),
            'excluded' => count($byStatus['excluded']),
            'suspicious_dates' => count(array_filter($rows, fn ($r) => in_array('fecha_sospechosa', $r['flags'], true))),
            'complex_operations' => count(array_filter($rows, fn ($r) => in_array('operacion_compleja', $r['flags'], true))),
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
        ];

        $reconciliation = [
            'excel' => [
                'ingresos_ars' => round($excelTotals['ingresos'], 2),
                'egresos_ars' => round($excelTotals['egresos'], 2),
                'cc_in_ars' => round($excelTotals['cc_in'], 2),
                'cc_out_ars' => round($excelTotals['cc_out'], 2),
                'merca_in' => round($excelTotals['merca_in'], 2),
                'merca_out' => round($excelTotals['merca_out'], 2),
                'ventas' => round($excelTotals['venta'], 2),
                'utilidad_ventas' => round($excelTotals['ut_ventas'], 2),
                'resultado_aprox' => round($excelTotals['ingresos'] + $excelTotals['venta'] - $excelTotals['egresos'], 2),
            ],
            'ar_sistemas_preview' => [
                'ingresos_ars' => round(array_sum(array_map(fn ($r) => ($r['interpretation']['finance_income'] ?? 0), $rows)), 2),
                'egresos_ars' => round(array_sum(array_map(fn ($r) => ($r['interpretation']['finance_expense'] ?? 0), $rows)), 2),
                'cc_charges' => round(array_sum(array_map(fn ($r) => ($r['interpretation']['cc_charge'] ?? 0), $rows)), 2),
                'cc_payments' => round(array_sum(array_map(fn ($r) => ($r['interpretation']['cc_payment'] ?? 0), $rows)), 2),
                'note' => 'Valores reconstruibles solo desde filas interpretables; no incluye stock físico.',
            ],
            'differences' => [],
            'notes' => [
                'Mercadería IN/OUT se usa para análisis/conciliación, NO para stock de apertura.',
                'Operaciones complejas quedan en Rojo/revisión manual.',
                'No se exige diferencia cero artificial.',
            ],
        ];

        $reconciliation['differences'] = [
            'ingresos' => round($reconciliation['excel']['ingresos_ars'] - $reconciliation['ar_sistemas_preview']['ingresos_ars'], 2),
            'egresos' => round($reconciliation['excel']['egresos_ars'] - $reconciliation['ar_sistemas_preview']['egresos_ars'], 2),
            'cc_in' => round($reconciliation['excel']['cc_in_ars'] - $reconciliation['ar_sistemas_preview']['cc_charges'], 2),
            'cc_out' => round($reconciliation['excel']['cc_out_ars'] - $reconciliation['ar_sistemas_preview']['cc_payments'], 2),
        ];

        $rootCauseGroups = $this->rootCauses->groupByRootCause($rows);
        $differenceAttribution = $this->rootCauses->attributeDifferences($rows);

        $allPath = 'imports/previews/'.Str::uuid().'.json';
        Storage::disk('local')->put($allPath, json_encode([
            'summary' => $summary,
            'rows' => $rows,
            'root_cause_groups' => $rootCauseGroups,
            'difference_attribution' => $differenceAttribution,
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
     * @return array{status: ImportReviewStatus, flags: list<string>, scope: string|null, scope_ambiguous: bool}
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

        if (! ($date['ok'] ?? false)) {
            $flags[] = 'fecha_sospechosa';
        } else {
            $iso = $date['iso'];
            $dateDecision = $ctx['date_decision'] ?? null;
            $dateResolved = in_array($dateDecision['action'] ?? null, ['accept', 'correct'], true);
            if (! $dateResolved && ($iso < $ctx['period_from'] || $iso > $ctx['period_to'])) {
                $flags[] = 'fecha_sospechosa';
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

        $isComplex = $filled >= 3
            || (($amounts['venta'] ?? 0) > 0 && (($amounts['cc_in'] ?? 0) > 0 || ($amounts['merca_out'] ?? 0) > 0 || ($amounts['merca_in'] ?? 0) > 0))
            || (
                ($amounts['merca_in'] ?? 0) > 0
                && (($amounts['cc_out'] ?? 0) > 0 || ($amounts['ingresos'] ?? 0) > 0)
                && ($amounts['venta'] ?? 0) > 0
            );

        // Regla inequívoca: CC OUT + ingreso (sin venta/merca compleja) NO es operación compleja.
        $ccWithIncomeOnly = ($amounts['cc_out'] ?? 0) > 0
            && ($amounts['ingresos'] ?? 0) > 0
            && ($amounts['venta'] ?? 0) <= 0
            && ($amounts['merca_in'] ?? 0) <= 0
            && ($amounts['merca_out'] ?? 0) <= 0
            && ($amounts['cc_in'] ?? 0) <= 0;

        if ($ccWithIncomeOnly && $this->rules->hasInterpretationRule('cc_out_with_income')) {
            $flags[] = 'cc_combinado_ingreso';
            $isComplex = false;
        } elseif (($amounts['cc_out'] ?? 0) > 0 && ($amounts['ingresos'] ?? 0) > 0 && ! $isComplex) {
            // Sin regla aún: marcar para revisión pero no forzar rojo por eso solo
            $flags[] = 'cc_combinado_ingreso';
        }

        if ($isComplex) {
            $flags[] = 'operacion_compleja';
        }

        if ($scopeAmbiguous) {
            $flags[] = 'ambito_dudoso';
        }

        if ($ctx['account_def'] === null && $ctx['subcuenta'] !== '' && ! $this->looksLikeClientSubcuenta($ctx['subcuenta'], $cuenta)) {
            $flags[] = 'cuenta_desconocida';
        }

        if (($amounts['cc_in'] ?? 0) > 0 || ($amounts['cc_out'] ?? 0) > 0) {
            $flags[] = 'cc_movimiento';
            if (! $ctx['client'] && $cuenta === 'CC') {
                $flags[] = 'cliente_ambiguo';
            }
        }

        if (($amounts['merca_in'] ?? 0) > 0 || ($amounts['merca_out'] ?? 0) > 0) {
            $flags[] = 'merca_analisis_only';
        }

        // Card payment of statement: expense on bank + reduction of card liability — yellow mapping
        if (in_array($cuenta, ['VISA', 'MC', 'MCMP'], true) || (($amounts['pagos_tc'] ?? 0) > 0)) {
            $flags[] = 'pago_tarjeta_posible';
        }

        $status = ImportReviewStatus::Green;
        if (in_array('operacion_compleja', $flags, true)
            || (in_array('fecha_sospechosa', $flags, true) && ! $ccWithIncomeOnly)
            || in_array('cliente_ambiguo', $flags, true)
            || (($amounts['venta'] ?? 0) > 0 && $filled > 1)
        ) {
            $status = ImportReviewStatus::Red;
        } elseif ($flags !== []) {
            $status = ImportReviewStatus::Yellow;
        }

        // Fecha sospechosa sola → rojo; si viene con otros flags amarillos y no compleja, rojo igual
        if (in_array('fecha_sospechosa', $flags, true) && $status !== ImportReviewStatus::Red) {
            $status = ImportReviewStatus::Red;
        }

        // CC combinado con ingreso (regla inequívoca): Amarillo, interpretable
        if (in_array('cc_combinado_ingreso', $flags, true) && ! in_array('operacion_compleja', $flags, true)) {
            if ($status === ImportReviewStatus::Green) {
                $status = ImportReviewStatus::Yellow;
            }
            if ($status === ImportReviewStatus::Red && ! in_array('fecha_sospechosa', $flags, true) && ! in_array('cliente_ambiguo', $flags, true)) {
                $status = ImportReviewStatus::Yellow;
            }
        }

        // Simple single-leg income/expense with known account → can stay green even with category default
        if ($status === ImportReviewStatus::Yellow
            && count(array_diff($flags, ['ambito_dudoso', 'merca_analisis_only'])) === 0
            && $filled === 1
            && (($amounts['ingresos'] ?? 0) > 0 || ($amounts['egresos'] ?? 0) > 0)
            && $ctx['account_def']
        ) {
            // keep yellow if ambito dudoso else green
            $status = in_array('ambito_dudoso', $flags, true) ? ImportReviewStatus::Yellow : ImportReviewStatus::Green;
        }

        if ($status === ImportReviewStatus::Green && $filled === 1 && $ctx['account_def'] && ! $scopeAmbiguous) {
            $status = ImportReviewStatus::Green;
        }

        return [
            'status' => $status,
            'flags' => array_values(array_unique($flags)),
            'scope' => $scope,
            'scope_ambiguous' => $scopeAmbiguous,
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
    ): array {
        $out = [
            'kind' => 'simple',
            'finance_income' => 0.0,
            'finance_expense' => 0.0,
            'cc_charge' => 0.0,
            'cc_payment' => 0.0,
            'would_generate' => [],
            'notes' => [],
            'components' => null,
        ];

        if ($complexDecision && ! empty($complexDecision['approved'])) {
            $out['kind'] = 'complex_resolved';
            $out['finance_income'] = (float) ($complexDecision['finance_income'] ?? $complexDecision['cobro'] ?? 0);
            $out['finance_expense'] = (float) ($complexDecision['finance_expense'] ?? 0);
            $out['cc_charge'] = (float) ($complexDecision['cc_charge'] ?? 0);
            $out['cc_payment'] = (float) ($complexDecision['cc_payment'] ?? 0);
            $out['components'] = [
                'VENTA' => (float) ($complexDecision['venta'] ?? 0),
                'COBRO' => (float) ($complexDecision['cobro'] ?? 0),
                'CC_CARGO' => $out['cc_charge'],
                'CC_COBRO' => $out['cc_payment'],
                'MERCADERIA_ENTREGADA' => (float) ($complexDecision['merca_out'] ?? 0),
                'MERCADERIA_RECIBIDA' => (float) ($complexDecision['merca_in'] ?? 0),
                'UTILIDAD' => (float) ($complexDecision['utilidad'] ?? 0),
            ];
            foreach ($out['components'] as $label => $val) {
                if ($val > 0.0001) {
                    $out['would_generate'][] = "{$label}: {$val}";
                }
            }
            $out['notes'][] = 'Venta compleja resuelta manualmente (preview; sin importar).';
            $out['notes'][] = 'Mercadería solo análisis — no stock físico.';

            return $out;
        }

        if ($cardDecision) {
            $kind = $cardDecision['kind'] ?? 'purchase';
            if ($kind === 'statement_payment') {
                $out['kind'] = 'card_statement_payment';
                $pay = (float) (($amounts['pagos_tc'] ?? 0) > 0 ? $amounts['pagos_tc'] : ($amounts['egresos'] ?? 0));
                // No finance_expense: no segundo gasto. Transferencia banco→pasivo.
                $out['would_generate'][] = 'Disminuye banco/efectivo '.$pay.' ('.$subcuenta.')';
                $out['would_generate'][] = 'Disminuye pasivo tarjeta '.$pay.' ('.$cuenta.')';
                $out['notes'][] = 'Pago de resumen: no genera nuevo gasto.';

                return $out;
            }
            $out['kind'] = 'card_purchase';
            $out['finance_expense'] = (float) ($amounts['egresos'] ?? 0);
            $out['would_generate'][] = 'Gasto/compra '.$out['finance_expense'];
            $out['would_generate'][] = 'Aumenta pasivo tarjeta ('.($accountDef['name'] ?? $subcuenta).')';
            $out['notes'][] = 'Compra con tarjeta: gasto + pasivo.';

            return $out;
        }

        if (in_array('operacion_compleja', $classification['flags'], true)) {
            $out['kind'] = 'complex';
            $out['notes'][] = 'OPERACIÓN COMPLEJA — revisión manual; no confirmar automáticamente.';
            $out['would_generate'][] = 'Requiere mapping humano (venta/CC/cobro/merca).';
            $out['components'] = [
                'VENTA' => (float) ($amounts['venta'] ?? 0),
                'COBRO' => (float) ($amounts['ingresos'] ?? 0),
                'CC_CARGO' => (float) ($amounts['cc_in'] ?? 0),
                'CC_COBRO' => (float) ($amounts['cc_out'] ?? 0),
                'MERCADERIA_ENTREGADA' => (float) ($amounts['merca_out'] ?? 0),
                'MERCADERIA_RECIBIDA' => (float) ($amounts['merca_in'] ?? 0),
                'UTILIDAD' => (float) ($amounts['ut_ventas'] ?? 0),
            ];

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

        // Auto card suggestion when rule active and no explicit decision yet
        if (in_array('pago_tarjeta_posible', $classification['flags'], true)
            && $this->rules->hasInterpretationRule('card_liability_expense')
        ) {
            if (($amounts['pagos_tc'] ?? 0) > 0 || (in_array($cuenta, ['VISA', 'MC', 'MCMP'], true) && ($amounts['egresos'] ?? 0) <= 0)) {
                $out['kind'] = 'card_statement_payment_preview';
                $pay = (float) (($amounts['pagos_tc'] ?? 0) > 0 ? $amounts['pagos_tc'] : 0);
                $out['would_generate'][] = 'Preview pago resumen '.$pay.' (sin segundo gasto) — confirmar en resolución';
                $out['notes'][] = 'Sugerido: pago de resumen. Confirmar en UI de tarjetas.';

                return $out;
            }
            if (($amounts['egresos'] ?? 0) > 0) {
                $out['kind'] = 'card_purchase_preview';
                $out['finance_expense'] = (float) $amounts['egresos'];
                $out['would_generate'][] = 'Preview compra tarjeta '.$out['finance_expense'].' + pasivo';
                $out['notes'][] = 'Sugerido: compra con tarjeta. Confirmar en UI de tarjetas.';

                return $out;
            }
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
                $out['would_generate'][] = 'CC cobro/crédito '.$amounts['cc_out'].' sin ingreso financiero explícito';
            }
        }
        if (($amounts['merca_in'] ?? 0) > 0 || ($amounts['merca_out'] ?? 0) > 0) {
            $out['notes'][] = 'Merca IN/OUT solo análisis — no stock de apertura.';
        }

        return $out;
    }

    /**
     * @return array{ok:bool,iso:?string,error:?string}
     */
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
