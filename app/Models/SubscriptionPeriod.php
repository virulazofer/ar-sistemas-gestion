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
    'status',
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

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
