<?php

namespace App\Models;

use App\Enums\ClientLedgerType;
use App\Enums\MovementStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'client_id',
    'currency_id',
    'type',
    'amount',
    'signed_amount',
    'exchange_rate_id',
    'exchange_rate_value',
    'exchange_rate_at',
    'amount_ars',
    'amount_usd',
    'entry_date',
    'entry_time',
    'user_id',
    'description',
    'reason',
    'status',
    'void_reason',
    'voided_by',
    'voided_at',
    'financial_movement_id',
    'invoice_id',
    'quote_id',
    'sale_id',
    'work_order_id',
    'subscription_id',
    'event_id',
    'document_id',
])]
class ClientLedgerEntry extends Model
{
    protected function casts(): array
    {
        return [
            'type' => ClientLedgerType::class,
            'status' => MovementStatus::class,
            'amount' => 'decimal:2',
            'signed_amount' => 'decimal:2',
            'exchange_rate_value' => 'decimal:6',
            'exchange_rate_at' => 'datetime',
            'amount_ars' => 'decimal:2',
            'amount_usd' => 'decimal:2',
            'entry_date' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function financialMovement(): BelongsTo
    {
        return $this->belongsTo(Movement::class, 'financial_movement_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function voidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', MovementStatus::Posted->value);
    }

    public function isPosted(): bool
    {
        return $this->status === MovementStatus::Posted;
    }
}
