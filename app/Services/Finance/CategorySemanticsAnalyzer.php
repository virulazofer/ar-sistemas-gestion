<?php

namespace App\Services\Finance;

use App\Models\Category;
use App\Models\Movement;
use App\Models\Subcategory;
use Illuminate\Support\Collection;

/**
 * Análisis (sin aplicar) para conceptos ambiguos + nomenclatura aprobada 11F-8.
 */
class CategorySemanticsAnalyzer
{
    public function __construct(
        private readonly StructuralReclassificationPlanner $planner,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function analyzeAmbiguous(): array
    {
        return [
            'Comida' => $this->analyzeNameVariants(['Comida', 'Comidas'], [
                'suggested_parent' => 'Alimentación',
                'suggested_as' => 'subcategoría Comidas',
                'propuesta' => 'EGRESO → Alimentación → Comidas',
                'confidence' => 'ALTA',
                'auto_migrate' => false,
                'note' => 'Aprobado; dry-run listo. No aplicar masa sin confirmación.',
            ]),
            'Auto' => $this->analyzeAuto(),
            'Miranda' => $this->analyzeNameVariants(['Miranda'], [
                'suggested_parent' => 'Gastos familiares',
                'suggested_as' => 'subcategoría Miranda',
                'propuesta' => 'EGRESO → Gastos familiares → Miranda',
                'confidence' => 'ALTA',
                'auto_migrate' => false,
                'note' => 'Aprobado usuario: Gastos familiares → Miranda.',
            ]),
            'MYU' => $this->analyzeNameVariants(['MYU', 'MyU', 'Myu', 'Muebles y útiles', 'Muebles y utiles'], [
                'suggested_label' => 'Muebles y útiles',
                'suggested_as' => 'categoría Muebles y útiles / sub MYU',
                'propuesta' => 'EGRESO → Muebles y útiles → MYU',
                'confidence' => 'ALTA',
                'auto_migrate' => false,
                'note' => 'Gasto operativo; sin módulo patrimonial completo aún.',
            ]),
            'Remotos' => $this->analyzeNameVariants(['Remotos'], [
                'suggested_parent' => 'Servicios profesionales',
                'suggested_as' => 'subcategoría Remotos (INGRESO)',
                'propuesta' => 'INGRESO → Servicios profesionales → Remotos',
                'confidence' => 'ALTA',
                'auto_migrate' => false,
                'note' => 'NO es Servicios (egreso utilities/streaming).',
            ]),
            'Super' => $this->analyzeNameVariants(['Super'], [
                'suggested_parent' => 'Alimentación',
                'suggested_as' => 'subcategoría Supermercado',
                'propuesta' => 'EGRESO → Alimentación → Supermercado',
                'confidence' => 'ALTA',
                'auto_migrate' => false,
                'note' => 'Conservar excel_name/alias Super.',
            ]),
        ];
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    public function analyzeNameVariants(array $names, array $proposal = []): array
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

        $defaultProposal = $this->proposalFor($names[0], $movements);
        $merged = array_merge($defaultProposal, $proposal);

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
            'proposal' => $merged,
            'auto_migrate' => (bool) ($merged['auto_migrate'] ?? false),
            'reason' => $merged['note'] ?? 'Dry-run 11F-8; no se aplica cambio masivo sin aprobación.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzeAuto(): array
    {
        $base = $this->analyzeNameVariants(['Auto', 'Autos'], [
            'suggested_parent' => 'Automotor',
            'suggested_as' => 'categoría Automotor con subs por descripción',
            'propuesta' => 'EGRESO → Automotor → (sub inequívoca)',
            'confidence' => 'MIXTA',
            'auto_migrate' => false,
            'note' => 'Solo auto-aplicar si la descripción es inequívoca; resto a export ambiguos.',
        ]);

        $breakdown = [];
        $unequivocal = 0;
        $ambiguous = 0;
        foreach ($base['concepts_sample'] as $row) {
            // sample incompleto; recalcular sobre todos vía planner helper
        }

        $names = ['Auto', 'Autos'];
        $movements = Movement::query()
            ->posted()
            ->where(function ($q) use ($names) {
                $q->whereHas('category', fn ($c) => $c->where(function ($qq) {
                    $qq->whereRaw('LOWER(name) = ?', ['auto'])->orWhereRaw('LOWER(name) = ?', ['autos']);
                }))
                    ->orWhereHas('subcategory', fn ($c) => $c->where(function ($qq) {
                        $qq->whereRaw('LOWER(name) = ?', ['auto'])->orWhereRaw('LOWER(name) = ?', ['autos']);
                    }));
                foreach ($names as $n) {
                    $q->orWhere('description', 'like', '%'.$n.'%');
                }
            })
            ->get(['id', 'description']);

        foreach ($movements as $m) {
            $sub = $this->planner->inferAutoSubcategory((string) $m->description);
            if ($sub === null) {
                $ambiguous++;
                $breakdown['(ambiguo)'] = ($breakdown['(ambiguo)'] ?? 0) + 1;
            } else {
                $unequivocal++;
                $breakdown[$sub] = ($breakdown[$sub] ?? 0) + 1;
            }
        }
        arsort($breakdown);

        $base['proposal']['subcategory_breakdown'] = $breakdown;
        $base['proposal']['unequivocal'] = $unequivocal;
        $base['proposal']['ambiguous'] = $ambiguous;
        $base['movement_count'] = $movements->count();

        return $base;
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
                'suggested_as' => 'subcategoría Comidas',
                'confidence' => $movements->count() > 0 ? 'ALTA' : 'BAJA',
            ],
            'auto', 'autos' => [
                'suggested_parent' => 'Automotor',
                'confidence' => 'MIXTA',
            ],
            'miranda' => [
                'suggested_parent' => 'Gastos familiares',
                'confidence' => 'ALTA',
            ],
            'myu', 'muebles y útiles', 'muebles y utiles' => [
                'suggested_label' => 'Muebles y útiles',
                'confidence' => 'ALTA',
            ],
            'remotos' => [
                'suggested_parent' => 'Servicios profesionales',
                'confidence' => 'ALTA',
            ],
            'super' => [
                'suggested_parent' => 'Alimentación',
                'suggested_as' => 'Supermercado',
                'confidence' => 'ALTA',
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
