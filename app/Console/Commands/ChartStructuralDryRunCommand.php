<?php

namespace App\Console\Commands;

use App\Services\Finance\ChartStructuralMigrationService;
use Illuminate\Console\Command;

class ChartStructuralDryRunCommand extends Command
{
    protected $signature = 'chart:dry-run-11f
        {--json= : Ruta de salida JSON}
        {--infra : Aplicar solo infraestructura (seed árbol + link FA + remap masters; SIN movimientos)}';

    protected $description = '11F dry-run migración estructural del plan de cuentas (sin apply masivo)';

    public function handle(ChartStructuralMigrationService $service): int
    {
        if ($this->option('infra')) {
            $this->warn('Aplicando SOLO infraestructura (sin tocar movimientos)...');
            $report = $service->applyInfrastructureOnly();
        } else {
            $report = $service->dryRun();
        }

        $jsonPath = $this->option('json');
        if ($jsonPath) {
            $dir = dirname($jsonPath);
            if ($dir !== '' && $dir !== '.' && ! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info('JSON: '.$jsonPath);
        }

        $this->table(
            ['Clave', 'Valor'],
            collect($report)
                ->reject(fn ($v) => is_array($v))
                ->map(fn ($v, $k) => [$k, is_bool($v) ? ($v ? 'true' : 'false') : (string) $v])
                ->values()
                ->all()
        );

        $this->warn($report['stop'] ?? 'DETENERSE ANTES DEL APPLY MASIVO');

        return self::SUCCESS;
    }
}
