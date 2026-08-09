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
    'part_number',
    'name',
    'description',
    'product_category_id',
    'product_subcategory_id',
    'brand',
    'model',
    'unit',
    'type',
    'requires_serial',
    'tracks_units',
    'status',
    'stock_min',
    'stock_max',
    'inventory_location_id',
    'qty_on_hand',
    'qty_reserved',
    'notes',
    'reference_cost_usd',
    'tax_indicator',
    'internal_tax',
    'list_price_date',
    'default_supplier_id',
    'supplier_comments',
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
            'tracks_units' => 'boolean',
            'stock_min' => 'decimal:4',
            'stock_max' => 'decimal:4',
            'qty_on_hand' => 'decimal:4',
            'qty_reserved' => 'decimal:4',
            'reference_cost_usd' => 'decimal:4',
            'internal_tax' => 'decimal:4',
            'list_price_date' => 'date',
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

    public function units(): HasMany
    {
        return $this->hasMany(InventoryUnit::class);
    }

    public function supplierCodes(): HasMany
    {
        return $this->hasMany(ProductSupplierCode::class);
    }

    public function defaultSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'default_supplier_id');
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
