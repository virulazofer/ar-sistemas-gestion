<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'inventory_movement_id',
    'inventory_lot_id',
    'quantity',
    'unit_cost',
    'currency_id',
    'exchange_rate_value',
    'unit_cost_ars',
    'unit_cost_usd',
    'total_cost',
    'total_cost_ars',
    'total_cost_usd',
])]
class InventoryLotAllocation extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:6',
            'exchange_rate_value' => 'decimal:6',
            'unit_cost_ars' => 'decimal:6',
            'unit_cost_usd' => 'decimal:6',
            'total_cost' => 'decimal:6',
            'total_cost_ars' => 'decimal:2',
            'total_cost_usd' => 'decimal:2',
        ];
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
