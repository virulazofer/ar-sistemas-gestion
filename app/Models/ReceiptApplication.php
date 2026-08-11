<?php

namespace App\Models;

use App\Enums\ReceiptStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'receipt_id',
    'commercial_charge_id',
    'amount',
    'status',
    'user_id',
    'voided_at',
    'void_reason',
    'voided_by',
])]
class ReceiptApplication extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ReceiptStatus::class,
            'amount' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(CommercialCharge::class, 'commercial_charge_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPosted(): bool
    {
        return $this->status === ReceiptStatus::Posted;
    }
}
