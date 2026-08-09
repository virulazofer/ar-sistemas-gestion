<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'sort_order', 'is_active'])]
class EquipmentComponentCategory extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function templateItems(): HasMany
    {
        return $this->hasMany(EquipmentTypeTemplateItem::class, 'component_category_id');
    }
}
