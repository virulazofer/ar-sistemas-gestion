<?php

namespace Database\Seeders;

use App\Models\InventoryLocation;
use App\Models\ProductCategory;
use App\Models\ProductSubcategory;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class InventoryCatalogSeeder extends Seeder
{
    public function run(): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'stock.allow_negative'],
            [
                'value' => '0',
                'type' => 'bool',
                'group' => 'stock',
                'label' => 'Permitir stock negativo',
                'description' => 'Por defecto deshabilitado. Si se activa, permite salidas sin stock disponible.',
            ]
        );

        InventoryLocation::query()->updateOrCreate(
            ['code' => 'DEP'],
            [
                'name' => 'Depósito',
                'is_default' => true,
                'is_active' => true,
                'notes' => 'Ubicación principal',
            ]
        );

        InventoryLocation::query()->updateOrCreate(
            ['code' => 'TAL'],
            [
                'name' => 'Taller',
                'is_default' => false,
                'is_active' => true,
            ]
        );

        $tree = [
            'Hardware' => ['Procesadores', 'Motherboards', 'Memorias', 'Discos', 'GPU', 'Fuentes', 'Gabinetes', 'Refrigeración'],
            'Periféricos' => ['Teclados', 'Mouse', 'Monitores', 'Impresoras'],
            'Redes' => ['Switches', 'Routers', 'Access Points', 'Cableado'],
        ];

        $order = 0;
        foreach ($tree as $catName => $subs) {
            $order++;
            $category = ProductCategory::query()->updateOrCreate(
                ['slug' => Str::slug($catName)],
                ['name' => $catName, 'sort_order' => $order, 'is_active' => true]
            );

            $subOrder = 0;
            foreach ($subs as $subName) {
                $subOrder++;
                ProductSubcategory::query()->updateOrCreate(
                    [
                        'product_category_id' => $category->id,
                        'slug' => Str::slug($subName),
                    ],
                    [
                        'name' => $subName,
                        'sort_order' => $subOrder,
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
