<?php

namespace App\Services\Finance;

use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\Subcategory;

/**
 * Asegura (sin destruir) la taxonomía operativa aprobada 11F-8.
 * No elimina tablas ni categorías históricas; solo crea/actualiza destino canónico.
 */
class ApprovedTaxonomyService
{
    /**
     * @return array{categories: list<array{id:int,name:string,scope:string}>, subcategories: list<array{id:int,name:string,category:string}>}
     */
    public function ensureCanonical(bool $write = true): array
    {
        $expensePersonal = ChartAccount::query()->where('code', '5.1')->first();
        $expenseProfessional = ChartAccount::query()->where('code', '5.2')->first();
        $incomeProfessional = ChartAccount::query()->where('code', '4.2')->first();
        $incomePersonal = ChartAccount::query()->where('code', '4.1')->first();

        $defs = [
            // EGRESO
            ['name' => 'Alimentación', 'scope' => 'personal', 'chart' => $expensePersonal, 'subs' => [
                'Supermercado', 'Comidas', 'Carnicería', 'Delivery', 'Otros',
            ]],
            ['name' => 'Servicios', 'scope' => 'both', 'chart' => $expensePersonal, 'subs' => [
                'Electricidad', 'Gas', 'Agua', 'Internet', 'Telefonía', 'Streaming', 'Otros',
            ]],
            ['name' => 'Automotor', 'scope' => 'personal', 'chart' => $expensePersonal, 'subs' => [
                'Combustible', 'Seguro', 'Mantenimiento', 'Patente', 'Estacionamiento', 'Peajes', 'Lavado/Limpieza', 'Otros',
            ]],
            ['name' => 'Gastos familiares', 'scope' => 'personal', 'chart' => $expensePersonal, 'subs' => [
                'Miranda', 'Otros',
            ]],
            ['name' => 'Muebles y útiles', 'scope' => 'both', 'chart' => $expenseProfessional, 'subs' => [
                'MYU', 'Otros',
            ], 'excel_name' => 'MYU'],
            // INGRESO
            ['name' => 'Ventas', 'scope' => 'professional', 'chart' => $incomeProfessional, 'subs' => []],
            ['name' => 'Servicios profesionales', 'scope' => 'professional', 'chart' => $incomeProfessional, 'subs' => [
                'Abonos', 'Remotos', 'Reparaciones', 'Instalaciones', 'Consultoría', 'Otros',
            ]],
            ['name' => 'Financieros', 'scope' => 'both', 'chart' => $incomePersonal, 'subs' => [
                'Intereses', 'Otros',
            ]],
        ];

        $catsOut = [];
        $subsOut = [];

        foreach ($defs as $i => $def) {
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
                    'chart_account_id' => $category->chart_account_id ?: $def['chart']?->id,
                    'is_active' => true,
                    'excel_name' => $category->excel_name ?: ($def['excel_name'] ?? $def['name']),
                    'default_scope' => $category->default_scope ?: $scope,
                ]);
            } else {
                $category = Category::query()->create([
                    'name' => $def['name'],
                    'scope' => $scope === 'both' ? 'both' : $scope,
                    'chart_account_id' => $def['chart']?->id,
                    'is_active' => true,
                    'sort_order' => ($i + 1) * 10,
                    'excel_name' => $def['excel_name'] ?? $def['name'],
                    'default_scope' => $scope,
                ]);
            }

            // No eliminamos filas históricas homónimas.
            $catsOut[] = ['id' => $category->id, 'name' => $category->name, 'scope' => $category->scope];

            foreach ($def['subs'] as $j => $subName) {
                $sub = Subcategory::query()->updateOrCreate(
                    ['category_id' => $category->id, 'name' => $subName],
                    [
                        'chart_account_id' => $def['chart']?->id,
                        'is_active' => true,
                        'sort_order' => ($j + 1) * 10,
                    ]
                );
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
