<?php

namespace App\Services\Finance;

use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\Subcategory;

/**
 * Asegura (sin destruir) la taxonomía operativa aprobada 11F-8,
 * alineada al árbol estructural §2 (códigos del plan).
 */
class ApprovedTaxonomyService
{
    public function __construct(private readonly ChartConceptCompatibility $compat) {}

    /**
     * @return array{categories: list<array{id:int,name:string,scope:string}>, subcategories: list<array{id:int,name:string,category:string}>}
     */
    public function ensureCanonical(bool $write = true): array
    {
        $defs = [
            ['name' => 'Alimentación', 'scope' => 'personal', 'code' => '5.1', 'subs' => [
                'Supermercado', 'Comidas', 'Carnicería', 'Delivery', 'Otros',
            ]],
            ['name' => 'Servicios', 'scope' => 'both', 'code' => '5.2', 'subs' => [
                'Electricidad', 'Gas', 'Agua', 'Internet', 'Telefonía', 'Suscripciones', 'Streaming', 'Otros',
            ]],
            ['name' => 'Automotor', 'scope' => 'personal', 'code' => '5.3', 'subs' => [
                'Combustible', 'Seguro', 'Mantenimiento', 'Patente', 'Estacionamiento', 'Peajes', 'Lavado/Limpieza', 'Otros',
            ]],
            ['name' => 'Gastos familiares', 'scope' => 'personal', 'code' => '5.4', 'subs' => [
                'Miranda', 'Otros',
            ]],
            ['name' => 'Muebles y útiles', 'scope' => 'both', 'code' => '5.5', 'subs' => [
                'MYU', 'Otros',
            ], 'excel_name' => 'MYU'],
            ['name' => 'Ventas', 'scope' => 'professional', 'code' => '4.1', 'subs' => [
                'Equipos', 'Componentes', 'Otros productos',
            ]],
            ['name' => 'Servicios profesionales', 'scope' => 'professional', 'code' => '4.2', 'subs' => [
                'Abonos', 'Remotos', 'Reparaciones', 'Instalaciones', 'Consultoría', 'Otros',
            ]],
            ['name' => 'Ingresos financieros', 'scope' => 'both', 'code' => '4.3', 'subs' => [
                'Intereses', 'Rendimientos', 'Otros',
            ], 'excel_name' => 'Financieros'],
        ];

        $catsOut = [];
        $subsOut = [];

        foreach ($defs as $i => $def) {
            $chart = ChartAccount::query()->where('code', $def['code'])->first();

            if (! $write) {
                $existing = Category::query()
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($def['name'])])
                    ->first();
                $catsOut[] = [
                    'id' => $existing?->id ?? 0,
                    'name' => $def['name'],
                    'scope' => $def['scope'],
                    'exists' => (bool) $existing,
                ];
                foreach ($def['subs'] as $subName) {
                    $sub = $existing
                        ? Subcategory::query()->where('category_id', $existing->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($subName)])->first()
                        : null;
                    $subsOut[] = [
                        'id' => $sub?->id ?? 0,
                        'name' => $subName,
                        'category' => $def['name'],
                        'exists' => (bool) $sub,
                    ];
                }

                continue;
            }

            $scope = $def['scope'];
            $category = Category::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($def['name'])])->orderBy('id')->first();
            if ($category) {
                $category->update([
                    'chart_account_id' => $chart?->id ?? $category->chart_account_id,
                    'is_active' => true,
                    'excel_name' => $category->excel_name ?: ($def['excel_name'] ?? $def['name']),
                    'default_scope' => $category->default_scope ?: $scope,
                ]);
            } else {
                $category = Category::query()->create([
                    'name' => $def['name'],
                    'scope' => $scope === 'both' ? 'both' : $scope,
                    'chart_account_id' => $chart?->id,
                    'is_active' => true,
                    'sort_order' => ($i + 1) * 10,
                    'excel_name' => $def['excel_name'] ?? $def['name'],
                    'default_scope' => $scope,
                ]);
            }

            $catsOut[] = ['id' => $category->id, 'name' => $category->name, 'scope' => $category->scope];

            foreach ($def['subs'] as $j => $subName) {
                $subChart = $this->compat->chartForName($subName);
                $sub = Subcategory::query()
                    ->where('category_id', $category->id)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($subName)])
                    ->first();
                if ($sub) {
                    $sub->update([
                        'chart_account_id' => $subChart?->id ?? $sub->chart_account_id,
                        'is_active' => true,
                    ]);
                } else {
                    $sub = Subcategory::query()->create([
                        'category_id' => $category->id,
                        'name' => $subName,
                        'chart_account_id' => $subChart?->id,
                        'is_active' => true,
                        'sort_order' => ($j + 1) * 10,
                    ]);
                }
                $subsOut[] = ['id' => $sub->id, 'name' => $sub->name, 'category' => $category->name];
            }
        }

        return ['categories' => $catsOut, 'subcategories' => $subsOut];
    }

    public function findCategory(string $name): ?Category
    {
        return Category::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->orderBy('id')->first();
    }

    public function findSubcategory(string $categoryName, string $subName): ?Subcategory
    {
        $cat = $this->findCategory($categoryName);
        if (! $cat) {
            return null;
        }

        return Subcategory::query()
            ->where('category_id', $cat->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($subName)])
            ->first();
    }
}
