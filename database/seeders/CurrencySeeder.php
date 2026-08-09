<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        Currency::query()->updateOrCreate(
            ['code' => 'ARS'],
            [
                'name' => 'Peso argentino',
                'symbol' => '$',
                'decimal_places' => 2,
                'is_active' => true,
                'is_base' => true,
            ]
        );

        Currency::query()->updateOrCreate(
            ['code' => 'USD'],
            [
                'name' => 'Dólar estadounidense',
                'symbol' => 'U$S',
                'decimal_places' => 2,
                'is_active' => true,
                'is_base' => false,
            ]
        );
    }
}
