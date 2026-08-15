<?php

namespace App\Models;

use App\Enums\DocumentOptimizationStatus;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'uuid',
    'code',
    'type',
    'disk',
    'path',
    'original_path',
    'optimized_path',
    'preview_path',
    'original_name',
    'mime',
    'size',
    'original_size',
    'optimized_size',
    'content_hash',
    'status',
    'optimization_status',
    'keep_original',
    'original_deleted_at',
    'source',
    'documentable_type',
    'documentable_id',
    'uploaded_by',
    'notes',
    'meta',
])]
class Document extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'optimization_status' => DocumentOptimizationStatus::class,
            'keep_original' => 'boolean',
            'meta' => 'array',
            'original_deleted_at' => 'datetime',
            'size' => 'integer',
            'original_size' => 'integer',
            'optimized_size' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isCaptureDocument(): bool
    {
        return filled($this->code) && $this->source === 'capture';
    }

    public function servingPath(): ?string
    {
        if ($this->optimized_path && ! $this->keep_original) {
            return $this->optimized_path;
        }

        return $this->original_path ?: $this->path;
    }

    public function absoluteServingPath(): ?string
    {
        $relative = $this->servingPath();
        if (! $relative) {
            return null;
        }

        return Storage::disk($this->disk ?: 'local')->path($relative);
    }

    public function typeLabel(): string
    {
        if ($this->type instanceof DocumentType) {
            return $this->type->label();
        }

        return (string) ($this->type ?: '—');
    }

    public function statusLabel(): string
    {
        if ($this->status instanceof DocumentStatus) {
            return $this->status->label();
        }

        return (string) ($this->status ?: '—');
    }

    public function associatedLabel(): string
    {
        if (! $this->documentable_type || ! $this->documentable_id) {
            return 'Sin asociar';
        }

        $short = class_basename($this->documentable_type);

        return "{$short} #{$this->documentable_id}";
    }
}
