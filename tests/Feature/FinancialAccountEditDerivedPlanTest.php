<?php

use App\Enums\AccountType;
use App\Enums\ChartAccountType;
use App\Models\ChartAccount;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\Movement;
use App\Services\Finance\ChartAccountWorkspaceService;
use App\Services\Finance\FinancialAccountChartLinker;
use Database\Seeders\ChartAccountSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\FinancialAccountSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CurrencySeeder::class);
    $this->seed(ChartAccountSeeder::class);
});

function ensureFaLocations(): void
{
    foreach (['1.1.1', '1.1.2', '1.1.3', '2.1'] as $code) {
        ChartAccount::query()->firstOrCreate(
            ['code' => $code],
            [
                'name' => 'Loc '.$code,
                'type' => str_starts_with($code, '2') ? ChartAccountType::Liability : ChartAccountType::Asset,
                'is_active' => true,
                'sort_order' => 1,
            ]
        );
    }
}

function arsId(): int
{
    return (int) Currency::query()->where('code', 'ARS')->value('id');
}

test('editar banco billetera efectivo tarjeta sin validation.string', function () {
    ensureFaLocations();
    $admin = makeAdmin();
    $this->actingAs($admin);
    $currencyId = arsId();

    $bank = FinancialAccount::query()->create([
        'name' => 'Banco Edit',
        'type' => AccountType::Bank,
        'currency_id' => $currencyId,
        'status' => 'active',
        'cached_balance' => 0,
    ]);
    app(FinancialAccountChartLinker::class)->link($bank, force: true);

    $wallet = FinancialAccount::query()->create([
        'name' => 'Wallet Edit',
        'type' => AccountType::Wallet,
        'currency_id' => $currencyId,
        'status' => 'active',
        'cached_balance' => 0,
    ]);
    app(FinancialAccountChartLinker::class)->link($wallet, force: true);

    $cash = FinancialAccount::query()->create([
        'name' => 'Cash Edit',
        'type' => AccountType::Cash,
        'currency_id' => $currencyId,
        'status' => 'active',
        'cached_balance' => 0,
    ]);
    app(FinancialAccountChartLinker::class)->link($cash, force: true);

    $card = FinancialAccount::query()->create([
        'name' => 'Card Edit',
        'type' => AccountType::CreditCard,
        'currency_id' => $currencyId,
        'status' => 'active',
        'is_liability' => true,
        'card_last4' => '1111',
        'card_brand' => 'Visa',
        'card_expiry_month' => 12,
        'card_expiry_year' => 2030,
        'cached_balance' => 0,
    ]);
    app(FinancialAccountChartLinker::class)->link($card, force: true);

    // Simula payload browser: campos de tarjeta vacíos → null (ConvertEmptyStringsToNull).
    $this->put(route('accounts.update', $bank), [
        'name' => 'Banco Edit OK',
        'type' => AccountType::Bank->value,
        'status' => 'active',
        'alias' => 'BE',
        'institution' => 'Patagonia',
        'holder_name' => 'Titular Banco',
        'cbu_cvu' => '0170099120000004785295',
        'description' => '',
        'external_identifier' => '123',
        'card_number' => null,
        'card_last4' => null,
        'card_brand' => null,
        'card_holder' => null,
        'card_expiry_month' => null,
        'card_expiry_year' => null,
    ])->assertRedirect(route('accounts.index'))
        ->assertSessionDoesntHaveErrors();

    expect($bank->fresh()->name)->toBe('Banco Edit OK')
        ->and($bank->fresh()->institution)->toBe('Patagonia');

    $this->put(route('accounts.update', $wallet), [
        'name' => 'Wallet Edit OK',
        'type' => AccountType::Wallet->value,
        'status' => 'active',
        'institution' => 'Mercado Pago',
        'holder_name' => 'Titular MP',
        'cbu_cvu' => '0000003100010000000001',
    ])->assertRedirect(route('accounts.index'));

    $this->put(route('accounts.update', $cash), [
        'name' => 'Cash Edit OK',
        'type' => AccountType::Cash->value,
        'status' => 'active',
        'holder_name' => 'Caja',
    ])->assertRedirect(route('accounts.index'));

    $this->put(route('accounts.update', $card), [
        'name' => 'Card Edit OK',
        'type' => AccountType::CreditCard->value,
        'status' => 'active',
        'institution' => 'Patagonia',
        'card_brand' => 'Visa',
        'card_holder' => 'Titular',
        'card_last4' => '1111',
        'card_expiry_month' => 12,
        'card_expiry_year' => 2030,
        'card_number' => null,
    ])->assertRedirect(route('accounts.index'));

    $html = $this->followingRedirects()
        ->put(route('accounts.update', $bank->fresh()), [
            'name' => 'Banco Edit OK',
            'type' => AccountType::Bank->value,
            'status' => 'active',
            'institution' => 'Patagonia',
        ])->assertOk()->getContent();

    expect($html)->not->toContain('validation.string');
});

