<?php

use App\Models\ExchangeRate;
use App\Services\Finance\ExchangeRateImportService;
use App\Services\Finance\ExchangeRateService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ExchangeRateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function seedFxModule(): void
{
    test()->seed(CurrencySeeder::class);
}

test('exchange-rates:update guarda cotizacion oficial y es idempotente', function () {
    seedFxModule();
    Http::fake([
        'dolarapi.com/*' => Http::response([
            'compra' => 1400.5,
            'venta' => 1450.75,
            'fechaActualizacion' => '2026-08-08T12:00:00.000Z',
        ], 200),
    ]);

    $this->artisan('exchange-rates:update')->assertSuccessful();

    expect(ExchangeRate::count())->toBe(1);
    $rate = ExchangeRate::first();
    expect($rate->rate)->toBe('1450.750000');
    expect($rate->rate_buy)->toBe('1400.500000');
    expect($rate->source)->toBe('api');
    expect($rate->provider)->toBe('dolarapi');

    $this->artisan('exchange-rates:update')->assertSuccessful();
    expect(ExchangeRate::count())->toBe(1);
});

test('exchange-rates:update conserva ultima valida si falla la API', function () {
    seedFxModule();
    app(ExchangeRateService::class)->storeManual('1500', 'base', '1490');
    expect(ExchangeRate::count())->toBe(1);

    Http::fake([
        'dolarapi.com/*' => Http::response('error', 500),
    ]);

    $this->artisan('exchange-rates:update')->assertFailed();
    expect(ExchangeRate::count())->toBe(1);
    expect(ExchangeRate::first()->rate)->toBe('1500.000000');
});

test('importacion historica CSV preview y confirm evita duplicados', function () {
    $admin = makeAdmin();
    seedFxModule();
    $this->actingAs($admin);

    Storage::fake('local');
    $csv = "fecha,compra,venta\n2026-01-02,1000,1010\n2026-01-03,1010,1020\ninvalid,x,y\n2026-01-02,1000,1010\n";
    $file = UploadedFile::fake()->createWithContent('hist.csv', $csv);

    $preview = app(ExchangeRateImportService::class)->parseAndPreview($file);
    expect($preview['rows_valid'])->toBe(2);
    expect($preview['rows_invalid'])->toBe(1);
    expect($preview['rows_duplicate'])->toBe(1);

    $result = app(ExchangeRateImportService::class)->confirm($preview);
    expect($result['imported'])->toBe(2);
    expect(ExchangeRate::where('source', 'historical_import')->count())->toBe(2);

    // Segunda confirmación del mismo preview no duplica
    $again = app(ExchangeRateImportService::class)->confirm($preview);
    expect($again['imported'])->toBe(0);
    expect(ExchangeRate::where('source', 'historical_import')->count())->toBe(2);
});

test('UI cotizaciones muestra vigente historial e importacion', function () {
    $admin = makeAdmin();
    seedFxModule();
    test()->seed(ExchangeRateSeeder::class);
    $this->actingAs($admin);

    $this->get(route('exchange-rates.index'))
        ->assertOk()
        ->assertSee('Cotización actual')
        ->assertSee('Filtrar historial')
        ->assertSee('Importar histórico');

    $this->get(route('exchange-rates.import'))
        ->assertOk()
        ->assertSee('fecha')
        ->assertSee('venta');
});

test('movimientos siguen congelando cotizacion historica', function () {
    $admin = makeAdmin();
    seedFxModule();
    test()->seed(Database\Seeders\FinancialAccountSeeder::class);
    $this->actingAs($admin);

    $rate = app(ExchangeRateService::class)->storeManual('1500', 'freeze', '1490');
    $account = \App\Models\FinancialAccount::where('name', 'Caja ARS')->firstOrFail();

    $movement = app(\App\Services\Finance\MovementService::class)->createSimple([
        'type' => 'income',
        'scope' => 'personal',
        'financial_account_id' => $account->id,
        'amount' => '100',
        'exchange_rate_id' => $rate->id,
    ]);

    app(ExchangeRateService::class)->storeManual('2000', 'nueva');
    expect((string) $movement->fresh()->exchange_rate_value)->toBe('1500.000000');
});
