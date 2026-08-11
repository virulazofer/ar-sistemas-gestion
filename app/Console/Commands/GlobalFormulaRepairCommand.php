<?php

namespace App\Console\Commands;

use App\Services\Imports\Historical\GlobalFormulaRepairService;
use Illuminate\Console\Command;

class GlobalFormulaRepairCommand extends Command
{
    protected $signature = 'imports:repair-global-formulas
                            {path? : Ruta al Excel GASTOS MENSUALES}
                            {--sheet=Movimientos}
                            {--classify-only : Solo clasificación / pre-report (sin escribir)}
                            {--dry-run : Planificar VERDE + simular balances (sin escribir)}
                            {--apply-green : Aplicar VERDE_REPAIR (idempotente)}
                            {--idempotency-check : Segunda pasada; debe ser todo idempotent_skip}';

    protected $description = 'ETAPA 11E-R: reparación global controlada de fórmulas omitidas (solo VERDE auto)';

    public function handle(GlobalFormulaRepairService $service): int
    {
        $path = (string) ($this->argument('path')
            ?: env('HISTORICAL_EXCEL_PATH')
            ?: storage_path('app/imports/GASTOS_MENSUALES_2026.xlsx'));

        if (! is_file($path)) {
            $this->error('Excel no encontrado: '.$path);

            return self::FAILURE;
        }

        $sheet = (string) $this->option('sheet');

        if ($this->option('classify-only')) {
            $this->info('Clasificando universo…');
            $report = $service->classify($path, $sheet);
            $this->printClassificationSummary($report);
            $this->info('Pre-report: '.storage_path('app/reports/GLOBAL_FORMULA_REPAIR_20260811_PRE.json'));

            return self::SUCCESS;
        }

        if ($this->option('idempotency-check')) {
            $this->info('Idempotency check (segunda pasada)…');
            $report = $service->idempotencyCheck($path, $sheet);
            $this->line(json_encode($report['second_run_action_statuses'] ?? [], JSON_PRETTY_PRINT));
            $this->info(($report['all_idempotent'] ?? false) ? 'OK: todo idempotent_skip' : 'FAIL: no todo idempotente');

            return ($report['all_idempotent'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run') || ! $this->option('apply-green');
        if (! $this->option('apply-green') && ! $this->option('dry-run')) {
            $this->warn('Sin --apply-green: ejecutando dry-run. Use --apply-green para escribir VERDES.');
            $dry = true;
        }

        $this->info(($dry ? '[DRY-RUN] ' : '[APPLY-GREEN] ').GlobalFormulaRepairService::BATCH_NAME);
        $report = $service->run($path, $dry, $sheet);
        $this->printClassificationSummary([
            'scan_counts' => ['zero_skip_11e_cols' => $report['pre_classification_counts']['VERDE_REPAIR'] ?? null],
            'universe_buckets' => $report['universe_buckets'] ?? [],
            'classification_counts' => $report['pre_classification_counts'] ?? [],
            'verde_count' => count($report['planned_verde'] ?? []),
            'amarillo_count' => count($report['amarillo_for_user'] ?? []),
            'rojo_count' => count($report['rojo_for_user'] ?? []),
            'daasa_readonly_check' => $report['daasa_readonly_check'] ?? [],
        ]);

        $this->line('Balances antes: '.json_encode($report['balances_before'] ?? [], JSON_UNESCAPED_UNICODE));
        if ($dry) {
            $this->line('Balances simulados: '.json_encode($report['balances_after_simulated'] ?? [], JSON_UNESCAPED_UNICODE));
            $this->info('Dry report: '.storage_path('app/reports/'.GlobalFormulaRepairService::BATCH_NAME.'_dry.json'));
        } else {
            $this->line('Acciones: '.count($report['actions'] ?? []));
            $this->line('Failed groups: '.count($report['failed_groups'] ?? []));
            $this->line('Balances después: '.json_encode($report['balances_after'] ?? [], JSON_UNESCAPED_UNICODE));
            $this->info('Post report: '.storage_path('app/reports/'.GlobalFormulaRepairService::BATCH_NAME.'_POST.json'));
            $this->warn('AMARILLOS pendientes de usuario: '.count($report['amarillo_for_user'] ?? []));
        }

        return empty($report['failed_groups']) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printClassificationSummary(array $report): void
    {
        $this->line('Universe buckets: '.json_encode($report['universe_buckets'] ?? [], JSON_UNESCAPED_UNICODE));
        $this->line('Classification: '.json_encode($report['classification_counts'] ?? [], JSON_UNESCAPED_UNICODE));
        $this->line('VERDE='.($report['verde_count'] ?? '?'));
        $this->line('AMARILLO='.($report['amarillo_count'] ?? '?'));
        $this->line('ROJO='.($report['rojo_count'] ?? '?'));
        $daasa = $report['daasa_readonly_check'] ?? [];
        if ($daasa) {
            $this->line('DAASA readonly: balance='.($daasa['balance_ars_signed'] ?? 'n/a')
                .' charges='.($daasa['repair_charges'] ?? 0)
                .' untouched_by_global='.json_encode($daasa['untouched_by_global'] ?? null));
        }
    }
}
