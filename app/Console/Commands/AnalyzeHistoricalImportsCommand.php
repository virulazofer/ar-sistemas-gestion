<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Imports\Historical\HistoricalMovementsPreviewService;
use App\Services\Imports\Historical\SupplierCatalogPreviewService;
use Illuminate\Console\Command;

class AnalyzeHistoricalImportsCommand extends Command
{
    protected $signature = 'imports:analyze-historical
                            {--catalog= : Ruta absoluta al Excel de lista de precios}
                            {--movements= : Ruta absoluta al Excel GASTOS MENSUALES}
                            {--cutover= : Fecha de corte YYYY-MM-DD}
                            {--user= : ID de usuario para auditoría}
                            {--out= : Directorio de salida para reportes JSON}';

    protected $description = 'Analiza planillas reales en modo preview (sin confirmar importación).';

    public function handle(
        SupplierCatalogPreviewService $catalog,
        HistoricalMovementsPreviewService $movements,
    ): int {
        $userId = (int) ($this->option('user') ?: User::query()->orderBy('id')->value('id'));
        if ($userId < 1) {
            $this->error('No hay usuario para asociar el preview.');

            return self::FAILURE;
        }

        $outDir = $this->option('out') ?: storage_path('app/imports/reports');
        if (! is_dir($outDir)) {
            mkdir($outDir, 0775, true);
        }

        $report = [
            'generated_at' => now()->toDateTimeString(),
            'confirm_executed' => false,
            'catalog' => null,
            'movements' => null,
        ];

        if ($path = $this->option('catalog')) {
            $this->info('Analizando catálogo: '.$path);
            $batch = $catalog->analyzePath($path, basename($path), $userId, '2026-08-07');
            $summary = $batch->classification_summary ?? [];
            $report['catalog'] = [
                'batch_id' => $batch->id,
                'uuid' => $batch->uuid,
                'file_hash' => $batch->file_hash,
                'status' => $batch->status,
                'summary' => $summary,
            ];
            $this->line('Catálogo filas válidas: '.($summary['products_valid'] ?? 0));
        }

        if ($path = $this->option('movements')) {
            $this->info('Analizando movimientos: '.$path);
            $batch = $movements->analyzePath(
                $path,
                basename($path),
                $userId,
                $this->option('cutover') ?: null
            );
            $summary = $batch->classification_summary ?? [];
            $report['movements'] = [
                'batch_id' => $batch->id,
                'uuid' => $batch->uuid,
                'file_hash' => $batch->file_hash,
                'status' => $batch->status,
                'summary' => $summary,
                'reconciliation' => $batch->reconciliation_payload,
                'confirm_blocked' => true,
            ];
            $this->line(sprintf(
                'Movimientos: leídos=%s verde=%s amarillo=%s rojo=%s',
                $summary['rows_read'] ?? 0,
                $summary['green'] ?? 0,
                $summary['yellow'] ?? 0,
                $summary['red'] ?? 0,
            ));
        }

        $outFile = rtrim($outDir, '/\\').DIRECTORY_SEPARATOR.'historical-preview-'.now()->format('Ymd-His').'.json';
        file_put_contents($outFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('Reporte: '.$outFile);
        $this->warn('NO se confirmó ninguna importación definitiva.');

        return self::SUCCESS;
    }
}
