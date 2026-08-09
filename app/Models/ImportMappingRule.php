<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'rule_type',
    'match_key',
    'match_value',
    'action',
    'is_active',
    'auto_apply',
    'times_applied',
    'approved_by',
    'approved_at',
    'notes',
])]
class ImportMappingRule extends Model
{
    protected function casts(): array
    {
        return [
            'action' => 'array',
            'is_active' => 'boolean',
            'auto_apply' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
