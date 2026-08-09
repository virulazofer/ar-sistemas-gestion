<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'work_order_id',
    'description',
    'assigned_user_id',
    'hours',
    'cost_amount',
    'price_amount',
    'currency_code',
    'exchange_rate_value',
    'cost_ars',
    'cost_usd',
    'price_ars',
    'price_usd',
    'status',
])]
class WorkOrderTask extends Model
{
    protected function casts(): array
    {
        return [
            'hours' => 'decimal:2',
            'cost_amount' => 'decimal:6',
            'price_amount' => 'decimal:6',
            'exchange_rate_value' => 'decimal:6',
            'cost_ars' => 'decimal:2',
            'cost_usd' => 'decimal:2',
            'price_ars' => 'decimal:2',
            'price_usd' => 'decimal:2',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
