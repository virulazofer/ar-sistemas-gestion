<?php

namespace App\Models;

use App\Enums\InventorySerialStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id',
    'inventory_lot_id',
    'serial_number',
    'internal_code',
    'status',
    'supplier_id',
    'purchase_id',
    'purchased_at',
    'warranty_until',
    'notes',
])]
class InventorySerial extends Model
{
    protected function casts(): array
    {
        return [
            'status' => InventorySerialStatus::class,
            'purchased_at' => 'date',
            'warranty_until' => 'date',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', InventorySerialStatus::Available->value);
    }

    public function isAvailable(): bool
    {
        return $this->status === InventorySerialStatus::Available;
    }
}
