<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'work_order_id',
    'product_id',
    'quantity',
    'price_unit',
    'price_total',
    'currency_code',
    'exchange_rate_value',
    'price_ars',
    'price_usd',
    'cost_unit',
    'cost_total',
    'cost_ars',
    'cost_usd',
    'status',
    'inventory_movement_id',
    'inventory_lot_id',
    'inventory_lot_allocation_id',
    'inventory_serial_id',
    'notes',
])]
class WorkOrderMaterial extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'price_unit' => 'decimal:6',
            'price_total' => 'decimal:6',
            'exchange_rate_value' => 'decimal:6',
            'price_ars' => 'decimal:2',
            'price_usd' => 'decimal:2',
            'cost_unit' => 'decimal:6',
            'cost_total' => 'decimal:6',
            'cost_ars' => 'decimal:2',
            'cost_usd' => 'decimal:2',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(InventorySerial::class, 'inventory_serial_id');
    }
}
