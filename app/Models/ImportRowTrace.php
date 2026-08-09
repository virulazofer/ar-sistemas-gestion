<?php

namespace App\Models;

use App\Enums\ImportReviewStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'import_batch_id',
    'sheet',
    'source_row',
    'row_hash',
    'review_status',
    'entity_type',
    'entity_id',
    'mapping',
    'original',
])]
class ImportRowTrace extends Model
{
    protected function casts(): array
    {
        return [
            'review_status' => ImportReviewStatus::class,
            'mapping' => 'array',
            'original' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
