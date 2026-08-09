<?php

namespace App\Models;

use App\Enums\WorkOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'number',
    'client_id',
    'work_order_type_id',
    'status',
    'priority',
    'assigned_user_id',
    'inventory_location_id',
    'opened_at',
    'closed_at',
    'title',
    'description',
    'solution',
    'notes',
    'currency_code',
    'total_cost',
    'total_cost_ars',
    'total_cost_usd',
    'total_price',
    'total_price_ars',
    'total_price_usd',
    'client_ledger_entry_id',
    'user_id',
    'sequence',
])]
class WorkOrder extends Model
{
    protected function casts(): array
    {
        return [
            'status' => WorkOrderStatus::class,
            'opened_at' => 'date',
            'closed_at' => 'date',
            'total_cost' => 'decimal:6',
            'total_cost_ars' => 'decimal:2',
            'total_cost_usd' => 'decimal:2',
            'total_price' => 'decimal:6',
            'total_price_ars' => 'decimal:2',
            'total_price_usd' => 'decimal:2',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(WorkOrderType::class, 'work_order_type_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(ClientLedgerEntry::class, 'client_ledger_entry_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(WorkOrderAsset::class);
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(WorkOrderDiagnosis::class)->latest('diagnosed_at');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(WorkOrderTask::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(WorkOrderMaterial::class);
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
