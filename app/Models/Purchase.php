<?php

namespace App\Models;

use App\Enums\MovementStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'supplier_id',
    'purchase_date',
    'voucher_type',
    'voucher_letter',
    'voucher_number',
    'currency_id',
    'exchange_rate_id',
    'exchange_rate_value',
    'exchange_rate_at',
    'subtotal',
    'tax_amount',
    'other_taxes',
    'discount_amount',
    'total',
    'total_ars',
    'total_usd',
    'payment_mode',
    'status',
    'financial_account_id',
    'financial_movement_id',
    'obligation_ledger_entry_id',
    'user_id',
    'notes',
    'void_reason',
    'voided_by',
    'voided_at',
])]
class Purchase extends Model
{
    public const MODE_CASH = 'cash';

    public const MODE_CREDIT = 'credit';

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'exchange_rate_value' => 'decimal:6',
            'exchange_rate_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'other_taxes' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'total_ars' => 'decimal:2',
            'total_usd' => 'decimal:2',
            'status' => MovementStatus::class,
            'voided_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function financialMovement(): BelongsTo
    {
        return $this->belongsTo(Movement::class, 'financial_movement_id');
    }

    public function obligationLedgerEntry(): BelongsTo
    {
        return $this->belongsTo(SupplierLedgerEntry::class, 'obligation_ledger_entry_id');
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function isPosted(): bool
    {
        return $this->status === MovementStatus::Posted;
    }

    public function isCash(): bool
    {
        return $this->payment_mode === self::MODE_CASH;
    }
}
