<?php

namespace Database\Seeders;

use App\Models\EquipmentComponentCategory;
use App\Models\EquipmentType;
use App\Services\Equipment\EquipmentTypeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class EquipmentCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'CPU', 'Motherboard', 'RAM', 'GPU', 'Storage', 'PSU', 'Case', 'Cooling', 'Network', 'Otros',
        ];

        $sort = 0;
        $catIds = [];
        foreach ($categories as $name) {
            $sort++;
            $cat = EquipmentComponentCategory::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'sort_order' => $sort, 'is_active' => true]
            );
            $catIds[$name] = $cat->id;
        }

        $service = app(EquipmentTypeService::class);

        $types = [
            'PC Oficina' => [
                'prefix' => 'PC',
                'items' => [
                    ['CPU', 1, 1, 1],
                    ['Motherboard', 1, 1, 1],
                    ['RAM', 1, 1, 2],
                    ['Storage', 1, 1, 2],
                    ['PSU', 1, 1, 1],
                    ['Case', 1, 1, 1],
                ],
            ],
            'PC Gamer' => [
                'prefix' => 'GM',
                'items' => [
                    ['CPU', 1, 1, 1],
                    ['Motherboard', 1, 1, 1],
                    ['RAM', 2, 2, 4],
                    ['GPU', 1, 1, 1],
                    ['Storage', 1, 1, 2],
                    ['PSU', 1, 1, 1],
                    ['Case', 1, 1, 1],
                    ['Cooling', 1, 1, 1],
                ],
            ],
            'Workstation' => ['prefix' => 'WS', 'items' => [['CPU', 1, 1, 2], ['Motherboard', 1, 1, 1], ['RAM', 2, 4, 8], ['Storage', 1, 2, 4], ['GPU', 0, 1, 2], ['PSU', 1, 1, 2], ['Case', 1, 1, 1]]],
            'Servidor' => ['prefix' => 'SV', 'items' => [['CPU', 1, 1, 4], ['Motherboard', 1, 1, 1], ['RAM', 2, 4, 16], ['Storage', 1, 4, 24], ['PSU', 1, 2, 2], ['Network', 0, 1, 4], ['Case', 1, 1, 1]]],
            'Notebook' => ['prefix' => 'NB', 'items' => [['Otros', 1, 1, 1]]],
            'Equipo de red' => ['prefix' => 'NET', 'items' => [['Network', 1, 1, 1], ['Otros', 0, 0, 4]]],
            'Otro' => ['prefix' => 'EQ', 'items' => [['Otros', 0, 1, 20]]],
        ];

        foreach ($types as $name => $cfg) {
            $type = EquipmentType::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'code_prefix' => $cfg['prefix'],
                    'next_sequence' => 1,
                    'is_active' => true,
                ]
            );

            foreach ($cfg['items'] as $i => $row) {
                [$catName, $min, $default, $max] = $row;
                $type->templateItems()->updateOrCreate(
                    [
                        'equipment_type_id' => $type->id,
                        'component_category_id' => $catIds[$catName],
                    ],
                    [
                        'qty_min' => $min,
                        'qty_default' => $default,
                        'qty_max' => $max,
                        'is_required' => $min > 0,
                        'allow_remove' => $min === 0,
                        'sort_order' => $i + 1,
                    ]
                );
            }
        }
    }
}
