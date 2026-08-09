<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'work_order_id',
    'client_reported_issue',
    'technical_diagnosis',
    'notes',
    'user_id',
    'diagnosed_at',
])]
class WorkOrderDiagnosis extends Model
{
    protected function casts(): array
    {
        return ['diagnosed_at' => 'datetime'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
