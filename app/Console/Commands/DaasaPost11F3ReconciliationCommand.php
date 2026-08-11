<?php

namespace App\Console\Commands;

use App\Services\Imports\Historical\DaasaPost11F3ReconciliationService;
use Illuminate\Console\Command;

class DaasaPost11F3ReconciliationCommand extends Command
{
    protected $signature = 'imports:reconcile-daasa-post-11f3
                            {--dry-run : Solo planificar, sin escribir}
                            {--diagnose-formulas : Diagnóstico global de fórmulas Excel (sin repair)}';

    protected $description = 'HOTFIX POST-11F-3: reconciliación histórica DAASA (idempotente)';

    public function handle(DaasaPost11F3ReconciliationService $service): int
    {
        if ($this->option('diagnose-formulas')) {
            $path = $this->option('excel') ?? null;
            $this->warn('Usar imports:diagnose-historical-formulas para diagnóstico global completo.');
            $this->line(json_encode($service->classificationCounts(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $this->info(($dry ? '[DRY-RUN] ' : '').'Ejecutando '.DaasaPost11F3ReconciliationService::BATCH_NAME);

        $report = $service->run($dry);
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $dir = storage_path('app/reports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = DaasaPost11F3ReconciliationService::BATCH_NAME.($dry ? '_dry' : '').'.json';
        $absolute = $dir.DIRECTORY_SEPARATOR.$filename;
        file_put_contents($absolute, $json);

        $this->line('Balance ARS antes: '.($report['before_balance_ars'] ?? 'n/a'));
        if (! $dry) {
            $this->line('Balance ARS después: '.($report['after_balance_ars'] ?? 'n/a'));
            if (! empty($report['balance_compare'])) {
                $this->line('Excel reconstruido: '.$report['balance_compare']['excel_cc_reconstructed']);
                $this->line('Ledger signed: '.$report['balance_compare']['ledger_signed_ars']);
                $this->line('UI a cobrar: '.$report['balance_compare']['ledger_display_a_cobrar']);
            }
        }
        $this->info('Reporte: '.$absolute);
        $this->line($json);

        return self::SUCCESS;
    }
}
