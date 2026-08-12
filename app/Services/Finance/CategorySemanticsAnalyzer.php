<?php

namespace App\Services\Finance;

use App\Models\Category;
use App\Models\Movement;
use App\Models\Subcategory;
use Illuminate\Support\Collection;

/**
 * Análisis (sin aplicar) para conceptos ambiguos: Comida, Auto, Miranda, MYU.
 */
class CategorySemanticsAnalyzer
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function analyzeAmbiguous(): array
    {
        return [
            'Comida' => $this->analyzeNameVariants(['Comida', 'Comidas']),
            'Auto' => $this->analyzeNameVariants(['Auto', 'Autos']),
            'Miranda' => $this->analyzeNameVariants(['Miranda']),
            'MYU' => $this->analyzeNameVariants(['MYU', 'MyU', 'Myu', 'Muebles y útiles', 'Muebles y utiles']),
        ];
    }

    /**
     * @param  list<string>  $names
     * @return array<string, mixed>
     */
    public function analyzeNameVariants(array $names): array
    {
        $categories = Category::query()
            ->where(function ($q) use ($names) {
                foreach ($names as $n) {
                    $q->orWhereRaw('LOWER(name) = ?', [mb_strtolower($n)]);
                }
            })->get();

        $subs = Subcategory::query()
            ->with('category')
            ->where(function ($q) use ($names) {
                foreach ($names as $n) {
                    $q->orWhereRaw('LOWER(name) = ?', [mb_strtolower($n)]);
                }
            })->get();

        $catIds = $categories->pluck('id')->all();
        $subIds = $subs->pluck('id')->all();

        $movements = Movement::query()
            ->posted()
            ->with(['account', 'category', 'subcategory'])
            ->where(function ($q) use ($catIds, $subIds, $names) {
                if ($catIds !== []) {
                    $q->orWhereIn('category_id', $catIds);
                }
                if ($subIds !== []) {
                    $q->orWhereIn('subcategory_id', $subIds);
                }
                foreach ($names as $n) {
                    $q->orWhere('description', 'like', '%'.$n.'%');
                }
            })
            ->orderBy('movement_date')
            ->get();

        return [
            'categories' => $categories->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'scope' => $c->scope,
                'chart_account_id' => $c->chart_account_id,
                'is_active' => $c->is_active,
            ])->all(),
            'subcategories' => $subs->map(fn (Subcategory $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'category' => $s->category?->name,
                'chart_account_id' => $s->chart_account_id,
            ])->all(),
            'movement_count' => $movements->count(),
            'total_ars' => number_format((float) $movements->sum('amount_ars'), 2, '.', ''),
            'by_scope' => $this->groupCount($movements, fn ($m) => $m->scope instanceof \BackedEnum ? $m->scope->value : (string) $m->scope),
            'by_account' => $this->groupCount($movements, fn ($m) => $m->account?->name ?? '—'),
            'by_category' => $this->groupCount($movements, fn ($m) => $m->category?->name ?? '—'),
            'concepts_sample' => $movements->take(30)->map(fn ($m) => [
                'id' => $m->id,
                'date' => $m->movement_date?->toDateString(),
                'description' => $m->description,
                'amount_ars' => (string) $m->amount_ars,
                'scope' => $m->scope instanceof \BackedEnum ? $m->scope->value : (string) $m->scope,
                'account' => $m->account?->name,
            ])->all(),
            'proposal' => $this->proposalFor($names[0], $movements),
            'auto_migrate' => false,
            'reason' => 'Requiere decisión humana (11F-8 §26). Infraestructura lista; no se aplica cambio semántico automático.',
        ];
    }

    /**
     * @param  Collection<int, Movement>  $movements
     * @return array<string, mixed>
     */
    private function proposalFor(string $label, Collection $movements): array
    {
        return match (mb_strtolower($label)) {
            'comida', 'comidas' => [
                'suggested_parent' => 'Alimentación',
                'suggested_as' => 'subcategoría (no categoría rígida)',
                'note' => 'Ámbito Personal/Profesional permanece independiente del nombre de categoría.',
                'confidence' => $movements->count() > 0 ? 'MEDIA' : 'BAJA',
            ],
            'auto', 'autos' => [
                'suggested_parent' => 'Transporte / Vehículo',
                'suggested_as' => 'categoría padre con subs: combustible, seguro, mantenimiento, patente, estacionamiento, peajes',
                'note' => 'No mezclar automáticamente si el histórico ya distingue conceptos.',
                'confidence' => 'MEDIA',
                'distinct_concepts' => $movements->pluck('description')->filter()->unique()->take(40)->values()->all(),
            ],
            'miranda' => [
                'suggested_as' => 'pendiente — no asumir significado',
                'note' => 'Analizar conceptos/importes/ámbitos; proponer solo con alta confianza.',
                'confidence' => 'BAJA',
            ],
            'myu', 'muebles y útiles', 'muebles y utiles' => [
                'suggested_label' => 'Muebles y útiles',
                'suggested_as' => 'gasto operativo; futura distinción gasto vs activo patrimonial sin módulo patrimonial completo aún',
                'confidence' => 'MEDIA',
            ],
            default => ['confidence' => 'BAJA'],
        };
    }

    /**
     * @param  Collection<int, Movement>  $movements
     * @param  callable(Movement): string  $fn
     * @return array<string, int>
     */
    private function groupCount(Collection $movements, callable $fn): array
    {
        $out = [];
        foreach ($movements as $m) {
            $k = $fn($m) ?: '—';
            $out[$k] = ($out[$k] ?? 0) + 1;
        }
        arsort($out);

        return $out;
    }
}
