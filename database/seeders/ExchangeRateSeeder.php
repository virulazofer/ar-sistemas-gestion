<?php

namespace Database\Seeders;

use App\Services\Finance\ExchangeRateService;
use Illuminate\Database\Seeder;

class ExchangeRateSeeder extends Seeder
{
    public function run(): void
    {
        // Cotización inicial local para desarrollo/offline (no depende de la API).
        app(ExchangeRateService::class)->storeManual('1450.000000', 'Cotización semilla Etapa 2');
    }
}
