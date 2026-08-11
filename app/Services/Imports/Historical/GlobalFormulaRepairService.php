<?php

namespace App\Services\Imports\Historical;

use App\Enums\MovementType;
use App\Models\Category;
use App\Models\Client;
use App\Models\ClientLedgerEntry;
use App\Models\CommercialCharge;
use App\Models\FinancialAccount;
use App\Models\ImportBatch;
use App\Models\Movement;
use App\Models\Receipt;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Clients\ClientLedgerService;
use App\Services\Finance\MovementService;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;
use Throwable;

/**
 * ETAPA 11E-R — reparación global controlada de fórmulas omitidas (idempotente).
 * No reimporta 11E. No toca DAASA (solo validación lectura). Auto-aplica solo VERDE_REPAIR.
 */
class GlobalFormulaRepairService
{
    public const BATCH_NAME = 'GLOBAL_FORMULA_REPAIR_20260811';

    public const REASON = 'stage11e_formula_zero_skip_global_repair';

    public const IMPORT_BATCH_UUID = '6cd0d4ba-6b62-49dc-be85-ee896bbb7d92';

    public const DAASA_BATCH = DaasaPost11F3ReconciliationService::BATCH_NAME;

    /** @var list<int> DAASA hotfix candidate set (read-only validation). */
    public const DAASA_CANDIDATE_ROWS = [
        5, 43, 44, 171, 172, 236, 275, 276, 277, 377, 378, 404, 466, 473, 484, 485,
        501, 502, 503, 505, 512, 532, 635, 636, 637, 772, 773,
    ];

    /** Rows with DAASA commercial repair markers expected on staging. */
    public const DAASA_REPAIRED_ROWS = [404, 484, 485, 503, 505];

    private const AMOUNT_COLS = [
        'ingresos' => 4,
        'egresos' => 5,
        'cc_in' => 7,
        'cc_out' => 8,
        'pagos_tc' => 9,
        'merca_in' => 10,
        'merca_out' => 11,
        'venta' => 12,
        'utilidad' => 13,
    ];

    /** Official 11E zero-skip amount columns (pagos_tc was calculated in preview). */
    private const ZERO_SKIP_COLS = ['ingresos', 'egresos', 'cc_in', 'cc_out', 'merca_in', 'merca_out', 'venta', 'utilidad'];

