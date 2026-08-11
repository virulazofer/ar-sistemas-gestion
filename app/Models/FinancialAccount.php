<?php

namespace App\Models;

use App\Enums\AccountType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'type',
    'currency_id',
    'account_holder_id',
    'is_liability',
    'alias',
    'aliases',
    'status',
    'external_identifier',
    'cbu_cvu',
    'cuit',
    'card_last4',
    'card_brand',
    'card_holder',
    'card_expiry_month',
    'card_expiry_year',
    'description',
    'cached_balance',
])]
class FinancialAccount extends Model
{
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'is_liability' => 'boolean',
            'aliases' => 'array',
            'cached_balance' => 'decimal:2',
        ];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(AccountHolder::class, 'account_holder_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
