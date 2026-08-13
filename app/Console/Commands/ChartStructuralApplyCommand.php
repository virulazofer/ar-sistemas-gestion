<?php

namespace App\Console\Commands;

use App\Services\Finance\ChartStructuralMigrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * ETAPA 11F — Apply Fase 1 autorizado (convergencia chart + 2B raíces legacy).
 * Requiere --confirm=APPLY-11F-PHASE1.
 */
class ChartStructuralApplyCommand extends Command
{
    protected $signature = 'chart:apply-11f
        {--confirm= : Debe ser APPLY-11F-PHASE1}
        {--json= : Ruta relativa en storage/app para el reporte}
        {--skip-integrity-abort : No abortar ante diff monetario (solo diagnóstico)}';

    protected $description = '11F Fase 1 apply: 2B raíces legacy + FA link + Bazar/MUBI + convergencia chart (requiere confirmación)';

    public function handle(ChartStructuralMigrationService $service): int
    {
        if ($this->option('confirm') !== 'APPLY-11F-PHASE1') {
            $this->error('Abortado: pasá --confirm=APPLY-11F-PHASE1');

            return self::FAILURE;
        }

        $this->warn('Aplicando 11F Fase 1 (convergencia chart_account_id + corrección 2B)...');

        try {
            $report = $service->applyPhase1(
                abortOnMonetaryDiff: ! $this->option('skip-integrity-abort')
            );
        } catch (\Throwable $e) {
            $this->error('DETENERSE: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Clave', 'Valor'],
            collect($report)
                ->reject(fn ($v) => is_array($v))
                ->map(fn ($v, $k) => [$k, is_bool($v) ? ($v ? 'true' : 'false') : (string) $v])
                ->values()
                ->all()
        );

        if ($json = $this->option('json')) {
            $rel = ltrim((string) $json, '/\\');
            Storage::disk('local')->put($rel, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line('JSON: storage/app/'.$rel);
        }

        if (! ($report['integrity_ok'] ?? false)) {
            $this->error('DETENERSE: integridad PRE/POST con diferencias.');

            return self::FAILURE;
        }

        $this->info('Batch: '.($report['batch_id'] ?? '—'));
        $this->warn($report['stop'] ?? '11F PLAN DE CUENTAS — FASE 1 COMPLETADA');

        return self::SUCCESS;
    }
}
