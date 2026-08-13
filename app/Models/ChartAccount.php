<?php

namespace App\Models;

use App\Enums\ChartAccountType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable([
    'code',
    'name',
    'type',
    'parent_id',
    'is_active',
    'is_protected',
    'help_text',
    'suggested_scope',
    'sort_order',
])]
class ChartAccount extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_protected' => 'boolean',
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
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('code');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class);
    }

    public function financialAccounts(): HasMany
    {
        return $this->hasMany(FinancialAccount::class);
    }

    public function isProtectedRoot(): bool
    {
        return (bool) $this->is_protected && $this->parent_id === null;
    }

    public function isLeaf(): bool
    {
        if ($this->relationLoaded('children')) {
            return $this->children->isEmpty();
        }

        return ! $this->children()->exists();
    }

    /** Ruta visible: Egresos › Automotor › Combustible */
    public function pathLabel(string $separator = ' › '): string
    {
        $parts = $this->ancestorsAndSelf()->map(fn (self $n) => $n->name)->all();

        return implode($separator, $parts);
    }

    /** @return Collection<int, self> */
    public function ancestorsAndSelf(): Collection
    {
        $nodes = collect();
        $current = $this;
        $guard = 0;
        while ($current && $guard < 32) {
            $nodes->prepend($current);
            $current = $current->relationLoaded('parent')
                ? $current->parent
                : $current->parent()->first();
            $guard++;
        }

        return $nodes->values();
    }

    public function root(): ?self
    {
        return $this->ancestorsAndSelf()->first();
    }

    /** @return list<int> */
    public function descendantIds(): array
    {
        $ids = [];
        $frontier = [$this->id];
        while ($frontier !== []) {
            $children = self::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            foreach ($children as $id) {
                $ids[] = $id;
            }
            $frontier = $children;
        }

        return $ids;
    }

    /** @return list<int> */
    public function selfAndDescendantIds(): array
    {
        return array_values(array_unique(array_merge([$this->id], $this->descendantIds())));
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id')->orderBy('sort_order')->orderBy('code');
    }

    public function scopeOfType($query, ChartAccountType|string $type)
    {
        $value = $type instanceof ChartAccountType ? $type->value : $type;

        return $query->where('type', $value);
    }
}
