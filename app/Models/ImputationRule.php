<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name',
    'condition_type',
    'condition_value',
    'target_category_id',
    'target_subcategory_id',
    'target_chart_account_id',
    'priority',
    'is_active',
    'allow_manual_override',
    'cached_match_count',
    'created_by',
])]
class ImputationRule extends Model
{
    public const TYPE_DESCRIPTION_CONTAINS = 'description_contains';

    public const TYPE_EXACT_DESCRIPTION = 'exact_description';

    public const TYPE_MOVEMENT_TYPE = 'movement_type';

    public const TYPE_CATEGORY_NAME = 'category_name';

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
            'allow_manual_override' => 'boolean',
            'cached_match_count' => 'integer',
        ];
    }

    public function targetCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'target_category_id');
    }

    public function targetSubcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class, 'target_subcategory_id');
    }

    public function targetChartAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class, 'target_chart_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function conditionLabel(): string
    {
        return match ($this->condition_type) {
            self::TYPE_DESCRIPTION_CONTAINS => 'Concepto contiene «'.$this->condition_value.'»',
            self::TYPE_EXACT_DESCRIPTION => 'Concepto exacto «'.$this->condition_value.'»',
            self::TYPE_MOVEMENT_TYPE => 'Tipo de movimiento = '.\App\Support\UiLabels::get($this->condition_value, $this->condition_value),
            self::TYPE_CATEGORY_NAME => 'Categoría = «'.$this->condition_value.'»',
            default => $this->condition_type.': '.$this->condition_value,
        };
    }

    public function destinationLabel(): string
    {
        $parts = [];
        if ($this->targetCategory) {
            $parts[] = $this->targetCategory->name;
        }
        if ($this->targetSubcategory) {
            $parts[] = $this->targetSubcategory->name;
        }
        if ($this->targetChartAccount) {
            $parts[] = $this->targetChartAccount->code.' '.$this->targetChartAccount->name;
        }

        return $parts === [] ? 'Sin destino' : implode(' / ', $parts);
    }
}
