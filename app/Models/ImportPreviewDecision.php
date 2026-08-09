<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'import_batch_id',
    'source_row',
    'decision_type',
    'match_key',
    'payload',
    'decided_by',
])]
class ImportPreviewDecision extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'source_row' => 'integer',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
