<?php

namespace App\Services\Documents;

use Illuminate\Support\Facades\Storage;

class DocumentStorageMetricsService
{
    /**
     * @return array{
     *     documents_count: int,
     *     total_bytes: int,
     *     original_bytes: int,
     *     optimized_bytes: int,
     *     preview_bytes: int,
     *     temp_bytes: int,
     *     average_bytes: float,
     *     quota_bytes: int,
     *     used_percent: float,
     *     level: string
     * }
     */
    public function snapshot(): array
    {
        $disk = Storage::disk(config('documents.disk', 'local'));
        $root = config('documents.paths.documents', 'documents');

        $total = 0;
        $original = 0;
        $optimized = 0;
        $preview = 0;
        $temp = 0;

        foreach ($this->safeFiles($disk, $root) as $path) {
            $size = (int) $disk->size($path);
            $total += $size;
            if (str_contains($path, '/temp/') || str_starts_with($path, 'documents/temp/')) {
                $temp += $size;
            } elseif (str_contains($path, '/previews/') || str_starts_with($path, 'documents/previews/')) {
                $preview += $size;
            } elseif (str_contains($path, '/optimized/') || str_starts_with($path, 'documents/optimized/')) {
                $optimized += $size;
            } else {
                $original += $size;
            }
        }

        $count = (int) \App\Models\Document::query()->whereNotNull('code')->count();
        $quota = max(1, (int) config('documents.storage_quota_bytes', 2_147_483_648));
        $percent = round(($total / $quota) * 100, 2);
        $warn = (int) config('documents.storage_warn_percent', 70);
        $critical = (int) config('documents.storage_critical_percent', 85);

        $level = 'ok';
        if ($percent >= $critical) {
            $level = 'critical';
        } elseif ($percent >= $warn) {
            $level = 'warning';
        }

        return [
            'documents_count' => $count,
            'total_bytes' => $total,
            'original_bytes' => $original,
            'optimized_bytes' => $optimized,
            'preview_bytes' => $preview,
            'temp_bytes' => $temp,
            'average_bytes' => $count > 0 ? round($total / $count, 2) : 0.0,
            'quota_bytes' => $quota,
            'used_percent' => $percent,
            'level' => $level,
        ];
    }

    /**
     * @return list<string>
     */
    private function safeFiles($disk, string $root): array
    {
        try {
            if (! $disk->exists($root)) {
                return [];
            }

            return $disk->allFiles($root);
        } catch (\Throwable) {
            return [];
        }
    }
}
