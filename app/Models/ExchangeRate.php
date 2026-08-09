<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'base_currency_id',
    'quote_currency_id',
    'rate_type',
    'rate',
    'rate_buy',
    'source',
    'provider',
    'rate_at',
    'created_by',
    'provider_payload',
    'notes',
])]
class ExchangeRate extends Model
{
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:6',
            'rate_buy' => 'decimal:6',
            'rate_at' => 'datetime',
            'provider_payload' => 'array',
        ];
    }

    public function baseCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'base_currency_id');
    }

    public function quoteCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'quote_currency_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
