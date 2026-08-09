<?php

namespace App\Models;

use App\Enums\ChartAccountType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'type', 'parent_id', 'is_active', 'sort_order'])]
class ChartAccount extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'type' => ChartAccountType::class,
        ];
    }

    public function typeLabel(): string
    {
        $type = $this->type;

        return $type instanceof ChartAccountType
            ? $type->label()
            : ChartAccountType::labelFor(is_string($type) ? $type : null);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
