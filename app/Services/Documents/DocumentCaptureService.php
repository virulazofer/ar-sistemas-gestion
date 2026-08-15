<?php

namespace App\Services\Documents;

use App\Enums\DocumentOptimizationStatus;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentCaptureService
{
    public function __construct(
        private readonly DocumentCodeService $codes,
        private readonly DocumentOptimizationService $optimizer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{document: Document, duplicate_warning: bool, duplicate_of: ?string}
     */
    public function capture(
        UploadedFile $file,
        int $userId,
        ?DocumentType $type = null,
        ?string $notes = null,
        bool $keepOriginal = false,
    ): array {
        $this->assertNotVideo($file);
        $mime = $this->detectMime($file);
        $this->assertAllowedMime($mime, $file);

        $hash = hash_file('sha256', $file->getRealPath());
        if ($hash === false) {
            throw ValidationException::withMessages([
                'file' => 'No se pudo calcular la integridad del archivo.',
            ]);
        }

        $duplicate = Document::query()
            ->where('content_hash', $hash)
            ->whereNotNull('code')
            ->orderByDesc('id')
            ->first();

        $uuid = (string) Str::uuid();
        $ext = $this->safeExtension($file, $mime);
        $year = now()->format('Y');
        $month = now()->format('m');
        $relativeDir = trim(config('documents.paths.documents', 'documents'), '/')."/{$year}/{$month}";
        $relativePath = "{$relativeDir}/{$uuid}.{$ext}";

        $diskName = config('documents.disk', 'local');
        $disk = Storage::disk($diskName);

        // Temp staging then move to final path (cleanup-friendly).
        $tempDir = trim(config('documents.paths.temp', 'documents/temp'), '/');
        $tempPath = "{$tempDir}/{$uuid}.{$ext}";
        $storedTemp = $file->storeAs($tempDir, "{$uuid}.{$ext}", $diskName);
        if (! $storedTemp) {
            throw ValidationException::withMessages([
                'file' => 'No se pudo subir el documento.',
            ]);
        }

        $absoluteTemp = $disk->path($tempPath);
        $size = (int) $disk->size($tempPath);

        $disk->makeDirectory($relativeDir);
        if (! $disk->move($tempPath, $relativePath)) {
            $disk->delete($tempPath);
            throw ValidationException::withMessages([
                'file' => 'No se pudo almacenar el documento de forma privada.',
            ]);
        }

        $absoluteFinal = $disk->path($relativePath);

        $document = DB::transaction(function () use (
            $uuid,
            $relativePath,
            $file,
            $mime,
            $size,
            $hash,
            $userId,
            $type,
            $notes,
            $keepOriginal,
            $diskName,
            $absoluteFinal,
        ) {
            $code = $this->codes->allocate();

            $doc = Document::query()->create([
                'uuid' => $uuid,
                'code' => $code,
                'type' => ($type ?? DocumentType::Otro)->value,
                'disk' => $diskName,
                'path' => $relativePath,
                'original_path' => $relativePath,
                'original_name' => $this->sanitizeOriginalName($file->getClientOriginalName()),
                'mime' => $mime,
                'size' => $size,
                'original_size' => $size,
                'content_hash' => $hash,
                'status' => DocumentStatus::PendienteDeAnalisis->value,
                'optimization_status' => DocumentOptimizationStatus::Pending->value,
                'keep_original' => $keepOriginal,
                'uploaded_by' => $userId,
                'notes' => $notes,
                'source' => 'capture',
                'documentable_type' => null,
                'documentable_id' => null,
            ]);

            $opt = $this->optimizer->optimize($doc, $absoluteFinal, $mime);

            $updates = [
                'optimization_status' => $opt['optimization_status']->value,
                'optimized_path' => $opt['optimized_path'],
                'preview_path' => $opt['preview_path'],
                'optimized_size' => $opt['optimized_size'],
            ];

            if ($keepOriginal) {
                $updates['optimization_status'] = DocumentOptimizationStatus::KeepOriginal->value;
            } elseif ($opt['optimization_status'] === DocumentOptimizationStatus::Failed) {
                $updates['status'] = DocumentStatus::Capturado->value;
            }

            // 12A: nunca borrar original tras upload (preparado para 12B).
            if ($keepOriginal) {
                $updates['meta'] = array_merge($doc->meta ?? [], [
                    'keep_original_reason' => 'user_exception',
                ]);
            }

            $doc->update($updates);
            $doc->refresh();

            $this->audit->log('document_captured', $doc, null, [
                'code' => $doc->code,
                'uuid' => $doc->uuid,
                'mime' => $doc->mime,
                'size' => $doc->size,
                'original_size' => $doc->original_size,
                'optimized_size' => $doc->optimized_size,
                'optimization_status' => $doc->optimization_status?->value ?? $doc->getAttribute('optimization_status'),
                'keep_original' => (bool) $doc->keep_original,
                'status' => $doc->status?->value ?? $doc->getAttribute('status'),
            ], 'Documento capturado');

            return $doc;
        });

        Log::info('document.captured', [
            'code' => $document->code,
            'user_id' => $userId,
            'status' => $document->status?->value ?? $document->getAttribute('status'),
            'size' => $document->size,
        ]);

        return [
            'document' => $document,
            'duplicate_warning' => $duplicate !== null,
            'duplicate_of' => $duplicate?->code,
        ];
    }

    /**
     * Soft delete + hard purge de archivos (sin huérfanos).
     */
    public function softDelete(Document $document, ?int $userId = null): void
    {
        $old = [
            'code' => $document->code,
            'status' => $document->status?->value ?? $document->getAttribute('status'),
        ];

        $document->update(['status' => DocumentStatus::Descartado->value]);
        $document->delete();

        $this->audit->log('document_soft_deleted', $document, $old, [
            'code' => $document->code,
            'deleted_by' => $userId,
        ], 'Documento descartado (soft delete)');
    }

    /**
     * Eliminación definitiva: metadata + archivos privados.
     */
    public function forceDelete(Document $document, ?int $userId = null): void
    {
        $disk = Storage::disk($document->disk ?: config('documents.disk', 'local'));
        $paths = array_filter([
            $document->path,
            $document->original_path,
            $document->optimized_path,
            $document->preview_path,
        ]);

        $sizes = [
            'size' => $document->size,
            'original_size' => $document->original_size,
            'optimized_size' => $document->optimized_size,
        ];

        foreach (array_unique($paths) as $path) {
            if ($path && $disk->exists($path)) {
                $disk->delete($path);
            }
        }

        $code = $document->code;
        $document->forceDelete();

        $this->audit->log('document_force_deleted', null, [
            'code' => $code,
            ...$sizes,
        ], [
            'code' => $code,
            'deleted_by' => $userId,
            'files_removed' => count($paths),
        ], 'Documento eliminado definitivamente');
    }

    private function assertNotVideo(UploadedFile $file): void
    {
        $mime = (string) ($file->getMimeType() ?: '');
        $ext = strtolower((string) $file->getClientOriginalExtension());
        if (str_starts_with($mime, 'video/') || in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'mkv'], true)) {
            throw ValidationException::withMessages([
                'file' => 'No se admiten videos. Usá una foto o un PDF.',
            ]);
        }
    }

    private function detectMime(UploadedFile $file): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $real = $finfo->file($file->getRealPath()) ?: '';
        $client = (string) ($file->getClientMimeType() ?: '');

        $real = strtolower(trim($real));
        if (in_array($real, config('documents.allowed_mimes'), true)) {
            return $real;
        }

        // Algunos JPEG llegan como image/jpg
        if (in_array($real, ['image/jpg'], true)) {
            return 'image/jpeg';
        }

        throw ValidationException::withMessages([
            'file' => in_array($real, config('documents.rejected_mimes'), true) || str_starts_with($client, 'image/heic')
                ? 'Formato HEIC/HEIF o no admitido. Convertí a JPEG/PNG/WEBP/PDF.'
                : 'Formato no admitido.',
        ]);
    }

    private function assertAllowedMime(string $mime, UploadedFile $file): void
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $allowedExt = config('documents.allowed_extensions', []);
        if ($ext !== '' && ! in_array($ext, $allowedExt, true)) {
            throw ValidationException::withMessages([
                'file' => 'Formato no admitido.',
            ]);
        }

        $maxKb = (int) config('documents.max_upload_kb', 10240);
        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages([
                'file' => 'El archivo supera el tamaño permitido.',
            ]);
        }
    }

    private function safeExtension(UploadedFile $file, string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }

    private function sanitizeOriginalName(?string $name): string
    {
        $name = $name ?: 'documento';
        $name = str_replace(["\0", '/', '\\'], '', $name);
        $name = preg_replace('/[^\pL\pN.\-_ ()\[\]]+/u', '_', $name) ?: 'documento';

        return Str::limit($name, 180, '');
    }
}
