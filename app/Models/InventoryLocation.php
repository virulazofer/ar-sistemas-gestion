<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'is_default', 'is_active', 'notes'])]
class InventoryLocation extends Model
{
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(InventoryLot::class);
    }

    public static function defaultLocation(): self
    {
        return static::query()->where('is_default', true)->where('is_active', true)->firstOrFail();
    }
}
