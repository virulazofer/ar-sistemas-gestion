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
                'masters' => $preview['masters'],
                'subscriptions_detected' => $preview['subscriptions_detected'],
                'rows_sample_green' => array_slice($preview['rows_by_status']['green'], 0, 30),
                'rows_sample_yellow' => array_slice($preview['rows_by_status']['yellow'], 0, 40),
                'rows_sample_red' => array_slice($preview['rows_by_status']['red'], 0, 60),
                'rows_all_path' => $preview['rows_all_path'],
                'confirm_blocked' => true,
                'confirm_blocked_reason' => 'Etapa 11E-1: confirmación definitiva deshabilitada hasta autorización expresa.',
            ],
            'classification_summary' => $preview['summary'],
            'reconciliation_payload' => $preview['reconciliation'],
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
     * @param  array{holders: list<array>, accounts: list<array>}  $accountMasters
     * @return array<string, mixed>
     */
    private function buildPreview(
        string $path,
        string $cutoverDate,
        string $periodFrom,
        string $periodTo,
        array $accountMasters,
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

            $accountDef = $this->accounts->resolveAlias($subcuenta);
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
            ]);

            $rowHash = hash('sha256', implode('|', [
                $sheetName, $sourceRow, (string) $fechaRaw, $concepto, $cuenta, $subcuenta,
                json_encode($amounts),
            ]));

            $interpreted = $this->interpret($classification, $cuenta, $subcuenta, $amounts, $accountDef, $client);

            if (strcasecmp($cuenta, 'Abonos') === 0 && $client) {
                $subscriptionBuckets[$client] = ($subscriptionBuckets[$client] ?? 0) + 1;
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
                'flags' => $classification['flags'],
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

        $allPath = 'imports/previews/'.Str::uuid().'.json';
        Storage::disk('local')->put($allPath, json_encode([
            'summary' => $summary,
            'rows' => $rows,
        ], JSON_UNESCAPED_UNICODE));

        return [
            'summary' => $summary,
            'reconciliation' => $reconciliation,
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
            if ($iso < $ctx['period_from'] || $iso > $ctx['period_to']) {
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
            || (($amounts['cc_out'] ?? 0) > 0 && ($amounts['ingresos'] ?? 0) > 0);

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
            || in_array('fecha_sospechosa', $flags, true)
            || in_array('cliente_ambiguo', $flags, true)
            || (($amounts['venta'] ?? 0) > 0 && $filled > 1)
        ) {
            $status = ImportReviewStatus::Red;
        } elseif ($flags !== []) {
            $status = ImportReviewStatus::Yellow;
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
     * @return array<string, mixed>
     */
    private function interpret(
        array $classification,
        string $cuenta,
        string $subcuenta,
        array $amounts,
        ?array $accountDef,
        ?string $client,
    ): array {
        $out = [
            'kind' => 'simple',
            'finance_income' => 0.0,
            'finance_expense' => 0.0,
            'cc_charge' => 0.0,
            'cc_payment' => 0.0,
            'would_generate' => [],
            'notes' => [],
        ];

        if (in_array('operacion_compleja', $classification['flags'], true)) {
            $out['kind'] = 'complex';
            $out['notes'][] = 'OPERACIÓN COMPLEJA — revisión manual; no confirmar automáticamente.';
            $out['would_generate'][] = 'Requiere mapping humano (venta/CC/cobro/merca).';

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
        if ($cuenta === 'CC') {
            return true;
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
