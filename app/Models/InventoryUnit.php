<?php

namespace App\Models;

use App\Enums\UnitCondition;
use App\Enums\UnitStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'product_id',
    'internal_code',
    'manufacturer_serial',
    'condition',
    'status',
    'inventory_lot_id',
    'first_used_at',
    'notes',
    'created_by',
])]
class InventoryUnit extends Model
{
    protected function casts(): array
    {
        return [
            'condition' => UnitCondition::class,
            'status' => UnitStatus::class,
            'first_used_at' => 'datetime',
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

    public function events(): HasMany
    {
        return $this->hasMany(InventoryUnitEvent::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
