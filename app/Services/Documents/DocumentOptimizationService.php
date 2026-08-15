<?php

namespace App\Services\Documents;

use App\Enums\DocumentOptimizationStatus;
use App\Models\Document;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentOptimizationService
{
    /**
     * Genera preview + copia optimizada. En 12A NO elimina el original.
     *
     * @return array{ok: bool, optimization_status: DocumentOptimizationStatus, optimized_path: ?string, preview_path: ?string, optimized_size: ?int, message: ?string}
     */
    public function optimize(Document $document, string $absoluteSourcePath, string $mime): array
    {
        if (! config('documents.optimization.enabled', true)) {
            return $this->result(DocumentOptimizationStatus::Skipped, null, null, null, 'Optimización deshabilitada.');
        }

        if (str_starts_with($mime, 'video/')) {
            return $this->result(DocumentOptimizationStatus::Failed, null, null, null, 'No se almacenan videos.');
        }

        if ($mime === 'application/pdf') {
            // PDF: sin recompresión en 12A; preview no aplica.
            return $this->result(DocumentOptimizationStatus::Skipped, null, null, null, 'PDF sin recompresión en 12A.');
        }

        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return $this->result(DocumentOptimizationStatus::Skipped, null, null, null, 'Formato no optimizable.');
        }

        if (! function_exists('imagecreatefromstring') || ! is_readable($absoluteSourcePath)) {
            return $this->result(DocumentOptimizationStatus::Failed, null, null, null, 'GD no disponible o archivo ilegible.');
        }

        $raw = @file_get_contents($absoluteSourcePath);
        if ($raw === false || $raw === '') {
            return $this->result(DocumentOptimizationStatus::Failed, null, null, null, 'No se pudo leer el original.');
        }

        $image = @imagecreatefromstring($raw);
        if ($image === false) {
            return $this->result(DocumentOptimizationStatus::Failed, null, null, null, 'Archivo de imagen inválido o corrupto.');
        }

        try {
            $image = $this->applyExifOrientation($image, $absoluteSourcePath, $mime);

            $disk = Storage::disk(config('documents.disk', 'local'));
            $uuid = $document->uuid ?: (string) Str::uuid();

            $previewPath = $this->writeJpegVariant(
                $disk,
                $image,
                config('documents.paths.previews', 'documents/previews').'/'.$uuid.'_preview.jpg',
                (int) config('documents.optimization.preview_max_edge_px', 480),
                (int) config('documents.optimization.preview_jpeg_quality', 70),
            );

            $optimizedPath = $this->writeJpegVariant(
                $disk,
                $image,
                config('documents.paths.optimized', 'documents/optimized').'/'.$uuid.'.jpg',
                (int) config('documents.optimization.max_edge_px', 1600),
                (int) config('documents.optimization.jpeg_quality', 78),
            );

            if ($optimizedPath === null) {
                return $this->result(DocumentOptimizationStatus::Failed, null, $previewPath, null, 'Falló la copia optimizada.');
            }

            $optimizedSize = (int) $disk->size($optimizedPath);

            Log::info('document.optimized', [
                'code' => $document->code,
                'original_size' => $document->original_size ?? $document->size,
                'optimized_size' => $optimizedSize,
                'keep_original' => (bool) $document->keep_original,
            ]);

            return $this->result(
                DocumentOptimizationStatus::Optimized,
                $optimizedPath,
                $previewPath,
                $optimizedSize,
                null,
            );
        } catch (\Throwable $e) {
            Log::warning('document.optimization_failed', [
                'code' => $document->code,
                'error' => $e->getMessage(),
            ]);

            return $this->result(DocumentOptimizationStatus::Failed, null, null, null, 'Pendiente de optimización.');
        } finally {
            if (is_resource($image) || $image instanceof \GdImage) {
                imagedestroy($image);
            }
        }
    }

    /**
     * @return array{ok: bool, optimization_status: DocumentOptimizationStatus, optimized_path: ?string, preview_path: ?string, optimized_size: ?int, message: ?string}
     */
    private function result(
        DocumentOptimizationStatus $status,
        ?string $optimizedPath,
        ?string $previewPath,
        ?int $optimizedSize,
        ?string $message,
    ): array {
        return [
            'ok' => in_array($status, [DocumentOptimizationStatus::Optimized, DocumentOptimizationStatus::Skipped, DocumentOptimizationStatus::KeepOriginal], true),
            'optimization_status' => $status,
            'optimized_path' => $optimizedPath,
            'preview_path' => $previewPath,
            'optimized_size' => $optimizedSize,
            'message' => $message,
        ];
    }

    private function writeJpegVariant($disk, \GdImage $source, string $relativePath, int $maxEdge, int $quality): ?string
    {
        $w = imagesx($source);
        $h = imagesy($source);
        if ($w < 1 || $h < 1) {
            return null;
        }

        $scale = min(1.0, $maxEdge / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $canvas = imagecreatetruecolor($nw, $nh);
        if ($canvas === false) {
            return null;
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagejpeg($canvas, null, max(40, min(92, $quality)));
        $binary = ob_get_clean();
        imagedestroy($canvas);

        if ($binary === false || $binary === '') {
            return null;
        }

        $disk->put($relativePath, $binary);

        return $relativePath;
    }

    private function applyExifOrientation(\GdImage $image, string $path, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        return match ($orientation) {
            3 => imagerotate($image, 180, 0) ?: $image,
            6 => imagerotate($image, -90, 0) ?: $image,
            8 => imagerotate($image, 90, 0) ?: $image,
            default => $image,
        };
    }
}
