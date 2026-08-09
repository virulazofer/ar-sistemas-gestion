<?php

namespace App\Models;

use App\Enums\ProductType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sku',
    'supplier_code',
    'name',
    'description',
    'product_category_id',
    'product_subcategory_id',
    'brand',
    'model',
    'unit',
    'type',
    'requires_serial',
    'status',
    'stock_min',
    'stock_max',
    'inventory_location_id',
    'qty_on_hand',
    'qty_reserved',
    'notes',
    'import_batch_id',
    'external_id',
])]
class Product extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'requires_serial' => 'boolean',
            'stock_min' => 'decimal:4',
            'stock_max' => 'decimal:4',
            'qty_on_hand' => 'decimal:4',
            'qty_reserved' => 'decimal:4',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(ProductSubcategory::class, 'product_subcategory_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function lots(): HasMany
    {
        return $this->hasMany(InventoryLot::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function serials(): HasMany
    {
        return $this->hasMany(InventorySerial::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function tracksStock(): bool
    {
        return $this->type->tracksStock();
    }

    public function qtyAvailable(): string
    {
        return Money::sub((string) $this->qty_on_hand, (string) $this->qty_reserved, 4);
    }
}
