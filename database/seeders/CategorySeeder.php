<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\Subcategory;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $expensePersonal = ChartAccount::query()->where('code', '5.1')->first();
        $expenseProfessional = ChartAccount::query()->where('code', '5.2')->first();
        $incomePersonal = ChartAccount::query()->where('code', '4.1')->first();
        $incomeProfessional = ChartAccount::query()->where('code', '4.2')->first();

        $definitions = [
            [
                'name' => 'Alimentación',
                'scope' => 'personal',
                'chart' => $expensePersonal,
                'subs' => ['Supermercado', 'Carnicería', 'Delivery', 'Otros'],
            ],
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
            [
                'name' => 'Servicios',
                'scope' => 'professional',
                'chart' => $expenseProfessional,
                'subs' => ['Mantenimiento', 'Reparación', 'Instalación', 'Consultoría'],
            ],
            [
                'name' => 'Ingresos profesionales',
                'scope' => 'professional',
                'chart' => $incomeProfessional,
                'subs' => ['Ventas', 'Servicios', 'Abonos', 'Otros'],
            ],
        ];

        foreach ($definitions as $i => $def) {
            $category = Category::query()->updateOrCreate(
                ['name' => $def['name'], 'scope' => $def['scope']],
                [
                    'chart_account_id' => $def['chart']?->id,
                    'is_active' => true,
                    'sort_order' => ($i + 1) * 10,
                ]
            );

            foreach ($def['subs'] as $j => $subName) {
                Subcategory::query()->updateOrCreate(
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
