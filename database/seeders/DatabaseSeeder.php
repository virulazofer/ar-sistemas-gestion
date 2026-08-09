<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SettingsSeeder::class,
            AdminUserSeeder::class,
            CurrencySeeder::class,
            ChartAccountSeeder::class,
            CategorySeeder::class,
            FinancialAccountSeeder::class,
            ExchangeRateSeeder::class,
            InventoryCatalogSeeder::class,
            EquipmentCatalogSeeder::class,
            WorkOrderCatalogSeeder::class,
            CommercialCatalogSeeder::class,
        ]);
    }
}
