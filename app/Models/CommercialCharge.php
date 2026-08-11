<?php

namespace App\Models;

use App\Enums\CommercialChargeStatus;
use App\Enums\CommercialChargeType;
use App\Enums\DocumentalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'number',
    'sequence',
    'client_id',
    'charge_type',
    'concept',
    'charged_on',
    'due_on',
    'currency_code',
    'amount',
    'amount_applied',
    'amount_open',
    'scope',
    'status',
    'documental_status',
    'notes',
    'client_ledger_entry_id',
    'sale_id',
    'subscription_id',
    'subscription_period_id',
    'work_order_id',
    'user_id',
    'voided_at',
    'void_reason',
    'voided_by',
])]
class CommercialCharge extends Model
{
    protected function casts(): array
    {
        return [
            'charge_type' => CommercialChargeType::class,
            'status' => CommercialChargeStatus::class,
            'documental_status' => DocumentalStatus::class,
            'charged_on' => 'date',
            'due_on' => 'date',
            'amount' => 'decimal:2',
            'amount_applied' => 'decimal:2',
            'amount_open' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(ClientLedgerEntry::class, 'client_ledger_entry_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function subscriptionPeriod(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPeriod::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ReceiptApplication::class);
    }

    public function vouchers(): MorphMany
    {
        return $this->morphMany(CommercialVoucher::class, 'voucherable');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            CommercialChargeStatus::Pending->value,
            CommercialChargeStatus::Partial->value,
        ])->where('amount_open', '>', 0);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen() && (float) $this->amount_open > 0;
    }

    public function originLabel(): string
    {
        if ($this->sale_id) {
            return 'Venta';
        }
        if ($this->subscription_id) {
            return 'Abono';
        }
        if ($this->work_order_id) {
            return 'OT';
        }

        return 'Manual';
    }
}
