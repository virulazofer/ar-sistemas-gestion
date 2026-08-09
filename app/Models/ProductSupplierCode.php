<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id',
    'supplier_id',
    'supplier_code',
    'part_number',
    'cost_usd',
    'tax_indicator',
    'internal_tax',
    'list_date',
    'is_primary',
])]
class ProductSupplierCode extends Model
{
    protected function casts(): array
    {
        return [
            'cost_usd' => 'decimal:4',
            'internal_tax' => 'decimal:4',
            'list_date' => 'date',
            'is_primary' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
