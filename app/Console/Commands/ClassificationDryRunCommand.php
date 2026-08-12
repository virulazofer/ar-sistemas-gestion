<?php

namespace App\Console\Commands;

use App\Services\Finance\StructuralReclassificationPlanner;
use Illuminate\Console\Command;

/**
 * ETAPA 11F-8 — Dry-run de reclasificación estructural.
 * NUNCA aplica cambios masivos de datos.
 */
class ClassificationDryRunCommand extends Command
{
    protected $signature = 'classification:dry-run-11f8
        {--ensure-taxonomy : Crea cat/sub canónicas faltantes (maestro; no mueve movimientos)}
        {--export : Escribe CSV/XLSX de ambiguos en storage/app/exports/11f8}
        {--json= : Ruta relativa en storage/app para volcar el reporte JSON}';

    protected $description = '11F-8 dry-run: Super/Comida/Miranda/MYU/Remotos/Auto — sin aplicar reclasificación masiva';

    public function handle(StructuralReclassificationPlanner $planner): int
    {
        $this->warn('DRY-RUN únicamente. No se aplican cambios masivos de movimientos.');

        $report = $planner->dryRun(ensureTaxonomyWrite: (bool) $this->option('ensure-taxonomy'));

        $this->table(
            ['Grupo', 'Encontrados', 'Propuesta', 'Confianza', 'Alta confianza', 'Ambiguos'],
            collect($report['groups'])->map(fn ($g) => [
                $g['grupo'],
                $g['encontrados'],
                $g['propuesta'],
                $g['confianza'],
                $g['propuesta_alta_confianza'],
                $g['ambiguos'] ?? 0,
            ])->all()
        );

        $s = $report['summary'];
        $this->newLine();
        $this->info('Resumen operativo');
        $this->line('Pendientes antes (sin categoría): '.$s['pendientes_antes']);
        $this->line('Encontrados en grupos: '.$s['encontrados_grupos']);
        $this->line('Resueltos potencialmente (ALTA): '.$s['resueltos_potencialmente']);
        $this->line('Pendientes reales después (estimado): '.$s['pendientes_reales_despues_estimado']);
        $this->line('Cat/sub OK sin cuenta contable (opcional, no incompleto): '.$s['missing_chart_optional']);

        if (! empty($report['auto_breakdown'])) {
            $this->newLine();
            $this->info('Auto — breakdown por subcategoría propuesta');
            $this->table(
                ['Subcategoría', 'Cantidad'],
                collect($report['auto_breakdown'])->map(fn ($n, $k) => [$k, $n])->values()->all()
            );
        }

        if ($this->option('export')) {
            $paths = $planner->writeAmbiguousExports($report);
            $this->info("Export ambiguos: {$paths['count']} filas");
            $this->line('CSV: storage/app/'.$paths['csv']);
            $this->line('XLSX: storage/app/'.$paths['xlsx']);
        }

        if ($json = $this->option('json')) {
            $rel = ltrim((string) $json, '/\\');
            \Illuminate\Support\Facades\Storage::disk('local')->put(
                $rel,
                json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
            $this->line('JSON: storage/app/'.$rel);
        }

        $this->newLine();
        $this->warn('DETENERSE: no aplicar masa hasta aprobación explícita del usuario.');

        return self::SUCCESS;
    }
}
