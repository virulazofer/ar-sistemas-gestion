<?php

namespace App\Models;

use App\Enums\QuotationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'number',
    'sequence',
    'client_id',
    'status',
    'quoted_on',
    'valid_until',
    'currency_code',
    'exchange_rate_id',
    'exchange_rate_value',
    'salesperson_id',
    'notes',
    'terms',
    'subtotal',
    'discount_amount',
    'tax_amount',
    'total',
    'estimated_cost',
    'estimated_cost_ars',
    'estimated_cost_usd',
    'estimated_margin',
    'total_ars',
    'total_usd',
    'converted_sale_id',
    'converted_at',
    'user_id',
])]
class Quotation extends Model
{
    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'quoted_on' => 'date',
            'valid_until' => 'date',
            'exchange_rate_value' => 'decimal:6',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'estimated_cost' => 'decimal:2',
            'estimated_cost_ars' => 'decimal:2',
            'estimated_cost_usd' => 'decimal:2',
            'estimated_margin' => 'decimal:2',
            'total_ars' => 'decimal:2',
            'total_usd' => 'decimal:2',
            'converted_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('line_number');
    }

    public function convertedSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'converted_sale_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable() && $this->status !== QuotationStatus::Converted;
    }

    public function isExpiredByDate(): bool
    {
        return $this->valid_until && $this->valid_until->lt(now()->startOfDay());
    }
}
