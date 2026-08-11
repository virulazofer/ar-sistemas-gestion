<?php

namespace App\Models;

use App\Enums\CommercialVoucherType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'voucherable_type',
    'voucherable_id',
    'voucher_type',
    'point_of_sale',
    'number',
    'issued_on',
    'currency_code',
    'amount',
    'net_amount',
    'vat_amount',
    'other_taxes',
    'fiscal_date',
    'fiscal_period',
    'notes',
    'user_id',
])]
class CommercialVoucher extends Model
{
    protected function casts(): array
    {
        return [
            'voucher_type' => CommercialVoucherType::class,
            'issued_on' => 'date',
            'fiscal_date' => 'date',
            'amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'other_taxes' => 'decimal:2',
        ];
    }

    public function voucherable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
