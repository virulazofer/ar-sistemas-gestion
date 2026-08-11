<?php

namespace App\Models;

use App\Enums\DocumentalStatus;
use App\Enums\ReceiptStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'number',
    'sequence',
    'client_id',
    'received_on',
    'currency_code',
    'amount',
    'amount_applied',
    'amount_on_account',
    'financial_account_id',
    'financial_movement_id',
    'client_ledger_entry_id',
    'application_mode',
    'insufficient_option',
    'concept',
    'notes',
    'status',
    'documental_status',
    'user_id',
    'voided_at',
    'void_reason',
    'voided_by',
])]
class Receipt extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ReceiptStatus::class,
            'documental_status' => DocumentalStatus::class,
            'received_on' => 'date',
            'amount' => 'decimal:2',
            'amount_applied' => 'decimal:2',
            'amount_on_account' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function financialMovement(): BelongsTo
    {
        return $this->belongsTo(Movement::class, 'financial_movement_id');
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(ClientLedgerEntry::class, 'client_ledger_entry_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ReceiptApplication::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vouchers(): MorphMany
    {
        return $this->morphMany(CommercialVoucher::class, 'voucherable');
    }

    public function isPosted(): bool
    {
        return $this->status === ReceiptStatus::Posted;
    }
}
