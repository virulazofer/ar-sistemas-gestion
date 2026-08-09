<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'inventory_unit_id',
    'event_type',
    'from_condition',
    'to_condition',
    'from_status',
    'to_status',
    'notes',
    'user_id',
    'occurred_at',
])]
class InventoryUnitEvent extends Model
{
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class, 'inventory_unit_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
