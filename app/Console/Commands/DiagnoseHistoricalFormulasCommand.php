<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Diagnóstico global de fórmulas Excel (SOLO lectura; no repara otros clientes).
 */
class DiagnoseHistoricalFormulasCommand extends Command
{
    protected $signature = 'imports:diagnose-historical-formulas
                            {path : Ruta al Excel GASTOS MENSUALES}
                            {--sheet=Movimientos}';

    protected $description = 'Diagnóstico global: filas con fórmula vs zero-skip potencial (sin repair)';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->error('Archivo no encontrado: '.$path);

            return self::FAILURE;
        }

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName((string) $this->option('sheet'))
            ?? $spreadsheet->getActiveSheet();

        // Mirror 11E: no calculate full sheet; formulas read as strings → num 0.
        $matrix = $sheet->toArray(null, false, false, false);
        $start = max(0, ((int) config('historical_import.movements_data_start_row', 4)) - 1);

        $amountCols = [
            'ingresos' => 4,
            'egresos' => 5,
            'cc_in' => 7,
            'cc_out' => 8,
            'merca_in' => 10,
            'merca_out' => 11,
            'venta' => 12,
            'utilidad' => 13,
        ];

        $formulaRows = 0;
        $zeroSkipFormulaRows = 0;
        $clients = [];
        $omitted = 0.0;
        $details = [];

        for ($ri = $start; $ri < count($matrix); $ri++) {
            $rowNum = $ri + 1;
            $concepto = (string) ($matrix[$ri][1] ?? '');
            $subcuenta = (string) ($matrix[$ri][3] ?? '');
            $rowHasFormula = false;
            $rowZeroSkip = false;
            $rowOmitted = 0.0;

            foreach ($amountCols as $name => $ci) {
                $raw = $matrix[$ri][$ci] ?? null;
                if (! is_string($raw) || ! str_starts_with(ltrim($raw), '=')) {
                    continue;
                }
                $rowHasFormula = true;
                $calc = null;
                try {
                    $calc = $sheet->getCell([$ci + 1, $rowNum])->getCalculatedValue();
                } catch (Throwable) {
                    $calc = null;
                }
                $used = 0.0; // 11E with calculateFormulas=false
                if (is_numeric($calc) && abs((float) $calc) > 0.0001 && abs($used) < 0.0001) {
                    $rowZeroSkip = true;
                    if (in_array($name, ['ingresos', 'egresos', 'cc_in', 'cc_out'], true)) {
                        $rowOmitted += abs((float) $calc);
                    }
                }
            }

            if ($rowHasFormula) {
                $formulaRows++;
            }
            if ($rowZeroSkip) {
                $zeroSkipFormulaRows++;
                $omitted += $rowOmitted;
                $clientKey = $subcuenta !== '' ? $subcuenta : $concepto;
                $clients[$clientKey] = ($clients[$clientKey] ?? 0) + 1;
                if (count($details) < 80) {
                    $details[] = [
                        'row' => $rowNum,
                        'concepto' => $concepto,
                        'subcuenta' => $subcuenta,
                        'omitted_finance_cc_abs' => $rowOmitted,
                    ];
                }
            }
        }

        $spreadsheet->disconnectWorksheets();

        $report = [
            'excel' => $path,
            'formula_rows' => $formulaRows,
            'zero_skip_formula_rows' => $zeroSkipFormulaRows,
            'clients_affected_approx' => count($clients),
            'clients_top' => collect($clients)->sortDesc()->take(30)->all(),
            'potential_omitted_finance_cc_abs_sum' => round($omitted, 2),
            'sample_rows' => $details,
            'note' => 'SOLO diagnóstico. NO reparar otros clientes automáticamente.',
        ];

        $out = storage_path('app/reports/HISTORICAL_FORMULA_DIAGNOSTIC_GLOBAL.json');
        if (! is_dir(dirname($out))) {
            mkdir(dirname($out), 0755, true);
        }
        file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info('Fórmula rows: '.$formulaRows);
        $this->info('Zero-skip fórmula rows: '.$zeroSkipFormulaRows);
        $this->info('Clientes afectados (aprox): '.count($clients));
        $this->info('Omitido potencial finance/CC abs: '.round($omitted, 2));
        $this->line('Reporte: '.$out);

        return self::SUCCESS;
    }
}
