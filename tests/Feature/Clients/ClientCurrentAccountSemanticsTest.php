<?php

use App\Models\Client;
use App\Services\Clients\ClientCurrentAccountRankingService;
use App\Services\Clients\ClientLedgerService;
use App\Services\Dashboard\DashboardService;
use App\Support\UiSemantics;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ExchangeRateSeeder;
use Database\Seeders\FinancialAccountSeeder;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->seed(CurrencySeeder::class);
    $this->seed(FinancialAccountSeeder::class);
    $this->seed(ExchangeRateSeeder::class);
    Cache::flush();
});

it('UiSemantics resultado: positivo favorable, negativo atención, cero neutro', function () {
    expect(UiSemantics::tone('100.00', UiSemantics::MODE_RESULT))->toBe(UiSemantics::TONE_FAVORABLE)
        ->and(UiSemantics::tone('-50.00', UiSemantics::MODE_RESULT))->toBe(UiSemantics::TONE_ATTENTION)
        ->and(UiSemantics::tone('0.00', UiSemantics::MODE_RESULT))->toBe(UiSemantics::TONE_NEUTRAL)
        ->and(UiSemantics::cssClass('10.00', UiSemantics::MODE_RESULT))->toContain('semantic-amount--favorable')
        ->and(UiSemantics::cssClass('-10.00', UiSemantics::MODE_RESULT))->toContain('semantic-amount--attention')
        ->and(UiSemantics::cssClass('0', UiSemantics::MODE_RESULT))->toContain('semantic-amount--neutral');
});

it('UiSemantics CC clientes: positivo atención (nos deben), negativo favorable, cero neutro', function () {
    expect(UiSemantics::tone('250.00', UiSemantics::MODE_CLIENT_CC))->toBe(UiSemantics::TONE_ATTENTION)
        ->and(UiSemantics::tone('-80.00', UiSemantics::MODE_CLIENT_CC))->toBe(UiSemantics::TONE_FAVORABLE)
        ->and(UiSemantics::tone('0.00', UiSemantics::MODE_CLIENT_CC))->toBe(UiSemantics::TONE_NEUTRAL)
        ->and(UiSemantics::kpiClass('100.00', UiSemantics::MODE_CLIENT_CC))->toBe('ar-kpi-negative')
        ->and(UiSemantics::kpiClass('-100.00', UiSemantics::MODE_CLIENT_CC))->toBe('ar-kpi-positive')
        ->and(UiSemantics::kpiClass('0.00', UiSemantics::MODE_CLIENT_CC))->toBe('ar-kpi-zero');
});

it('convierte saldo ledger a saldo presentación CC sin mutar signos DB', function () {
    // Ledger: − = deuda → UI +
    expect(UiSemantics::clientCcDisplayBalance('-500.00'))->toBe('500.00')
        ->and(UiSemantics::clientCcDisplayBalance('120.00'))->toBe('-120.00')
        ->and(UiSemantics::clientCcDisplayBalance('0.00'))->toBe('0.00');
});

it('ranking CC default nos deben, orden descendente, ceros omitidos', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $ledger = app(ClientLedgerService::class);

    $big = Client::query()->create(['name' => 'Deudor Grande', 'status' => 'active']);
    $small = Client::query()->create(['name' => 'Deudor Chico', 'status' => 'active']);
    $credit = Client::query()->create(['name' => 'Cliente Credito', 'status' => 'active']);
    $settled = Client::query()->create(['name' => 'Cliente Saldado', 'status' => 'active']);

    $ledger->registerCharge($big, ['currency_code' => 'ARS', 'amount' => '1000', 'entry_date' => '2026-08-01']);
    $ledger->registerCharge($small, ['currency_code' => 'ARS', 'amount' => '200', 'entry_date' => '2026-08-02']);
    $ledger->registerCredit($credit, ['currency_code' => 'ARS', 'amount' => '50', 'entry_date' => '2026-08-03']);
    $ledger->registerCharge($settled, ['currency_code' => 'ARS', 'amount' => '80', 'entry_date' => '2026-08-01']);
    $cash = \App\Models\FinancialAccount::query()->whereHas('currency', fn ($q) => $q->where('code', 'ARS'))->firstOrFail();
    $ledger->registerPayment($settled, [
        'financial_account_id' => $cash->id,
        'amount' => '80',
        'entry_date' => '2026-08-04',
    ]);

    $data = app(ClientCurrentAccountRankingService::class)->build([]);
    expect($data['filter'])->toBe('owing')
        ->and(collect($data['rows'])->pluck('name')->all())->toBe(['Deudor Grande', 'Deudor Chico'])
        ->and($data['rows'][0]['balance'])->toBe('1000.00')
        ->and($data['summary']['owing_clients_count'])->toBe(2)
        ->and($data['summary']['to_collect']['ARS'])->toBe('1200.00')
        ->and($data['summary']['credit_clients_count'])->toBe(1)
        ->and($data['summary']['in_favor']['ARS'])->toBe('50.00');

    // Totales sin compensar: a cobrar ≠ neto (1200 − 50)
    expect($data['summary']['to_collect']['ARS'])->not->toBe('1150.00');
});

