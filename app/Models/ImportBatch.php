<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'uuid',
    'entity_type',
    'source',
    'original_filename',
    'disk',
    'stored_path',
    'status',
    'rows_total',
    'rows_valid',
    'rows_invalid',
    'rows_duplicate',
    'rows_imported',
    'preview_payload',
    'error_summary',
    'user_id',
    'confirmed_at',
    'rolled_back_at',
    'rolled_back_by',
    'rollback_reason',
])]
class ImportBatch extends Model
{
    protected function casts(): array
    {
        return [
            'preview_payload' => 'array',
            'error_summary' => 'array',
            'confirmed_at' => 'datetime',
            'rolled_back_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rolledBackByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rolled_back_by');
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isRolledBack(): bool
    {
        return $this->status === 'rolled_back';
    }
}
