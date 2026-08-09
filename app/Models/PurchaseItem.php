<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'purchase_id',
    'line_number',
    'product_id',
    'sku',
    'description',
    'quantity',
    'unit',
    'unit_price',
    'currency_id',
    'exchange_rate_value',
    'line_subtotal',
    'tax_amount',
    'discount_amount',
    'line_total',
    'unit_cost_ars',
    'unit_cost_usd',
    'line_total_ars',
    'line_total_usd',
    'qty_pending_stock',
    'stock_receipt_ready',
])]
class PurchaseItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:6',
            'exchange_rate_value' => 'decimal:6',
            'line_subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'unit_cost_ars' => 'decimal:6',
            'unit_cost_usd' => 'decimal:6',
            'line_total_ars' => 'decimal:2',
            'line_total_usd' => 'decimal:2',
            'qty_pending_stock' => 'decimal:4',
            'stock_receipt_ready' => 'boolean',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