test('errores reales en castellano nunca validation.string crudo', function () {
    ensureFaLocations();
    $this->actingAs(makeAdmin());
    $fa = FinancialAccount::query()->create([
        'name' => 'Banco Err',
        'type' => AccountType::Bank,
        'currency_id' => arsId(),
        'status' => 'active',
        'cached_balance' => 0,
    ]);

    $this->from(route('accounts.edit', $fa))
        ->put(route('accounts.update', $fa), [
            'name' => '',
            'type' => AccountType::Bank->value,
            'status' => 'active',
        ])->assertSessionHasErrors('name');

    $errors = session('errors');
    expect($errors->first('name'))->not->toBe('validation.string')
        ->and($errors->first('name'))->not->toContain('validation.')
        ->and(strtolower($errors->first('name')))->toContain('nombre');
});

test('vista derivada plan por tipo sin nodos contables individuales', function () {
    ensureFaLocations();
    $this->actingAs(makeAdmin());
    $currencyId = arsId();

    $bank = FinancialAccount::query()->create([
        'name' => 'Banco Derivado',
        'type' => AccountType::Bank,
        'currency_id' => $currencyId,
        'status' => 'active',
        'cached_balance' => 10,
    ]);
    $wallet = FinancialAccount::query()->create([
        'name' => 'Billetera Derivada',
        'type' => AccountType::Wallet,
        'currency_id' => $currencyId,
        'status' => 'active',
        'cached_balance' => 20,
    ]);
    $cash = FinancialAccount::query()->create([
        'name' => 'Efectivo Derivado',
        'type' => AccountType::Cash,
        'currency_id' => $currencyId,
        'status' => 'active',
        'cached_balance' => 30,
    ]);
    $card = FinancialAccount::query()->create([
        'name' => 'Visa Derivada',
        'type' => AccountType::CreditCard,
        'currency_id' => $currencyId,
        'status' => 'active',
        'is_liability' => true,
        'card_last4' => '4242',
        'card_expiry_month' => 11,
        'card_expiry_year' => 2031,
        'cached_balance' => -50,
    ]);
    $linker = app(FinancialAccountChartLinker::class);
    foreach ([$bank, $wallet, $cash, $card] as $fa) {
        $linker->link($fa, force: true);
    }

    $ws = app(ChartAccountWorkspaceService::class);
    $bancos = ChartAccount::query()->where('code', '1.1.2')->firstOrFail();
    $billeteras = ChartAccount::query()->where('code', '1.1.3')->firstOrFail();
    $caja = ChartAccount::query()->where('code', '1.1.1')->firstOrFail();
    $tarjetas = ChartAccount::query()->where('code', '2.1')->firstOrFail();

    $bankPanel = $ws->derivedPanel($bancos);
    expect($bankPanel['kind'])->toBe('disponibilidades')
        ->and($bankPanel['accounts']->pluck('name')->all())->toContain('Banco Derivado')
        ->and($bankPanel['accounts']->pluck('name')->all())->not->toContain('Billetera Derivada');

    $walletPanel = $ws->derivedPanel($billeteras);
    expect($walletPanel['accounts']->pluck('name')->all())->toContain('Billetera Derivada');

    $cashPanel = $ws->derivedPanel($caja);
    expect($cashPanel['accounts']->pluck('name')->all())->toContain('Efectivo Derivado');

    $cardPanel = $ws->derivedPanel($tarjetas);
    expect($cardPanel['kind'])->toBe('cards')
        ->and(collect($cardPanel['cards'])->pluck('name')->all())->toContain('Visa Derivada');

    // No se creó chart_account individual por FA
    $codes = ChartAccount::query()->pluck('code');
    expect($codes->contains('Banco Derivado'))->toBeFalse();
    expect(ChartAccount::query()->where('name', 'Visa Derivada')->exists())->toBeFalse();

    $this->get(route('chart-accounts.index', ['account' => $tarjetas->id]))
        ->assertOk()
        ->assertSee('Visa Derivada')
        ->assertSee(route('accounts.edit', $card), false);

    $this->get(route('chart-accounts.index', ['account' => $bancos->id]))
        ->assertOk()
        ->assertSee('Banco Derivado')
        ->assertSee(route('accounts.edit', $bank), false);
});

