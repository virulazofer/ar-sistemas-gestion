<?php

namespace App\Models;

use App\Enums\SaleStatus;
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
    'origin',
    'quotation_id',
    'sold_on',
    'currency_code',
    'exchange_rate_id',
    'exchange_rate_value',
    'payment_mode',
    'amount_paid_on_confirm',
    'salesperson_id',
    'notes',
    'documental_status',
    'subtotal',
    'discount_amount',
    'tax_amount',
    'total',
    'total_ars',
    'total_usd',
    'total_cost',
    'total_cost_ars',
    'total_cost_usd',
    'gross_margin',
    'charge_ledger_entry_id',
    'commercial_charge_id',
    'payment_ledger_entry_id',
    'financial_movement_id',
    'confirmed_at',
    'voided_at',
    'void_reason',
    'voided_by',
    'user_id',
])]
class Sale extends Model
{
    public const MODE_CASH = 'cash';

    public const MODE_CREDIT = 'credit';

    public const MODE_PARTIAL = 'partial';

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'documental_status' => \App\Enums\DocumentalStatus::class,
            'sold_on' => 'date',
            'exchange_rate_value' => 'decimal:6',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'total_ars' => 'decimal:2',
            'total_usd' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'total_cost_ars' => 'decimal:2',
            'total_cost_usd' => 'decimal:2',
            'gross_margin' => 'decimal:2',
            'amount_paid_on_confirm' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
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
        return $this->hasMany(SaleItem::class)->orderBy('line_number');
    }

    public function chargeEntry(): BelongsTo
    {
        return $this->belongsTo(ClientLedgerEntry::class, 'charge_ledger_entry_id');
    }

    public function commercialCharge(): BelongsTo
    {
        return $this->belongsTo(CommercialCharge::class);
    }

    public function paymentEntry(): BelongsTo
    {
        return $this->belongsTo(ClientLedgerEntry::class, 'payment_ledger_entry_id');
    }

    public function financialMovement(): BelongsTo
    {
        return $this->belongsTo(Movement::class, 'financial_movement_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }
}
