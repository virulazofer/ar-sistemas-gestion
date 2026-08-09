<?php

namespace App\Models;

use App\Enums\InventoryMovementType;
use App\Enums\MovementStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'transfer_group_id',
    'product_id',
    'type',
    'quantity',
    'signed_qty_on_hand',
    'signed_qty_reserved',
    'movement_date',
    'movement_time',
    'user_id',
    'reason',
    'notes',
    'inventory_location_id',
    'inventory_location_to_id',
    'purchase_id',
    'purchase_item_id',
    'work_order_id',
    'sale_id',
    'inventory_lot_id',
    'unit_cost',
    'currency_id',
    'exchange_rate_value',
    'total_cost',
    'total_cost_ars',
    'total_cost_usd',
    'status',
    'void_reason',
    'voided_by',
    'voided_at',
])]
class InventoryMovement extends Model
{
    protected function casts(): array
    {
        return [
            'type' => InventoryMovementType::class,
            'status' => MovementStatus::class,
            'quantity' => 'decimal:4',
            'signed_qty_on_hand' => 'decimal:4',
            'signed_qty_reserved' => 'decimal:4',
            'movement_date' => 'date',
            'unit_cost' => 'decimal:6',
            'exchange_rate_value' => 'decimal:6',
            'total_cost' => 'decimal:6',
            'total_cost_ars' => 'decimal:2',
            'total_cost_usd' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function locationTo(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_to_id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(InventoryLotAllocation::class);
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', MovementStatus::Posted->value);
    }

    public function isPosted(): bool
    {
        return $this->status === MovementStatus::Posted;
    }
}
