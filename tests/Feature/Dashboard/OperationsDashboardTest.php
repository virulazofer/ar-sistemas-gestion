<?php

use App\Models\Movement;
use App\Services\Dashboard\DashboardService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ExchangeRateSeeder;
use Database\Seeders\FinancialAccountSeeder;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->seed(CurrencySeeder::class);
    $this->seed(FinancialAccountSeeder::class);
    Cache::flush();
});

it('renderiza dashboard operativo con base vacía (sin cotización) en todos los scopes', function (string $scope) {
    $admin = makeAdmin();

    $this->actingAs($admin)
        ->get(route('dashboard.operations', ['scope' => $scope]))
        ->assertOk()
        ->assertSee('Tablero operativo');
})->with(['personal', 'professional', 'all']);

it('cachea snapshot serializable y re-renderiza con cotización', function () {
    $this->seed(ExchangeRateSeeder::class);
    $admin = makeAdmin();

    $this->actingAs($admin)
        ->get(route('dashboard.operations', ['scope' => 'personal']))
        ->assertOk();

    $cached = app(DashboardService::class)->snapshot('personal');
    expect($cached['rate'])->toBeArray()
        ->and($cached['rate'])->toHaveKeys(['rate', 'rate_at', 'rate_at_label'])
        ->and($cached['stock']['last_in'])->toBeArray()
        ->and($cached['stock']['last_out'])->toBeArray();

    // Segunda lectura desde cache database (no debe producir incomplete object)
    $this->actingAs($admin)
        ->get(route('dashboard.operations', ['scope' => 'personal']))
        ->assertOk();

    $this->actingAs($admin)
        ->get(route('dashboard.operations', ['scope' => 'professional']))
        ->assertOk();

    expect(Movement::count())->toBeGreaterThanOrEqual(0);
});
