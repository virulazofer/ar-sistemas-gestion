<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'pattern_normalized',
    'pattern_display',
    'movement_type',
    'chart_account_id',
    'scope',
    'match_kind',
    'is_active',
    'hit_count',
    'last_used_at',
    'created_by',
    'updated_by',
])]
class RememberedClassification extends Model
{
    public const KIND_EXACT = 'exact';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'hit_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function chartAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function classificationLabel(): string
    {
        $account = $this->chartAccount;
        if (! $account) {
            return '—';
        }

        return $account->pathLabel();
    }
}
