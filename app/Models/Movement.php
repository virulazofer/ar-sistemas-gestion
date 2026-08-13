<?php

namespace App\Models;

use App\Enums\MovementScope;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'code',
    'transfer_id',
    'movement_date',
    'movement_time',
    'user_id',
    'scope',
    'type',
    'financial_account_id',
    'currency_id',
    'amount',
    'exchange_rate_id',
    'exchange_rate_value',
    'exchange_rate_at',
    'amount_ars',
    'amount_usd',
    'category_id',
    'subcategory_id',
    'chart_account_id',
    'description',
    'observations',
    'status',
    'void_reason',
    'voided_by',
    'voided_at',
    'client_id',
    'supplier_id',
    'work_order_id',
    'event_id',
    'document_id',
    'import_batch_id',
    'external_id',
    'is_opening_adjustment',
    'source_sheet',
    'source_row',
    'source_payload',
])]
class Movement extends Model
{
    protected function casts(): array
    {
        return [
            'movement_date' => 'date',
            'scope' => MovementScope::class,
            'type' => MovementType::class,
            'status' => MovementStatus::class,
            'amount' => 'decimal:2',
            'exchange_rate_value' => 'decimal:6',
            'exchange_rate_at' => 'datetime',
            'amount_ars' => 'decimal:2',
            'amount_usd' => 'decimal:2',
            'voided_at' => 'datetime',
            'is_opening_adjustment' => 'boolean',
            'source_payload' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function exchangeRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class);
    }

    public function chartAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function voidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function editAudits(): HasMany
    {
        return $this->hasMany(MovementEditAudit::class);
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class, 'financial_movement_id');
    }

    public function sale(): HasOne
    {
        return $this->hasOne(Sale::class, 'financial_movement_id');
    }

    public function purchase(): HasOne
    {
        return $this->hasOne(Purchase::class, 'financial_movement_id');
    }

    public function clientLedgerEntry(): HasOne
    {
        return $this->hasOne(ClientLedgerEntry::class, 'financial_movement_id');
    }

    public function supplierLedgerEntry(): HasOne
    {
        return $this->hasOne(SupplierLedgerEntry::class, 'financial_movement_id');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', MovementStatus::Posted->value);
    }

    public function isPosted(): bool
    {
        return $this->status === MovementStatus::Posted;
    }

    public function isTransfer(): bool
    {
        return $this->type->isTransfer();
    }

    public function affectsResult(): bool
    {
        return in_array($this->type, [MovementType::Income, MovementType::Expense], true);
    }

    public function displayCode(): string
    {
        return $this->code ?: ('#'.$this->id);
    }
}
