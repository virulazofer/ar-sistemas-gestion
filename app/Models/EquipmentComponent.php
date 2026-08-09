<?php

namespace App\Models;

use App\Enums\EquipmentComponentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'equipment_id',
    'component_category_id',
    'product_id',
    'inventory_lot_id',
    'inventory_serial_id',
    'inventory_movement_id',
    'inventory_lot_allocation_id',
    'quantity',
    'unit_cost',
    'currency_id',
    'exchange_rate_value',
    'unit_cost_ars',
    'unit_cost_usd',
    'total_cost',
    'total_cost_ars',
    'total_cost_usd',
    'status',
    'installed_at',
    'removed_at',
    'removal_reason',
    'replaced_by_component_id',
    'warranty_until',
    'purchase_id',
])]
class EquipmentComponent extends Model
{
    protected function casts(): array
    {
        return [
            'status' => EquipmentComponentStatus::class,
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:6',
            'exchange_rate_value' => 'decimal:6',
            'unit_cost_ars' => 'decimal:6',
            'unit_cost_usd' => 'decimal:6',
            'total_cost' => 'decimal:6',
            'total_cost_ars' => 'decimal:2',
            'total_cost_usd' => 'decimal:2',
            'installed_at' => 'datetime',
            'removed_at' => 'datetime',
            'warranty_until' => 'date',
        ];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EquipmentComponentCategory::class, 'component_category_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(InventorySerial::class, 'inventory_serial_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLotAllocation::class, 'inventory_lot_allocation_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }
}