    public function __construct(
        private readonly MovementService $movements,
        private readonly AccountMappingService $accounts,
        private readonly ClientLedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function classify(string $excelPath, string $sheetName = 'Movimientos', bool $writePreReport = true): array
    {
        if (! is_file($excelPath)) {
            throw new RuntimeException('Excel no encontrado: '.$excelPath);
        }

        $scanned = $this->scanExcel($excelPath, $sheetName);
        $classified = [];
        foreach ($scanned['rows'] as $row) {
            $classified[] = $this->classifyRow($row);
        }

        $byClass = [];
        foreach ($classified as $c) {
            $byClass[$c['classification']] = ($byClass[$c['classification']] ?? 0) + 1;
        }

        $universe = [
            'A_DAASA_REPAIRED' => count(array_filter($classified, fn ($c) => $c['classification'] === 'DAASA_REPAIRED')),
            'B_other_clients' => count(array_filter($classified, fn ($c) => ($c['universe_bucket'] ?? '') === 'B_other_clients')),
            'C_no_client' => count(array_filter($classified, fn ($c) => ($c['universe_bucket'] ?? '') === 'C_no_client')),
            'D_personal' => count(array_filter($classified, fn ($c) => ($c['universe_bucket'] ?? '') === 'D_personal')),
            'E_ZERO_REAL' => count(array_filter($classified, fn ($c) => $c['classification'] === 'ZERO_REAL')),
        ];

        $greens = array_values(array_filter($classified, fn ($c) => $c['classification'] === 'VERDE_REPAIR'));
        $yellows = array_values(array_filter($classified, fn ($c) => $c['classification'] === 'AMARILLO_REVIEW'));
        $reds = array_values(array_filter($classified, fn ($c) => $c['classification'] === 'ROJO_BLOCK'));

        $report = [
            'batch_name' => self::BATCH_NAME,
            'generated_at' => now()->toIso8601String(),
            'excel' => $excelPath,
            'sheet' => $sheetName,
            'import_batch_uuid' => self::IMPORT_BATCH_UUID,
            'scan_counts' => $scanned['counts'],
            'universe_buckets' => $universe,
            'classification_counts' => $byClass,
            'verde_count' => count($greens),
            'amarillo_count' => count($yellows),
            'rojo_count' => count($reds),
            'rows' => $classified,
            'verde_ops' => array_values(array_filter(array_map(fn ($c) => $c['repair_op'] ?? null, $greens))),
            'amarillo_questions' => array_map(fn ($c) => [
                'row' => $c['row'],
                'concepto' => $c['concepto'],
                'rule' => $c['rule'],
                'question' => $c['question'] ?? null,
                'formula_cells' => $c['zero_skip_cells'],
            ], $yellows),
            'rojo_reasons' => array_map(fn ($c) => [
                'row' => $c['row'],
                'concepto' => $c['concepto'],
                'rule' => $c['rule'],
                'reason' => $c['reason'],
            ], $reds),
            'daasa_readonly_check' => $this->daasaReadonlyCheck(),
            'notes' => [
                'Auto-aplicar solo VERDE_REPAIR.',
                'DAASA solo validación lectura (DAASA_POST_11F3_RECONCILIATION_20260811).',
                'No inventar cargos por recurrencia ni segundos ingresos sin documentación.',
                'Universe zero_skip oficial (cols 11E sin pagos_tc) = scan_counts.zero_skip_11e_cols.',
            ],
        ];

        if ($writePreReport) {
            $this->writeReport('GLOBAL_FORMULA_REPAIR_20260811_PRE.json', $report);
        }

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function run(string $excelPath, bool $dryRun = false, string $sheetName = 'Movimientos'): array
    {
        $admin = User::query()->orderBy('id')->first();
        if (! $admin) {
            throw new RuntimeException('Se requiere un usuario para reparar.');
        }
        Auth::login($admin);

        $batch11e = ImportBatch::query()->where('uuid', self::IMPORT_BATCH_UUID)->first();
        if (! $batch11e) {
            throw new RuntimeException('Batch 11E intacto requerido: '.self::IMPORT_BATCH_UUID);
        }

        $pre = $this->classify($excelPath, $sheetName, writePreReport: true);
        $ops = $pre['verde_ops'];

        $balancesBefore = $this->financeBalancesForOps($ops);

        $report = [
            'batch_name' => self::BATCH_NAME,
            'dry_run' => $dryRun,
            'import_batch_uuid' => $batch11e->uuid,
            'pre_classification_counts' => $pre['classification_counts'],
            'universe_buckets' => $pre['universe_buckets'],
            'daasa_readonly_check' => $pre['daasa_readonly_check'],
            'balances_before' => $balancesBefore,
            'planned_verde' => $ops,
            'actions' => [],
            'failed_groups' => [],
            'amarillo_for_user' => $pre['amarillo_questions'],
            'rojo_for_user' => $pre['rojo_reasons'],
        ];

        if ($dryRun) {
            $report['balances_after_simulated'] = $this->simulateFinanceBalances($balancesBefore, $ops);
            $this->writeReport(self::BATCH_NAME.'_dry.json', $report);

            return $report;
        }

        // Group by client_id or finance scope bucket for rollback isolation.
        $groups = [];
        foreach ($ops as $op) {
            $key = $op['client_key'] ?? ('finance:'.($op['scope'] ?? 'na'));
            $groups[$key][] = $op;
        }

        foreach ($groups as $key => $groupOps) {
            try {
                $groupResult = DB::transaction(function () use ($groupOps, $batch11e) {
                    $actions = [];
                    foreach ($groupOps as $op) {
                        $actions[] = $this->applyOp($op, $batch11e);
                    }

                    return $actions;
                });
                foreach ($groupResult as $action) {
                    $report['actions'][] = $action;
                }
            } catch (Throwable $e) {
                $report['failed_groups'][] = [
                    'group' => $key,
                    'error' => $e->getMessage(),
                    'ops' => array_map(fn ($o) => $o['row'], $groupOps),
                ];
            }
        }

        $report['balances_after'] = $this->financeBalancesForOps($ops);
        $report['idempotency_markers'] = $this->countMarkers();
        $report['post_classification_sample'] = [
            'verde_applied_or_skipped' => count($report['actions']),
            'failed_groups' => count($report['failed_groups']),
        ];

        $this->audit->log('global_formula_repair', null, null, [
            'batch' => self::BATCH_NAME,
            'actions' => count($report['actions']),
            'failed_groups' => count($report['failed_groups']),
        ], 'Reparación global fórmulas 11E-R');

        $this->writeReport(self::BATCH_NAME.'_POST.json', $report);

        return $report;
    }

    /**
     * Double-run: apply again; all actions should be idempotent_skip.
     *
     * @return array<string, mixed>
     */
    public function idempotencyCheck(string $excelPath, string $sheetName = 'Movimientos'): array
    {
        // Re-classify: greens already marked should be ALREADY_PRESENT (no ops),
        // or if still VERDE, applyOp must idempotent_skip via external_id.
        $pre = $this->classify($excelPath, $sheetName, writePreReport: false);
        $remainingVerde = count($pre['verde_ops'] ?? []);
        $already = (int) (($pre['classification_counts']['ALREADY_PRESENT'] ?? 0));

        $second = null;
        if ($remainingVerde > 0) {
            $second = $this->run($excelPath, dryRun: false, sheetName: $sheetName);
            $statuses = array_count_values(array_map(fn ($a) => $a['status'] ?? '?', $second['actions'] ?? []));
            $allIdempotent = ($statuses['idempotent_skip'] ?? 0) === count($second['actions'] ?? [])
                && ($second['failed_groups'] ?? []) === [];
        } else {
            $statuses = ['no_verde_remaining' => 1];
            $allIdempotent = true;
        }

        $report = [
            'batch_name' => self::BATCH_NAME,
            'remaining_verde_after_first_pass' => $remainingVerde,
            'already_present_count' => $already,
            'second_run_action_statuses' => $statuses,
            'all_idempotent' => $allIdempotent,
            'actions' => $second['actions'] ?? [],
            'note' => 'Si remaining_verde=0, los markers GLOBAL_FORMULA_REPAIR ya cubren los verdes aplicados.',
        ];
        $this->writeReport(self::BATCH_NAME.'_IDEMPOTENCY.json', $report);

        return $report;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function scanExcel(string $excelPath, string $sheetName = 'Movimientos'): array
    {
        $spreadsheet = IOFactory::load($excelPath);
        $sheet = $spreadsheet->getSheetByName($sheetName) ?? $spreadsheet->getActiveSheet();
        $matrix = $sheet->toArray(null, false, false, false);
        $start = max(0, ((int) config('historical_import.movements_data_start_row', 4)) - 1);

        $rows = [];
        $formulaRows = 0;
        $zeroSkip11e = 0;
        $zeroReal = 0;

        for ($ri = $start; $ri < count($matrix); $ri++) {
            $rowNum = $ri + 1;
            $fecha = $matrix[$ri][0] ?? null;
            $concepto = (string) ($matrix[$ri][1] ?? '');
            $cuenta = (string) ($matrix[$ri][2] ?? '');
            $subcuenta = (string) ($matrix[$ri][3] ?? '');
            $cells = [];
            $hasFormula = false;
            $zeroSkip = false;
            $allZero = true;

            foreach (self::AMOUNT_COLS as $name => $ci) {
                $raw = $matrix[$ri][$ci] ?? null;
                if (! is_string($raw) || ! str_starts_with(ltrim($raw), '=')) {
                    continue;
                }
                $hasFormula = true;
                $calc = null;
                $err = null;
                try {
                    $calc = $sheet->getCell([$ci + 1, $rowNum])->getCalculatedValue();
                } catch (Throwable $e) {
                    $err = $e->getMessage();
                }
                $calcNum = is_numeric($calc) ? (float) $calc : null;
                $skip = $calcNum !== null && abs($calcNum) > 0.0001;
                if ($skip) {
                    $zeroSkip = true;
                    $allZero = false;
                } elseif ($calcNum === null) {
                    $allZero = false;
                }
                $cells[$name] = [
                    'formula' => $raw,
                    'calculated' => $calcNum,
                    'calc_error' => $err,
                    'zero_skip' => $skip,
                ];
            }

            if (! $hasFormula) {
                continue;
            }
            $formulaRows++;

            $zeroSkip11eCols = false;
            foreach (self::ZERO_SKIP_COLS as $col) {
                if (! empty($cells[$col]['zero_skip'])) {
                    $zeroSkip11eCols = true;
                    break;
                }
            }

            if ($zeroSkip11eCols) {
                $zeroSkip11e++;
            } elseif ($allZero) {
                $zeroReal++;
            } else {
                // pagos_tc-only or unresolvable — keep if any zero_skip for audit
                if (! $zeroSkip && ! $allZero) {
                    continue;
                }
            }

            if (! $zeroSkip11eCols && ! $allZero && ! $zeroSkip) {
                continue;
            }

            $date = null;
            if (is_numeric($fecha)) {
                try {
                    $date = ExcelDate::excelToDateTimeObject((float) $fecha)->format('Y-m-d');
                } catch (Throwable) {
                    $date = null;
                }
            }

            $rows[] = [
                'row' => $rowNum,
                'date' => $date,
                'concepto' => $concepto,
                'cuenta' => $cuenta,
                'subcuenta' => $subcuenta,
                'cells' => $cells,
                'zero_skip' => $zeroSkip11eCols || $zeroSkip,
                'zero_skip_11e_cols' => $zeroSkip11eCols,
                'formula_all_zero' => $allZero && ! $zeroSkip,
            ];
        }

        $spreadsheet->disconnectWorksheets();

        return [
            'rows' => $rows,
            'counts' => [
                'formula_rows' => $formulaRows,
                'zero_skip_11e_cols' => $zeroSkip11e,
                'zero_real_formula_rows' => $zeroReal,
                'scanned_relevant_rows' => count($rows),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function classifyRow(array $row): array
    {
        $rowNum = (int) $row['row'];
        $concepto = (string) $row['concepto'];
        $cuenta = (string) $row['cuenta'];
        $subcuenta = (string) $row['subcuenta'];
        $cells = $row['cells'];

        $zeroSkipCells = [];
        foreach ($cells as $name => $c) {
            if (! empty($c['zero_skip'])) {
                $zeroSkipCells[$name] = $c;
            }
        }

        $base = [
            'row' => $rowNum,
            'date' => $row['date'],
            'concepto' => $concepto,
            'cuenta' => $cuenta,
            'subcuenta' => $subcuenta,
            'zero_skip_cells' => $zeroSkipCells,
            'excel_said' => array_map(fn ($c) => $c['formula'] ?? null, $cells),
            'formula_calculated' => array_map(fn ($c) => $c['calculated'] ?? null, $cells),
            'used_by_11e' => 0.0,
        ];

        if (! empty($row['formula_all_zero'])) {
            return $base + [
                'classification' => 'ZERO_REAL',
                'universe_bucket' => 'E_ZERO_REAL',
                'rule' => 'formula_calculates_to_zero',
                'reason' => 'Fórmula resuelve a 0 — no hay importe omitido real.',
            ];
        }

        // Presence checks
        if ($this->hasGlobalMarker($rowNum)) {
            return $base + [
                'classification' => 'ALREADY_PRESENT',
                'universe_bucket' => $this->bucketFor($row, null),
                'rule' => 'global_repair_marker',
                'reason' => 'Ya reparado por '.self::BATCH_NAME,
            ];
        }

        if (in_array($rowNum, self::DAASA_REPAIRED_ROWS, true) || $this->hasDaasaMarker($rowNum)) {
            return $base + [
                'classification' => 'DAASA_REPAIRED',
                'universe_bucket' => 'A_DAASA_REPAIRED',
                'rule' => 'daasa_post_11f3',
                'reason' => 'Reparado por '.self::DAASA_BATCH.' — solo validación lectura.',
            ];
        }

        if ($this->isMonthOrSummaryRow($concepto, $subcuenta, $cells)) {
            return $base + [
                'classification' => 'ROJO_BLOCK',
                'universe_bucket' => 'C_no_client',
                'rule' => 'month_or_summary_total',
                'reason' => 'Fila de total/resumen mensual (SUM) — no es operación contable importable.',
            ];
        }

        // pagos_tc-only already handled by preview calculator — if movement exists, ALREADY_PRESENT
        $financeFields = array_values(array_intersect(array_keys($zeroSkipCells), ['ingresos', 'egresos']));
        $ccFields = array_values(array_intersect(array_keys($zeroSkipCells), ['cc_in', 'cc_out']));
        $analysisFields = array_values(array_intersect(array_keys($zeroSkipCells), ['merca_in', 'merca_out', 'venta', 'utilidad']));
        $pagosFields = array_values(array_intersect(array_keys($zeroSkipCells), ['pagos_tc']));

        $movExists = Schema::hasTable('movements') && Movement::query()
            ->where('source_sheet', 'Movimientos')
            ->where('source_row', $rowNum)
            ->where('status', 'posted')
            ->exists();

        if ($movExists && $financeFields === [] && $ccFields === []) {
            return $base + [
                'classification' => 'ALREADY_PRESENT',
                'universe_bucket' => $this->bucketFor($row, null),
                'rule' => 'movement_source_row_exists',
                'reason' => 'Ya hay movement(s) 11E para esta fila; fórmulas restantes son analysis-only o pagos_tc ya resuelto.',
            ];
        }

        if ($pagosFields !== [] && $financeFields === [] && $ccFields === [] && $analysisFields === []) {
            return $base + [
                'classification' => $movExists ? 'ALREADY_PRESENT' : 'AMARILLO_REVIEW',
                'universe_bucket' => 'C_no_client',
                'rule' => 'pagos_tc_only',
                'reason' => $movExists
                    ? 'pagos_tc ya importado (preview calculaba esa columna).'
                    : 'Solo pagos_tc con fórmula; revisar si el transfer de tarjeta falta.',
                'question' => $movExists ? null : '¿Falta el pago de resumen de tarjeta en esta fila?',
            ];
        }

        // Analysis-only (no finance/CC omitido)
        if ($financeFields === [] && $ccFields === [] && $analysisFields !== []) {
            $clientHint = $this->clientHint($concepto, $subcuenta);
            if ($this->hasClientLedgerForConcept($concepto) || $movExists) {
                return $base + [
                    'classification' => 'ALREADY_PRESENT',
                    'universe_bucket' => $clientHint ? 'B_other_clients' : 'C_no_client',
                    'rule' => 'analysis_only_already_imported',
                    'reason' => 'Solo merca/venta/utilidad en fórmula; CC/caja ya presente o no aplicable. No inventar stock.',
                ];
            }

            return $base + [
                'classification' => 'ROJO_BLOCK',
                'universe_bucket' => $clientHint ? 'B_other_clients' : 'C_no_client',
                'rule' => 'analysis_only_no_finance_cc',
                'reason' => 'Solo columnas económicas (merca/venta/utilidad). No inventar stock ni cobro desde utilidad.',
            ];
        }

        // CC fields
        if ($ccFields !== []) {
            return $this->classifyCcRow($base, $row, $ccFields, $financeFields, $analysisFields);
        }

        // Finance ingresos/egresos
        if ($financeFields !== []) {
            return $this->classifyFinanceRow($base, $row, $financeFields);
        }

        return $base + [
            'classification' => 'ROJO_BLOCK',
            'universe_bucket' => 'C_no_client',
            'rule' => 'unclassified',
            'reason' => 'No hay regla segura de reparación.',
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $row
     * @param  list<string>  $ccFields
     * @param  list<string>  $financeFields
     * @param  list<string>  $analysisFields
     * @return array<string, mixed>
     */
    private function classifyCcRow(array $base, array $row, array $ccFields, array $financeFields, array $analysisFields): array
    {
        $rowNum = (int) $row['row'];
        $concepto = (string) $row['concepto'];
        $subcuenta = (string) $row['subcuenta'];
        $clientHint = $this->clientHint($concepto, $subcuenta);
        $isPersonal = $this->isPersonalCategory((string) $row['cuenta']);

        if ($isPersonal && in_array('cc_in', $ccFields, true)) {
            return $base + [
                'classification' => 'ROJO_BLOCK',
                'universe_bucket' => 'D_personal',
                'rule' => 'personal_cc_in_suspicious',
                'reason' => 'CC IN en categoría personal (posible SUM mal ubicado). No auto-reparar.',
            ];
        }

        if (in_array($rowNum, self::DAASA_CANDIDATE_ROWS, true) || $this->looksLikeDaasa($concepto, $subcuenta)) {
            return $base + [
                'classification' => 'DAASA_REPAIRED',
                'universe_bucket' => 'A_DAASA_REPAIRED',
                'rule' => 'daasa_candidate_readonly',
                'reason' => 'Fila DAASA: no re-tocar. Validar solo contra hotfix previo.',
            ];
        }

        if (! $clientHint) {
            return $base + [
                'classification' => 'AMARILLO_REVIEW',
                'universe_bucket' => 'C_no_client',
                'rule' => 'cc_without_resolvable_client',
                'question' => 'Fila '.$rowNum.': CC con fórmula pero sin cliente resoluble. ¿Qué cliente aplica y es cargo o cobro?',
                'reason' => 'No inventar cliente ni cargo CC sin confirmación.',
            ];
        }

        $client = $this->findClient($clientHint);
        if (! $client) {
            return $base + [
                'classification' => 'AMARILLO_REVIEW',
                'universe_bucket' => 'B_other_clients',
                'rule' => 'cc_client_missing_in_db',
                'question' => 'Fila '.$rowNum.' ('.$concepto.'): ¿crear cliente "'.$clientHint.'" y cargo/cobro CC por el importe de la fórmula?',
                'reason' => 'Cliente no existe en staging; no auto-crear.',
            ];
        }

        // Unequivocal CC with known client — still require pair clarity; single cc_in sale-like can be green
        // if formula is simple multiply/add and cuenta is Ventas/CC.
        $field = $ccFields[0];
        $cell = $row['cells'][$field];
        $amount = abs((float) $cell['calculated']);
        $formula = (string) $cell['formula'];

        if (! $this->isSimpleArithmeticFormula($formula)) {
            return $base + [
                'classification' => 'AMARILLO_REVIEW',
                'universe_bucket' => 'B_other_clients',
                'rule' => 'cc_formula_not_simple',
                'question' => 'Fila '.$rowNum.': fórmula CC no trivial ('.$formula.'). ¿Confirmar importe '.$amount.' para '.$client->name.'?',
                'reason' => 'Fórmula compleja — revisar.',
            ];
        }

        // Policy: do not auto-create commercial charges for non-DAASA without prior user-confirmed pattern.
        // Charly/others go yellow even if client exists — historical mess warning.
        return $base + [
            'classification' => 'AMARILLO_REVIEW',
            'universe_bucket' => 'B_other_clients',
            'rule' => 'cc_needs_user_ack_non_daasa',
            'question' => 'Fila '.$rowNum.' cliente '.$client->name.': ¿aplicar '.$field.' = '.$amount.' (fórmula '.$formula.') como cargo/cobro 11F-3 sin inventar caja?',
            'reason' => 'CC de otros clientes requiere confirmación (no forzar cierre histórico).',
            'suggested_op' => [
                'row' => $rowNum,
                'op' => $field === 'cc_in' ? 'charge_cc_in' : 'cc_out_no_finance',
                'client_id' => $client->id,
                'client_name' => $client->name,
                'amount' => number_format($amount, 2, '.', ''),
                'formula' => $formula,
                'field' => $field,
                'date' => $row['date'],
                'concepto' => $concepto,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $row
     * @param  list<string>  $financeFields
     * @return array<string, mixed>
     */
    private function classifyFinanceRow(array $base, array $row, array $financeFields): array
    {
        $rowNum = (int) $row['row'];
        $concepto = (string) $row['concepto'];
        $cuenta = (string) $row['cuenta'];
        $subcuenta = (string) $row['subcuenta'];
        $isPersonal = $this->isPersonalCategory($cuenta);
        $bucket = $isPersonal ? 'D_personal' : 'C_no_client';
        $clientHint = $this->clientHint($concepto, $subcuenta);
        if ($clientHint && ! $isPersonal) {
            $bucket = 'B_other_clients';
        }

        if (Schema::hasTable('movements')
            && Movement::query()->where('source_sheet', 'Movimientos')->where('source_row', $rowNum)->where('status', 'posted')->exists()) {
            // Partial: finance may still be missing if only analysis was imported — check type
            $field = $financeFields[0];
            $wantType = $field === 'ingresos' ? 'income' : 'expense';
            $hasType = Movement::query()
                ->where('source_sheet', 'Movimientos')
                ->where('source_row', $rowNum)
                ->where('type', $wantType)
                ->where('status', 'posted')
                ->exists();
            if ($hasType) {
                return $base + [
                    'classification' => 'ALREADY_PRESENT',
                    'universe_bucket' => $bucket,
                    'rule' => 'finance_movement_already_present',
                    'reason' => 'Movement '.$wantType.' ya existe para source_row='.$rowNum,
                ];
            }
        }

        // One primary finance field (if both, yellow)
        if (count($financeFields) > 1) {
            return $base + [
                'classification' => 'AMARILLO_REVIEW',
                'universe_bucket' => $bucket,
                'rule' => 'both_ingresos_egresos_formula',
                'question' => 'Fila '.$rowNum.': fórmulas en ingresos y egresos. ¿Cuál aplicar?',
                'reason' => 'Ambigüedad de dirección financiera.',
            ];
        }

        $field = $financeFields[0];
        $cell = $row['cells'][$field];
        $amount = abs((float) $cell['calculated']);
        $formula = (string) $cell['formula'];

        if ($cell['calculated'] === null || ! empty($cell['calc_error'])) {
            return $base + [
                'classification' => 'ROJO_BLOCK',
                'universe_bucket' => $bucket,
                'rule' => 'unresolvable_formula',
                'reason' => 'No se pudo calcular la fórmula: '.($cell['calc_error'] ?? 'null'),
            ];
        }

        if (! $this->isSimpleArithmeticFormula($formula)) {
            return $base + [
                'classification' => 'AMARILLO_REVIEW',
                'universe_bucket' => $bucket,
                'rule' => 'finance_formula_not_simple',
                'question' => 'Fila '.$rowNum.': fórmula no trivial '.$formula.' → '.$amount.'. ¿Confirmar?',
                'reason' => 'Fórmula compleja.',
            ];
        }

        $accountDef = $this->accounts->resolveAlias($subcuenta);
        if (! $accountDef) {
            return $base + [
                'classification' => 'AMARILLO_REVIEW',
                'universe_bucket' => $bucket,
                'rule' => 'unknown_financial_account',
                'question' => 'Fila '.$rowNum.': subcuenta "'.$subcuenta.'" sin alias financiero. ¿Qué cuenta usar para '.$field.'='.$amount.'?',
                'reason' => 'Cuenta financiera no mapeada.',
            ];
        }

        $currency = strtoupper((string) ($accountDef['currency'] ?? 'ARS'));
        if ($currency !== 'ARS') {
            return $base + [
                'classification' => 'AMARILLO_REVIEW',
                'universe_bucket' => $bucket,
                'rule' => 'non_ars_account_currency',
                'question' => 'Fila '.$rowNum.': cuenta '.$subcuenta.' es '.$currency.'. ¿El importe '.$amount.' está en '.$currency.' o ARS (FX en fórmula)?',
                'reason' => 'Ambigüedad de moneda — no auto-aplicar.',
            ];
        }

        // Intereses registrados como egresos — sospechoso
        if ($field === 'egresos' && str_contains(mb_strtolower($cuenta), 'interes')) {
            return $base + [
                'classification' => 'AMARILLO_REVIEW',
                'universe_bucket' => $bucket,
                'rule' => 'interest_as_expense',
                'question' => 'Fila '.$rowNum.' Intereses como egresos ('.$amount.'). ¿Es egreso real, ingreso, o error de columna Excel?',
                'reason' => 'Semántica de intereses ambigua.',
            ];
        }

        $scope = $isPersonal ? 'personal' : 'professional';
        if (! $isPersonal && ($accountDef['default_scope'] ?? null) === 'personal') {
            $scope = 'personal';
        }
        // Professional income/expense categories
        $catDef = config('historical_import.category_defaults.'.$cuenta, []);
        if (($catDef['default_scope'] ?? '') === 'professional') {
            $scope = 'professional';
        } elseif (($catDef['default_scope'] ?? '') === 'personal') {
            $scope = 'personal';
            $bucket = 'D_personal';
        } elseif (($catDef['default_scope'] ?? '') === 'both') {
            // Impuestos/Servicios: professional when clearly business concepts
            $scope = $this->looksProfessionalConcept($concepto, $cuenta) ? 'professional' : 'personal';
        }

        $opType = $field === 'ingresos' ? 'finance_income' : 'finance_expense';

        return $base + [
            'classification' => 'VERDE_REPAIR',
            'universe_bucket' => $bucket,
            'rule' => 'unequivocal_finance_formula',
            'reason' => 'Fórmula inequívoca + cuenta ARS conocida + sin movement '.$field.'; no inventa CC ni segundo cobro.',
            'repair_op' => [
                'row' => $rowNum,
                'op' => $opType,
                'field' => $field,
                'amount' => number_format($amount, 2, '.', ''),
                'formula' => $formula,
                'account_alias' => $subcuenta,
                'category' => $cuenta,
                'scope' => $scope,
                'date' => $row['date'],
                'concepto' => $concepto !== '' ? $concepto : ('Reparación fórmula fila '.$rowNum),
                'client_key' => 'finance:'.$scope,
                'client_hint' => $clientHint,
                'excel_said' => $formula,
                'formula_y' => $amount,
                'approved_z' => $amount,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function applyOp(array $op, ImportBatch $batch11e): array
    {
        $marker = $this->marker((int) $op['row'], (string) $op['field']);
        $externalId = 'global_formula_repair:20260811:row:'.$op['row'].':'.$op['field'];

        $existing = Movement::query()->where('external_id', $externalId)->first();
        if ($existing) {
            return ['action' => $op['op'], 'status' => 'idempotent_skip', 'source_row' => $op['row'], 'movement_id' => $existing->id];
        }

        // Also skip if same source_row+type already posted from 11E
        $type = $op['op'] === 'finance_income' ? MovementType::Income->value : MovementType::Expense->value;
        $already = Movement::query()
            ->where('source_sheet', 'Movimientos')
            ->where('source_row', $op['row'])
            ->where('type', $type)
            ->where('status', 'posted')
            ->first();
        if ($already) {
            return ['action' => $op['op'], 'status' => 'idempotent_skip', 'source_row' => $op['row'], 'movement_id' => $already->id, 'reason' => 'source_row_type_exists'];
        }

        $def = $this->accounts->resolveAlias((string) $op['account_alias']);
        if (! $def) {
            throw new RuntimeException('Alias financiero faltante: '.$op['account_alias']);
        }
        $account = $this->accounts->ensureAccountFromDef($def['_matched_alias'] ?? $op['account_alias'], $def);
        $category = $this->ensureCategory((string) $op['category'], (string) $op['scope']);

        $clientId = null;
        if (! empty($op['client_hint'])) {
            $client = $this->findClient((string) $op['client_hint']);
            $clientId = $client?->id;
        }

        $mov = $this->movements->createSimple([
            'type' => $type,
            'scope' => $op['scope'],
            'financial_account_id' => $account->id,
            'amount' => $op['amount'],
            'movement_date' => $op['date'] ?? now()->toDateString(),
            'category_id' => $category?->id,
            'description' => (string) $op['concepto'],
            'client_id' => $clientId,
            'import_batch_id' => $batch11e->id,
            'external_id' => $externalId,
            'source_sheet' => 'Movimientos',
            'source_row' => $op['row'],
            'source_payload' => [
                'repair_batch' => self::BATCH_NAME,
                'marker' => $marker,
                'reason' => self::REASON,
                'excel_said' => $op['excel_said'] ?? $op['formula'],
                'formula_y' => $op['formula'],
                'calculated' => $op['amount'],
                'approved_z' => $op['approved_z'] ?? $op['amount'],
                'original_11e_batch' => $batch11e->uuid,
                'used_by_11e' => 0,
            ],
        ]);

        return [
            'action' => $op['op'],
            'status' => 'created',
            'source_row' => $op['row'],
            'movement_id' => $mov->id,
            'amount' => $op['amount'],
            'formula' => $op['formula'],
            'account_id' => $account->id,
            'scope' => $op['scope'],
            'marker' => $marker,
            'reason' => self::REASON,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function daasaReadonlyCheck(): array
    {
        if (! Schema::hasTable('commercial_charges') || ! Schema::hasTable('clients')) {
            return ['ok' => false, 'error' => 'tables_missing_local', 'skipped' => true];
        }

        $client = Client::query()->where('code', 3)->where('name', 'like', '%DAASA%')->first();
        if (! $client) {
            return ['ok' => false, 'error' => 'DAASA not found'];
        }

        $charges = CommercialCharge::query()
            ->where('client_id', $client->id)
            ->where('notes', 'like', '%'.self::DAASA_BATCH.'%')
            ->get(['id', 'number', 'concept', 'amount', 'notes']);

        $receipts = Receipt::query()
            ->where('client_id', $client->id)
            ->where('notes', 'like', '%'.self::DAASA_BATCH.'%')
            ->count();

        $balance = $this->ledger->balanceFor($client, 'ARS');
        $expectedRows = self::DAASA_REPAIRED_ROWS;
        $foundRows = [];
        foreach ($charges as $ch) {
            if (preg_match('/row:(\d+):/', (string) $ch->notes, $m)) {
                $foundRows[] = (int) $m[1];
            }
        }
        foreach (Receipt::query()->where('client_id', $client->id)->where('notes', 'like', '%'.self::DAASA_BATCH.'%')->get(['notes']) as $rc) {
            if (preg_match('/row:(\d+):/', (string) $rc->notes, $m)) {
                $foundRows[] = (int) $m[1];
            }
        }
        $foundRows = array_values(array_unique($foundRows));

        return [
            'ok' => true,
            'client_id' => $client->id,
            'balance_ars_signed' => $balance,
            'repair_charges' => $charges->count(),
            'repair_receipts' => $receipts,
            'expected_repaired_rows' => $expectedRows,
            'found_marker_rows' => $foundRows,
            'untouched_by_global' => CommercialCharge::query()->where('notes', 'like', '%'.self::BATCH_NAME.'%')->where('client_id', $client->id)->count() === 0,
            'note' => 'Read-only: global repair must not write DAASA markers.',
        ];
    }

    private function marker(int $row, string $field): string
    {
        return self::BATCH_NAME.':row:'.$row.':'.$field;
    }

    private function hasGlobalMarker(int $row): bool
    {
        $m = self::BATCH_NAME.':row:'.$row.':';
        if (Schema::hasTable('movements')
            && Movement::query()->where('external_id', 'like', 'global_formula_repair:20260811:row:'.$row.':%')->exists()) {
            return true;
        }
        if (Schema::hasTable('commercial_charges')
            && CommercialCharge::query()->where('notes', 'like', '%'.$m.'%')->exists()) {
            return true;
        }

        return Schema::hasTable('receipts')
            && Receipt::query()->where('notes', 'like', '%'.$m.'%')->exists();
    }

    private function hasDaasaMarker(int $row): bool
    {
        if (! Schema::hasTable('commercial_charges') && ! Schema::hasTable('receipts')) {
            return false;
        }
        $m = self::DAASA_BATCH.':row:'.$row.':';
        if (Schema::hasTable('commercial_charges')
            && CommercialCharge::query()->where('notes', 'like', '%'.$m.'%')->exists()) {
            return true;
        }

        return Schema::hasTable('receipts')
            && Receipt::query()->where('notes', 'like', '%'.$m.'%')->exists();
    }

    private function hasClientLedgerForConcept(string $concepto): bool
    {
        $concepto = trim($concepto);
        if ($concepto === '' || ! Schema::hasTable('client_ledger_entries')) {
            return false;
        }

        return ClientLedgerEntry::query()
            ->where('description', 'like', '%'.mb_substr($concepto, 0, 40).'%')
            ->where('status', 'posted')
            ->exists();
    }

    private function isMonthOrSummaryRow(string $concepto, string $subcuenta, array $cells): bool
    {
        $sub = trim($subcuenta);
        $months = 'ENERO|FEBRERO|MARZO|ABRIL|MAYO|JUNIO|JULIO|AGOSTO|SEPTIEMBRE|OCTUBRE|NOVIEMBRE|DICIEMBRE|Saldo Inicial';
        if (preg_match('/^('.$months.')$/iu', $sub)) {
            return true;
        }
        if (trim($concepto) === '' && $sub === '') {
            foreach ($cells as $c) {
                $f = (string) ($c['formula'] ?? '');
                if (preg_match('/^=\s*SUM\s*\(/i', $f)) {
                    return true;
                }
            }
        }
        // Opening merca saldo
        if (str_contains(mb_strtolower($concepto), 'saldo de mercader')) {
            return true;
        }

        return false;
    }

    private function isPersonalCategory(string $cuenta): bool
    {
        $def = config('historical_import.category_defaults.'.$cuenta, []);

        return ($def['default_scope'] ?? '') === 'personal';
    }

    private function looksLikeDaasa(string $concepto, string $subcuenta): bool
    {
        $hay = mb_strtolower($concepto.' '.$subcuenta);

        return str_contains($hay, 'daasa') || str_contains($hay, 'hugo ferreyra');
    }

    private function clientHint(string $concepto, string $subcuenta): ?string
    {
        $aliases = config('historical_import.client_known_aliases', []);
        $hay = mb_strtolower(trim($concepto.' '.$subcuenta));
        foreach ($aliases as $alias => $canonical) {
            if (str_contains($hay, mb_strtolower((string) $alias))) {
                return (string) $canonical;
            }
        }
        foreach (['charly', 'alejandro', 'andrea balduzzi', 'superaccion', 'superacción'] as $k) {
            if (str_contains($hay, $k)) {
                return $k === 'superacción' ? 'Superaccion' : ucwords($k);
            }
        }
        // Subcuenta that is a known client name
        foreach (Client::query()->pluck('name') as $name) {
            if ($name !== '' && (strcasecmp($subcuenta, $name) === 0 || str_contains($hay, mb_strtolower($name)))) {
                return $name;
            }
        }

        return null;
    }

    private function findClient(string $hint): ?Client
    {
        $hint = trim($hint);
        if ($hint === '') {
            return null;
        }
        $aliases = config('historical_import.client_known_aliases', []);
        $key = mb_strtolower($hint);
        if (isset($aliases[$key])) {
            $hint = $aliases[$key];
        }

        if (! Schema::hasTable('clients')) {
            return null;
        }

        return Client::query()->where('name', $hint)->orWhere('name', 'like', $hint.'%')->orderBy('id')->first();
    }

    private function isSimpleArithmeticFormula(string $formula): bool
    {
        $f = strtoupper(str_replace(' ', '', $formula));
        if (! str_starts_with($f, '=')) {
            return false;
        }
        // Allow digits, operators, dots, parentheses, commas — reject cell refs like A1 or SUM(
        if (preg_match('/[A-Z]+\d+/', $f)) {
            return false;
        }
        if (str_contains($f, 'SUM(') || str_contains($f, 'SUMIF') || str_contains($f, 'IF(')) {
            return false;
        }

        return (bool) preg_match('/^=[0-9.+\-*\/(),]+$/', $f);
    }

    private function looksProfessionalConcept(string $concepto, string $cuenta): bool
    {
        $c = mb_strtolower($concepto.' '.$cuenta);
        foreach (['teamviewer', 'cursor', 'iibb', 'retencion', 'youtube', 'licencia', 'servicio'] as $k) {
            if (str_contains($c, $k)) {
                return true;
            }
        }

        return in_array($cuenta, ['Servicios', 'Impuestos', 'Intereses ganados'], true);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function bucketFor(array $row, ?string $forced): string
    {
        if ($forced) {
            return $forced;
        }
        if ($this->isPersonalCategory((string) $row['cuenta'])) {
            return 'D_personal';
        }
        if ($this->clientHint((string) $row['concepto'], (string) $row['subcuenta'])) {
            return 'B_other_clients';
        }

        return 'C_no_client';
    }

    private function ensureCategory(string $name, string $scope): ?Category
    {
        $name = trim($name);
        if ($name === '' || strcasecmp($name, 'CC') === 0) {
            return null;
        }
        $defaults = config('historical_import.category_defaults.'.$name, []);

        return Category::query()->firstOrCreate(
            ['excel_name' => $name],
            [
                'name' => $name,
                'scope' => $scope === 'professional' ? 'professional' : 'personal',
                'default_scope' => $defaults['default_scope'] ?? $scope,
                'is_active' => true,
                'sort_order' => 100,
            ]
        );
    }

    /**
     * @param  list<array<string, mixed>>  $ops
     * @return array<string, string>
     */
    private function financeBalancesForOps(array $ops): array
    {
        $aliases = [];
        foreach ($ops as $op) {
            $aliases[(string) $op['account_alias']] = true;
        }
        $out = [];
        foreach (array_keys($aliases) as $alias) {
            $def = $this->accounts->resolveAlias($alias);
            if (! $def) {
                $out[$alias] = 'n/a';
                continue;
            }
            $matched = $def['_matched_alias'] ?? $alias;
            $name = $def['name'] ?? $alias;
            $acc = FinancialAccount::query()
                ->where(function ($q) use ($matched, $name) {
                    $q->where('alias', $matched)->orWhere('name', $name);
                })
                ->first();
            $out[$alias] = $acc ? (string) $acc->cached_balance : 'missing';
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $before
     * @param  list<array<string, mixed>>  $ops
     * @return array<string, string>
     */
    private function simulateFinanceBalances(array $before, array $ops): array
    {
        $sim = $before;
        foreach ($ops as $op) {
            $alias = (string) $op['account_alias'];
            $bal = $sim[$alias] ?? '0.00';
            if ($bal === 'n/a' || $bal === 'missing') {
                continue;
            }
            if (($op['op'] ?? '') === 'finance_expense') {
                $sim[$alias] = Money::sub($bal, (string) $op['amount']);
            } else {
                $sim[$alias] = Money::add($bal, (string) $op['amount']);
            }
        }

        return $sim;
    }

    /**
     * @return array<string, int>
     */
    private function countMarkers(): array
    {
        return [
            'movements' => Movement::query()->where('external_id', 'like', 'global_formula_repair:20260811:%')->count(),
            'charges' => CommercialCharge::query()->where('notes', 'like', '%'.self::BATCH_NAME.'%')->count(),
            'receipts' => Receipt::query()->where('notes', 'like', '%'.self::BATCH_NAME.'%')->count(),
            'daasa_charges_untouched_check' => CommercialCharge::query()->where('notes', 'like', '%'.self::DAASA_BATCH.'%')->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeReport(string $filename, array $report): void
    {
        $dir = storage_path('app/reports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir.DIRECTORY_SEPARATOR.$filename, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
