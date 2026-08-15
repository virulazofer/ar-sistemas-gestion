<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupDocumentTempCommand extends Command
{
    protected $signature = 'documents:cleanup-temp
                            {--dry-run : Solo listar archivos a eliminar}
                            {--hours= : Antigüedad mínima en horas (default config)}';

    protected $description = 'Limpia temporales de captura documental (34B)';

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?: config('documents.temp_ttl_hours', 24));
        $dry = (bool) $this->option('dry-run');
        $disk = Storage::disk(config('documents.disk', 'local'));
        $tempRoot = trim(config('documents.paths.temp', 'documents/temp'), '/');
        $cutoff = now()->subHours(max(1, $hours))->getTimestamp();

        if (! $disk->exists($tempRoot)) {
            $this->info('Sin directorio temporal.');

            return self::SUCCESS;
        }

        $removed = 0;
        $bytes = 0;

        foreach ($disk->allFiles($tempRoot) as $path) {
            $mtime = $disk->lastModified($path);
            if ($mtime > $cutoff) {
                continue;
            }

            $size = (int) $disk->size($path);
            if ($dry) {
                $this->line("[dry-run] {$path} ({$size} bytes)");
            } else {
                $disk->delete($path);
                Log::info('document.temp_cleaned', [
                    'path' => $path,
                    'size' => $size,
                ]);
            }
            $removed++;
            $bytes += $size;
        }

        $prefix = $dry ? '[dry-run] ' : '';
        $this->info("{$prefix}Temporales: {$removed} archivo(s), {$bytes} bytes.");

        return self::SUCCESS;
    }
}
