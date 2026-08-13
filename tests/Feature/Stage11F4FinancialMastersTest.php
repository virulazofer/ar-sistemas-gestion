<?php

use App\Enums\AccountType;
use App\Enums\ChartAccountType;
use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\ClientLedgerEntry;
use App\Models\ExchangeRate;
use App\Models\FinancialAccount;
use App\Models\Movement;
use App\Models\Product;
use App\Rules\CbuCvu;
use App\Rules\Cuit;
use App\Services\Catalog\ProductService;
use App\Services\Clients\ClientLedgerService;
use App\Services\Finance\ExchangeRateService;
use App\Services\Finance\MovementService;
use App\Support\Appearance;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ExchangeRateSeeder;
use Database\Seeders\FinancialAccountSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(CurrencySeeder::class);
});

test('apariencia mode y palette con aria y persistencia ajax', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);

    $html = $this->get(route('movements.quick'))->assertOk()->getContent();
    expect($html)->toContain('role="radiogroup"')
        ->and($html)->toContain('aria-checked')
        ->and($html)->toContain('ar-appearance-choice');

    foreach (Appearance::modes() as $mode) {
        foreach (Appearance::palettes() as $palette) {
            $this->postJson(route('theme.update'), [
                'theme' => $mode,
                'palette' => $palette,
            ])->assertOk()->assertJsonPath('palette', $palette);

            expect($admin->fresh()->theme)->toBe($mode)
                ->and($admin->fresh()->appearancePalette())->toBe($palette);
        }
    }
});

test('backfill ArgentinaDatos es idempotente y rateForDate usa ultima previa', function () {
    Http::fake([
        'api.argentinadatos.com/*' => Http::response([
            ['fecha' => '2026-01-02', 'compra' => 1000, 'venta' => 1010, 'casa' => 'oficial'],
            ['fecha' => '2026-01-05', 'compra' => 1010, 'venta' => 1020, 'casa' => 'oficial'],
        ], 200),
    ]);

    $svc = app(ExchangeRateService::class);
    $preview = $svc->previewArgentinaDatosBackfill('2026-01-01', '2026-01-10');
    expect($preview['api_rows'])->toBe(2)->and($preview['to_import'])->toBe(2);

    $r1 = $svc->backfillFromArgentinaDatos('2026-01-01', '2026-01-10');
    expect($r1['imported'])->toBe(2);
    $r2 = $svc->backfillFromArgentinaDatos('2026-01-01', '2026-01-10');
    expect($r2['imported'])->toBe(0)->and($r2['skipped'])->toBe(2);

    $weekend = $svc->rateForDate('2026-01-04');
    expect($weekend)->not->toBeNull()
        ->and($weekend->rate)->toBe('1010.000000');

    $this->artisan('exchange-rates:backfill', ['--from' => '2026-01-01', '--to' => '2026-01-10', '--preview' => true])
        ->assertSuccessful();
});

test('apertura manual CC positiva = deuda ledger y control_cc_desde', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $this->seed(ExchangeRateSeeder::class);

    $client = Client::query()->create(['name' => 'CC Apertura', 'status' => 'active']);
    $entry = app(ClientLedgerService::class)->registerOpeningBalance($client, [
        'currency_code' => 'USD',
        'balance' => '250.00',
        'reason' => 'Saldo inicial auditoría',
        'entry_date' => '2026-03-01',
        'set_control_desde' => true,
    ]);

    expect($entry->regularization_kind)->toBe('opening_balance')
        ->and((string) $entry->signed_amount)->toBe('-250.00')
        ->and($client->fresh()->control_cc_desde?->toDateString())->toBe('2026-03-01');

    $this->get(route('clients.ledger.opening.create', $client))->assertOk()->assertSee('Apertura');
});

