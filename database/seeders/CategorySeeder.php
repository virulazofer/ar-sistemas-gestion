<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\Subcategory;
use App\Services\Finance\ApprovedTaxonomyService;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        // Taxonomía canónica 11F-8 (no destruye históricas homónimas).
        app(ApprovedTaxonomyService::class)->ensureCanonical(write: true);

        $expensePersonal = ChartAccount::query()->where('code', '5.1')->first();
        $expenseProfessional = ChartAccount::query()->where('code', '5.2')->first();
        $incomePersonal = ChartAccount::query()->where('code', '4.1')->first();

        // Complementos habituales no cubiertos por el núcleo aprobado.
        $extras = [
            [
                'name' => 'Vivienda',
                'scope' => 'personal',
                'chart' => $expensePersonal,
                'subs' => ['Alquiler', 'Expensas', 'Electricidad', 'Gas', 'Agua', 'Internet'],
            ],
            [
                'name' => 'Transporte',
                'scope' => 'personal',
                'chart' => $expensePersonal,
                'subs' => ['Combustible', 'Transporte público', 'Mantenimiento'],
            ],
            [
                'name' => 'Ingresos personales',
                'scope' => 'personal',
                'chart' => $incomePersonal,
                'subs' => ['Sueldo', 'Otros ingresos'],
            ],
            [
                'name' => 'Hardware',
                'scope' => 'professional',
                'chart' => $expenseProfessional,
                'subs' => ['Componentes', 'Periféricos', 'Otros'],
            ],
        ];

        foreach ($extras as $i => $def) {
            $category = Category::query()->firstOrCreate(
                ['name' => $def['name'], 'scope' => $def['scope']],
                [
                    'chart_account_id' => $def['chart']?->id,
                    'is_active' => true,
                    'sort_order' => 100 + ($i + 1) * 10,
                    'excel_name' => $def['name'],
                    'default_scope' => $def['scope'],
                ]
            );

            foreach ($def['subs'] as $j => $subName) {
                Subcategory::query()->firstOrCreate(
                    ['category_id' => $category->id, 'name' => $subName],
                    [
                        'chart_account_id' => $def['chart']?->id,
                        'is_active' => true,
                        'sort_order' => ($j + 1) * 10,
                    ]
                );
            }
        }
    }
}
