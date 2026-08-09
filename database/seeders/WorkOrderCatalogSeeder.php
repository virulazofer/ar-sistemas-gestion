<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\WorkOrderType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class WorkOrderCatalogSeeder extends Seeder
{
    public function run(): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'work_orders.next_sequence'],
            [
                'value' => '1',
                'type' => 'int',
                'group' => 'work_orders',
                'label' => 'Próximo número de OT',
                'description' => 'Secuencia para OT-######',
            ]
        );

        $types = [
            'Reparación',
            'Mantenimiento',
            'Actualización',
            'Instalación',
            'Soporte remoto',
            'Soporte presencial',
            'Armado',
            'Configuración',
            'Evento',
            'Otro',
        ];

        foreach ($types as $i => $name) {
            WorkOrderType::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'is_active' => true, 'sort_order' => $i + 1]
            );
        }
    }
}
