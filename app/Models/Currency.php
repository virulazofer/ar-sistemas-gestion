<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'symbol', 'decimal_places', 'is_active', 'is_base'])]
class Currency extends Model
{
    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            'is_active' => 'boolean',
            'is_base' => 'boolean',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(FinancialAccount::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
