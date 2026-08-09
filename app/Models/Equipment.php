<?php

namespace App\Models;

use App\Enums\EquipmentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code',
    'equipment_type_id',
    'name',
    'status',
    'assembled_at',
    'disassembled_at',
    'inventory_location_id',
    'total_cost',
    'total_cost_ars',
    'total_cost_usd',
    'user_id',
    'notes',
])]
class Equipment extends Model
{
    protected $table = 'equipments';

    protected function casts(): array
    {
        return [
            'status' => EquipmentStatus::class,
            'assembled_at' => 'datetime',
            'disassembled_at' => 'datetime',
            'total_cost' => 'decimal:6',
            'total_cost_ars' => 'decimal:2',
            'total_cost_usd' => 'decimal:2',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(EquipmentType::class, 'equipment_type_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(EquipmentComponent::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(EquipmentStatusLog::class);
    }

    public function installedComponents(): HasMany
    {
        return $this->components()->where('status', 'installed');
    }
}
