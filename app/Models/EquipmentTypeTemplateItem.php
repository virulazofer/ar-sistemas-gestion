<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'equipment_type_id',
    'component_category_id',
    'qty_min',
    'qty_default',
    'qty_max',
    'is_required',
    'allow_remove',
    'sort_order',
    'notes',
])]
class EquipmentTypeTemplateItem extends Model
{
    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'allow_remove' => 'boolean',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(EquipmentType::class, 'equipment_type_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EquipmentComponentCategory::class, 'component_category_id');
    }
}
