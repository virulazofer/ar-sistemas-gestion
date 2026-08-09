<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class CommercialCatalogSeeder extends Seeder
{
    public function run(): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'quotations.next_sequence'],
            [
                'value' => '1',
                'type' => 'int',
                'group' => 'quotations',
                'label' => 'Próximo número de presupuesto',
                'description' => 'Secuencia P-######',
            ]
        );

        Setting::query()->updateOrCreate(
            ['key' => 'sales.next_sequence'],
            [
                'value' => '1',
                'type' => 'int',
                'group' => 'sales',
                'label' => 'Próximo número de venta',
                'description' => 'Secuencia V-######',
            ]
        );
    }
}
