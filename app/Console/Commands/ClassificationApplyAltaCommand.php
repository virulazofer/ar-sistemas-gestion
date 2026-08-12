<?php

namespace App\Console\Commands;

use App\Services\Finance\StructuralReclassificationPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * ETAPA 11F-8 — Apply ALTA autorizado (un batch).
 * Requiere --confirm=APPLY-11F8-ALTA.
 */
class ClassificationApplyAltaCommand extends Command
{
    protected $signature = 'classification:apply-11f8-alta
        {--confirm= : Debe ser APPLY-11F8-ALTA}
        {--json= : Ruta relativa en storage/app para volcar el resumen}';

    protected $description = '11F-8 apply ALTA: Super/Comida/Miranda/MYU/Auto(+Lavado) — requiere confirmación';

    public function handle(StructuralReclassificationPlanner $planner): int
    {
        if ($this->option('confirm') !== 'APPLY-11F8-ALTA') {
            $this->error('Abortado: pasá --confirm=APPLY-11F8-ALTA');

            return self::FAILURE;
        }

        $this->warn('Aplicando ALTA 11F-8 (cat/sub únicamente; sin tocar ámbito/importes/FA/chart forzado)...');

        $summary = $planner->applyAlta(ensureTaxonomy: true);

        $this->table(
            ['Grupo', 'Candidatos', 'Actualizados', 'Omitidos'],
            collect($summary['by_group'] ?? [])->map(fn ($g, $name) => [
                is_string($name) ? $name : ($g['grupo'] ?? '—'),
                $g['candidates'] ?? 0,
                $g['updated'] ?? 0,
                $g['skipped'] ?? 0,
            ])->values()->all()
        );

        $this->info('Batch: '.$summary['batch_id']);
        $this->info('Actualizados total: '.$summary['updated_total']);
        $this->info('Omitidos total: '.$summary['skipped_total']);
        $this->line('Pendientes sin categoría antes: '.($summary['before']['pending'] ?? '—'));
        $this->line('Pendientes sin categoría después: '.($summary['after']['pending'] ?? '—'));
        $this->line('Cat OK sin cuenta contable (opcional): '.($summary['after']['missing_chart_optional'] ?? '—'));

        if (! empty($summary['by_group']['Auto']['by_sub'])) {
            $this->newLine();
            $this->info('Auto por subcategoría aplicada');
            $this->table(
                ['Subcategoría', 'Actualizados'],
                collect($summary['by_group']['Auto']['by_sub'])->map(fn ($n, $k) => [$k, $n])->values()->all()
            );
        }

        if ($json = $this->option('json')) {
            $rel = ltrim((string) $json, '/\\');
            Storage::disk('local')->put($rel, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line('JSON: storage/app/'.$rel);
        }

        $this->newLine();
        $this->warn('DETENERSE: apply ALTA finalizado. No continuar a 11F-9.');

        return self::SUCCESS;
    }
}
