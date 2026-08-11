<?php

namespace App\Console\Commands;

use App\Services\Imports\Historical\StagingTestDataCleanupService;
use Illuminate\Console\Command;

class StagingTestDataCleanupCommand extends Command
{
    protected $signature = 'staging:cleanup-test-data
                            {--diagnose : Solo diagnóstico (default si no --purge)}
                            {--purge : Purgar clase A autorizada}
                            {--out= : Ruta JSON de reporte}';

    protected $description = '6B: diagnostica/limpia movimientos de prueba en staging (no toca catálogo/maestros).';

    public function handle(StagingTestDataCleanupService $cleanup): int
    {
        $out = $this->option('out') ?: storage_path('app/imports/reports/staging-6b-'.now()->format('Ymd-His').'.json');

        if ($this->option('purge')) {
            $this->warn('Ejecutando PURGE clase A (autorizado 6B)...');
            try {
                $report = [
                    'mode' => 'purge',
                    'diagnose_before' => $cleanup->diagnose(),
                    'purge' => $cleanup->purgeClassA(false),
                ];
            } catch (\Throwable $e) {
                $report = [
                    'mode' => 'purge_blocked',
                    'error' => $e->getMessage(),
                    'diagnose' => $cleanup->diagnose(),
                ];
                file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $this->error($e->getMessage());
                $this->line('Reporte: '.$out);

                return self::FAILURE;
            }
        } else {
            $report = [
                'mode' => 'diagnose',
                'diagnose' => $cleanup->diagnose(),
            ];
        }

        if (! is_dir(dirname($out))) {
            mkdir(dirname($out), 0775, true);
        }
        file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $diag = $report['diagnose'] ?? $report['diagnose_before'] ?? [];
        $this->info('Candidatos A='.($diag['counts']['A_manual_test'] ?? 0)
            .' B='.($diag['counts']['B_catalog_masters'] ?? 0)
            .' D='.($diag['counts']['D_doubtful'] ?? 0)
            .' products='.($diag['products_count'] ?? 0));
        $this->line('Reporte: '.$out);

        if (($diag['stop_required'] ?? false) && ! $this->option('purge')) {
            $this->error('Hay clase D — revisar antes de purgar.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