test('cambio de tipo actualiza ubicación derivada sin tocar movimientos ni saldos', function () {
    ensureFaLocations();
    $this->actingAs(makeAdmin());
    $fa = FinancialAccount::query()->create([
        'name' => 'Cambio Tipo',
        'type' => AccountType::Other,
        'currency_id' => arsId(),
        'status' => 'active',
        'cached_balance' => '123.45',
        'is_liability' => false,
    ]);

    $movementsBefore = Movement::query()->count();
    $balanceBefore = (string) $fa->cached_balance;

    $this->put(route('accounts.update', $fa), [
        'name' => 'Cambio Tipo',
        'type' => AccountType::CreditCard->value,
        'status' => 'active',
        'card_brand' => 'MC',
        'card_last4' => '9999',
        'card_holder' => 'X',
        'card_expiry_month' => 12,
        'card_expiry_year' => 2032,
    ])->assertRedirect();

    $fresh = $fa->fresh();
    expect($fresh->type)->toBe(AccountType::CreditCard)
        ->and($fresh->chartAccount?->code)->toBe('2.1')
        ->and((string) $fresh->cached_balance)->toBe($balanceBefore)
        ->and(Movement::query()->count())->toBe($movementsBefore)
        ->and($fresh->is_liability)->toBeTrue();
});

test('inactiva editable y listado Ver inactivas; sin CVV', function () {
    ensureFaLocations();
    $this->actingAs(makeAdmin());
    $fa = FinancialAccount::query()->create([
        'name' => 'Inactiva FA',
        'type' => AccountType::Bank,
        'currency_id' => arsId(),
        'status' => 'inactive',
        'cached_balance' => 0,
    ]);

    $this->get(route('accounts.index'))->assertOk()->assertDontSee('Inactiva FA');
    $this->get(route('accounts.index', ['inactive' => 1]))->assertOk()->assertSee('Inactiva FA');

    $this->put(route('accounts.update', $fa), [
        'name' => 'Inactiva FA',
        'type' => AccountType::Bank->value,
        'status' => 'active',
        'institution' => 'Banco',
    ])->assertRedirect();

    expect($fa->fresh()->status)->toBe('active');

    $this->post(route('accounts.store'), [
        'name' => 'Card CVV',
        'type' => AccountType::CreditCard->value,
        'currency_id' => arsId(),
        'status' => 'active',
        'card_number' => '4111111111111111',
        'card_brand' => 'Visa',
        'card_holder' => 'T',
        'card_expiry_month' => 12,
        'card_expiry_year' => 2030,
        'cvv' => '123',
    ])->assertStatus(422);
});

test('CBU CVU inválidos en castellano; Luhn tarjeta', function () {
    ensureFaLocations();
    $this->actingAs(makeAdmin());
    $fa = FinancialAccount::query()->create([
        'name' => 'Val Bank',
        'type' => AccountType::Bank,
        'currency_id' => arsId(),
        'status' => 'active',
        'cached_balance' => 0,
    ]);

    $this->from(route('accounts.edit', $fa))
        ->put(route('accounts.update', $fa), [
            'name' => 'Val Bank',
            'type' => AccountType::Bank->value,
            'status' => 'active',
            'cbu_cvu' => '123',
        ])->assertSessionHasErrors('cbu_cvu');

    expect(session('errors')->first('cbu_cvu'))->toContain('22');

    $this->post(route('accounts.store'), [
        'name' => 'Bad Luhn',
        'type' => AccountType::CreditCard->value,
        'currency_id' => arsId(),
        'status' => 'active',
        'card_number' => '4111111111111112',
        'card_brand' => 'Visa',
        'card_holder' => 'T',
        'card_expiry_month' => 12,
        'card_expiry_year' => 2030,
    ])->assertSessionHasErrors('card_number');
});

test('cuentas seed aparecen bajo ramas derivadas correctas', function () {
    $this->seed(FinancialAccountSeeder::class);
    ensureFaLocations();
    $linker = app(FinancialAccountChartLinker::class);
    FinancialAccount::query()->each(fn ($fa) => $linker->link($fa, force: true));

    $ws = app(ChartAccountWorkspaceService::class);
    $byType = FinancialAccount::query()->get()->groupBy(fn ($fa) => $fa->type->value);

    foreach ([
        'bank' => '1.1.2',
        'wallet' => '1.1.3',
        'cash' => '1.1.1',
        'credit_card' => '2.1',
    ] as $type => $code) {
        $names = ($byType[$type] ?? collect())->pluck('name');
        if ($names->isEmpty()) {
            continue;
        }
        $node = ChartAccount::query()->where('code', $code)->firstOrFail();
        $panel = $ws->derivedPanel($node);
        $listed = collect($panel['accounts'] ?? $panel['cards'] ?? [])->pluck('name');
        foreach ($names as $name) {
            expect($listed->all())->toContain($name);
        }
    }
});
