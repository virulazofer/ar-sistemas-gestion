<?php

namespace App\Models;

use App\Enums\InventoryLotStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'product_id',
    'inventory_location_id',
    'supplier_id',
    'purchase_id',
    'purchase_item_id',
    'received_at',
    'qty_received',
    'qty_remaining',
    'unit_cost',
    'currency_id',
    'exchange_rate_id',
    'exchange_rate_value',
    'unit_cost_ars',
    'unit_cost_usd',
    'status',
    'notes',
])]
class InventoryLot extends Model
{
    protected function casts(): array
    {
        return [
            'status' => InventoryLotStatus::class,
            'received_at' => 'datetime',
            'qty_received' => 'decimal:4',
            'qty_remaining' => 'decimal:4',
            'unit_cost' => 'decimal:6',
            'exchange_rate_value' => 'decimal:6',
            'unit_cost_ars' => 'decimal:6',
            'unit_cost_usd' => 'decimal:6',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(InventoryLotAllocation::class);
    }

    public function scopeOpenFifo(Builder $query): Builder
    {
        return $query->where('status', InventoryLotStatus::Open->value)
            ->where('qty_remaining', '>', 0)
            ->orderBy('received_at')
            ->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === InventoryLotStatus::Open;
    }
}
