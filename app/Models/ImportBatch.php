<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'uuid',
    'entity_type',
    'importer_kind',
    'source',
    'original_filename',
    'disk',
    'stored_path',
    'file_hash',
    'cutover_date',
    'period_from',
    'period_to',
    'status',
    'rows_total',
    'rows_valid',
    'rows_invalid',
    'rows_duplicate',
    'rows_green',
    'rows_yellow',
    'rows_red',
    'rows_imported',
    'preview_payload',
    'classification_summary',
    'reconciliation_payload',
    'options',
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
            'classification_summary' => 'array',
            'reconciliation_payload' => 'array',
            'options' => 'array',
            'error_summary' => 'array',
            'cutover_date' => 'date',
            'period_from' => 'date',
            'period_to' => 'date',
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