it('filtros ranking: saldo a favor, saldados, todos y búsqueda', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $ledger = app(ClientLedgerService::class);

    $owing = Client::query()->create(['name' => 'Alpha Deuda', 'status' => 'active']);
    $favor = Client::query()->create(['name' => 'Beta Favor', 'status' => 'active']);
    $settled = Client::query()->create(['name' => 'Gamma Zero', 'status' => 'active']);

    $ledger->registerCharge($owing, ['currency_code' => 'ARS', 'amount' => '300', 'entry_date' => '2026-08-01']);
    $ledger->registerCredit($favor, ['currency_code' => 'ARS', 'amount' => '40', 'entry_date' => '2026-08-01']);
    $ledger->registerCharge($settled, ['currency_code' => 'ARS', 'amount' => '10', 'entry_date' => '2026-08-01']);
    $cash = \App\Models\FinancialAccount::query()->whereHas('currency', fn ($q) => $q->where('code', 'ARS'))->firstOrFail();
    $ledger->registerPayment($settled, [
        'financial_account_id' => $cash->id,
        'amount' => '10',
        'entry_date' => '2026-08-02',
    ]);

    $svc = app(ClientCurrentAccountRankingService::class);

    $credit = $svc->build(['filter' => 'credit']);
    expect(collect($credit['rows'])->pluck('name')->all())->toBe(['Beta Favor'])
        ->and($credit['rows'][0]['balance'])->toBe('-40.00');

    $zeros = $svc->build(['filter' => 'settled']);
    expect(collect($zeros['rows'])->pluck('name')->all())->toContain('Gamma Zero')
        ->and($zeros['rows'][0]['balance'])->toBe('0.00');

    $all = $svc->build(['filter' => 'all']);
    $names = collect($all['rows'])->pluck('name')->all();
    expect($names)->toContain('Alpha Deuda')
        ->and($names)->toContain('Beta Favor')
        ->and($names)->not->toContain('Gamma Zero');

    $search = $svc->build(['filter' => 'owing', 'q' => 'Alpha']);
    expect(collect($search['rows'])->pluck('name')->all())->toBe(['Alpha Deuda']);
});

it('alerta dashboard clientes con deuda no apunta al ABM genérico', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $ledger = app(ClientLedgerService::class);
    $client = Client::query()->create(['name' => 'Deudor Alert', 'status' => 'active']);
    $ledger->registerCharge($client, ['currency_code' => 'ARS', 'amount' => '99', 'entry_date' => now()->toDateString()]);

    $snap = app(DashboardService::class)->snapshot('all');
    $alert = collect($snap['alerts'])->first(fn ($a) => str_contains($a['text'], 'clientes con deuda'));

    expect($alert)->not->toBeNull()
        ->and($alert['url'])->toBe(route('clients.current-accounts', ['filter' => 'owing']))
        ->and($alert['url'])->not->toBe(route('clients.index'));
});

it('vista ranking y detalle CC responden; default filter nos deben', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $ledger = app(ClientLedgerService::class);
    $client = Client::query()->create(['name' => 'Vista CC', 'status' => 'active']);
    $ledger->registerCharge($client, ['currency_code' => 'ARS', 'amount' => '150', 'entry_date' => '2026-08-01']);

    $this->actingAs($admin)
        ->get(route('clients.current-accounts'))
        ->assertOk()
        ->assertSee('Cuentas corrientes de clientes')
        ->assertSee('Vista CC')
        ->assertSee('Ver cuenta corriente')
        ->assertSee(route('clients.show', $client), false);

    $this->actingAs($admin)
        ->get(route('clients.show', $client))
        ->assertOk()
        ->assertSee('Saldo CC ARS')
        ->assertSee('ar-kpi-negative', false);
});