test('plan de cuentas CRUD y mapa', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);

    $parent = ChartAccount::query()->create([
        'code' => '1',
        'name' => 'Activo',
        'type' => ChartAccountType::Asset,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $this->post(route('chart-accounts.store'), [
        'code' => '1.1',
        'name' => 'Caja',
        'type' => ChartAccountType::Asset->value,
        'parent_id' => $parent->id,
        'is_active' => 1,
        'sort_order' => 10,
    ])->assertRedirect();

    expect(ChartAccount::query()->where('code', '1.1')->exists())->toBeTrue();
    $this->get(route('chart-accounts.index'))->assertOk()->assertSee('1.1');
    $this->get(route('chart-accounts.map'))->assertRedirect(route('chart-accounts.index'));
});

test('categoria detalle muestra totales de periodo', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $this->seed(FinancialAccountSeeder::class);
    $this->seed(ExchangeRateSeeder::class);

    $cat = Category::query()->create([
        'name' => 'Test Cat',
        'scope' => 'professional',
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $account = FinancialAccount::query()->firstOrFail();
    $today = now()->toDateString();
    $movement = app(MovementService::class)->createSimple([
        'type' => 'expense',
        'scope' => 'professional',
        'financial_account_id' => $account->id,
        'amount' => '100',
        'category_id' => $cat->id,
        'movement_date' => $today,
        'description' => 'gasto test',
    ]);

    expect((int) $movement->category_id)->toBe((int) $cat->id)
        ->and($movement->isPosted())->toBeTrue()
        ->and($movement->movement_date?->toDateString())->toBe($today);

    $found = Movement::query()
        ->where('category_id', $cat->id)
        ->posted()
        ->whereDate('movement_date', '>=', $today)
        ->whereDate('movement_date', '<=', $today)
        ->count();
    expect($found)->toBe(1);

    $this->get(route('categories.show', ['category' => $cat, 'from' => $today, 'to' => $today]))
        ->assertOk()
        ->assertSee('Test Cat')
        ->assertSee('Total ARS')
        ->assertSee('gasto test');
});

test('cuenta financiera valida CBU CUIT y tarjeta sin CVV', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $currencyId = \App\Models\Currency::query()->where('code', 'ARS')->value('id');

    expect(CbuCvu::normalize('285-05909-400941-813520-12'))->toBe('2850590940094181352012');
    expect(Cuit::isValidChecksum('20111111112'))->toBeBool();

    $base = '2012345678';
    $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
    $sum = 0;
    for ($i = 0; $i < 10; $i++) {
        $sum += (int) $base[$i] * $weights[$i];
    }
    $mod = $sum % 11;
    $check = 11 - $mod;
    if ($check === 11) {
        $check = 0;
    } elseif ($check === 10) {
        $check = 9;
    }
    $validCuit = $base.$check;
    expect(Cuit::isValidChecksum($validCuit))->toBeTrue();

    $this->post(route('accounts.store'), [
        'name' => 'Banco Test',
        'type' => AccountType::Bank->value,
        'currency_id' => $currencyId,
        'status' => 'active',
        'cbu_cvu' => '285059094009418135201', // 21 digits — should fail
    ])->assertSessionHasErrors('cbu_cvu');

    $this->post(route('accounts.store'), [
        'name' => 'Banco Test',
        'type' => AccountType::Bank->value,
        'currency_id' => $currencyId,
        'status' => 'active',
        'cbu_cvu' => '2850590940094181352012',
        'cuit' => $validCuit,
    ])->assertRedirect();

    $acc = FinancialAccount::query()->where('name', 'Banco Test')->firstOrFail();
    expect($acc->cbu_cvu)->toBe('2850590940094181352012')
        ->and($acc->cuit)->toBe($validCuit);

    $this->post(route('accounts.store'), [
        'name' => 'Visa Corp',
        'type' => AccountType::CreditCard->value,
        'currency_id' => $currencyId,
        'status' => 'active',
        'card_last4' => '4242',
        'card_brand' => 'Visa',
        'card_holder' => 'ACME SA',
        'card_expiry_month' => 12,
        'card_expiry_year' => 2030,
        'card_pan_full' => '4111111111111111',
    ])->assertRedirect();

    $card = FinancialAccount::query()->where('name', 'Visa Corp')->firstOrFail();
    expect($card->card_last4)->toBe('4242')
        ->and($card->is_liability)->toBeTrue();

    $this->get(route('accounts.index'))->assertOk()->assertSee('Banco Test')->assertSee('sin CVV')->assertDontSee('name="cvv"', false);
});

