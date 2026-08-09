<?php

namespace Database\Seeders;

use App\Models\ChartAccount;
use Illuminate\Database\Seeder;

class ChartAccountSeeder extends Seeder
{
    public function run(): void
    {
        $roots = [
            ['code' => '1', 'name' => 'Activos', 'type' => 'asset', 'sort_order' => 10],
            ['code' => '2', 'name' => 'Pasivos', 'type' => 'liability', 'sort_order' => 20],
            ['code' => '3', 'name' => 'Patrimonio', 'type' => 'equity', 'sort_order' => 30],
            ['code' => '4', 'name' => 'Ingresos', 'type' => 'income', 'sort_order' => 40],
            ['code' => '5', 'name' => 'Gastos', 'type' => 'expense', 'sort_order' => 50],
            ['code' => '6', 'name' => 'Resultados', 'type' => 'result', 'sort_order' => 60],
        ];

        foreach ($roots as $row) {
            ChartAccount::query()->updateOrCreate(
                ['code' => $row['code']],
                [...$row, 'is_active' => true, 'parent_id' => null]
            );
        }

        $income = ChartAccount::query()->where('code', '4')->first();
        $expense = ChartAccount::query()->where('code', '5')->first();

        $children = [
            ['code' => '4.1', 'name' => 'Ingresos personales', 'type' => 'income', 'parent_id' => $income?->id, 'sort_order' => 1],
            ['code' => '4.2', 'name' => 'Ingresos profesionales', 'type' => 'income', 'parent_id' => $income?->id, 'sort_order' => 2],
            ['code' => '5.1', 'name' => 'Gastos personales', 'type' => 'expense', 'parent_id' => $expense?->id, 'sort_order' => 1],
            ['code' => '5.2', 'name' => 'Gastos profesionales', 'type' => 'expense', 'parent_id' => $expense?->id, 'sort_order' => 2],
        ];

        foreach ($children as $row) {
            ChartAccount::query()->updateOrCreate(
                ['code' => $row['code']],
                [...$row, 'is_active' => true]
            );
        }
    }
}
