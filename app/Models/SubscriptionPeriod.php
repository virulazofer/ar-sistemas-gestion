<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'subscription_id',
    'period_key',
    'period_start',
    'period_end',
    'amount',
    'currency_code',
    'client_ledger_entry_id',
    'commercial_charge_id',
    'status',
    'documental_status',
    'generated_at',
    'generated_by',
])]
class SubscriptionPeriod extends Model
{
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'amount' => 'decimal:2',
            'documental_status' => \App\Enums\DocumentalStatus::class,
            'generated_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(ClientLedgerEntry::class, 'client_ledger_entry_id');
    }

    public function commercialCharge(): BelongsTo
    {
        return $this->belongsTo(CommercialCharge::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
