<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'code_prefix', 'next_sequence', 'is_active', 'notes'])]
class EquipmentType extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'next_sequence' => 'integer',
        ];
    }

    public function templateItems(): HasMany
    {
        return $this->hasMany(EquipmentTypeTemplateItem::class)->orderBy('sort_order');
    }

    public function equipments(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }
}
