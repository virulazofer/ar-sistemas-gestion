<?php

namespace App\Models;

use App\Enums\CommercialItemType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sale_id',
    'line_number',
    'item_type',
    'description',
    'product_id',
    'equipment_id',
    'equipment_type_id',
    'work_order_id',
    'subscription_id',
    'quotation_item_id',
    'quantity',
    'unit_price',
    'currency_code',
    'discount_amount',
    'tax_amount',
    'line_subtotal',
    'line_total',
    'line_total_ars',
    'line_total_usd',
    'unit_cost',
    'line_cost',
    'line_cost_ars',
    'line_cost_usd',
    'line_margin',
    'inventory_movement_id',
    'equipment_status_before',
    'requires_build',
    'notes',
])]
class SaleItem extends Model
{
    protected function casts(): array
    {
        return [
            'item_type' => CommercialItemType::class,
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:6',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_subtotal' => 'decimal:2',
            'line_total' => 'decimal:2',
            'line_total_ars' => 'decimal:2',
            'line_total_usd' => 'decimal:2',
            'unit_cost' => 'decimal:6',
            'line_cost' => 'decimal:2',
            'line_cost_ars' => 'decimal:2',
            'line_cost_usd' => 'decimal:2',
            'line_margin' => 'decimal:2',
            'requires_build' => 'boolean',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class);
    }
}