test('listado movimientos columnas y colores semánticos', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $this->seed(FinancialAccountSeeder::class);
    $this->seed(ExchangeRateSeeder::class);

    $account = FinancialAccount::query()->firstOrFail();
    app(MovementService::class)->createSimple([
        'type' => 'income',
        'scope' => 'professional',
        'financial_account_id' => $account->id,
        'amount' => '50',
        'description' => 'ingreso semántico',
        'movement_date' => now()->toDateString(),
    ]);

    $this->get(route('movements.index'))
        ->assertOk()
        ->assertSee('Cuenta financiera')
        ->assertSee('Cuenta contable')
        ->assertSee('Descripción')
        ->assertSee('semantic-amount')
        ->assertSee('ingreso semántico');
});

test('productos columnas sin precio y bulk archive', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);

    $orphan = Product::query()->create([
        'sku' => 'SKU-ORPHAN',
        'name' => 'Huérfano',
        'type' => 'service',
        'status' => 'active',
        'qty_on_hand' => 0,
        'qty_reserved' => 0,
    ]);
    $kept = Product::query()->create([
        'sku' => 'SKU-KEEP',
        'name' => 'Con relacion',
        'type' => 'physical',
        'status' => 'active',
        'qty_on_hand' => 1,
        'qty_reserved' => 0,
    ]);

    $location = \App\Models\InventoryLocation::query()->first();
    if (! $location) {
        $location = \App\Models\InventoryLocation::query()->create([
            'name' => 'Default',
            'code' => 'DEF',
            'is_active' => true,
        ]);
    }

    \App\Models\InventoryLot::query()->create([
        'product_id' => $kept->id,
        'inventory_location_id' => $location->id,
        'qty_received' => 1,
        'qty_remaining' => 1,
        'unit_cost' => 1,
        'currency_id' => \App\Models\Currency::query()->where('code', 'USD')->value('id'),
        'received_at' => now(),
        'status' => 'open',
        'unit_cost_ars' => 1000,
        'unit_cost_usd' => 1,
        'exchange_rate_value' => 1000,
    ]);

    $this->get(route('products.index'))
        ->assertOk()
        ->assertSee('SKU')
        ->assertSee('Familia')
        ->assertSee('Stock')
        ->assertSee('Precio')
        ->assertSee('sale_price');

    $this->post(route('products.bulk-destroy'), [
        'ids' => [$orphan->id, $kept->id],
        'reason' => 'Limpieza 11F-6',
    ])
        ->assertRedirect();

    expect(Product::query()->find($orphan->id))->toBeNull()
        ->and(Product::query()->find($kept->id)?->status)->toBe('inactive');
});

test('movimiento valua con cotizacion de la fecha del movimiento', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $this->seed(FinancialAccountSeeder::class);

    app(ExchangeRateService::class)->storeHistoricalImport('2026-02-01', '1000', '990');
    app(ExchangeRateService::class)->storeHistoricalImport('2026-02-10', '1100', '1090');

    $account = FinancialAccount::query()->whereHas('currency', fn ($q) => $q->where('code', 'USD'))->firstOrFail();
    $m = app(MovementService::class)->createSimple([
        'type' => 'income',
        'scope' => 'professional',
        'financial_account_id' => $account->id,
        'amount' => '10',
        'movement_date' => '2026-02-03',
        'description' => 'fx date',
    ]);

    expect($m->exchange_rate_value)->toBe('1000.000000');
    // later rate exists but frozen value unchanged
    expect(ExchangeRate::query()->whereDate('rate_at', '2026-02-10')->exists())->toBeTrue();
    expect($m->fresh()->exchange_rate_value)->toBe('1000.000000');
});

test('venta create UI menciona margen sobre costo', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $this->get(route('sales.create'))
        ->assertOk()
        ->assertSee('margen')
        ->assertSee('Venta de equipos');
});
