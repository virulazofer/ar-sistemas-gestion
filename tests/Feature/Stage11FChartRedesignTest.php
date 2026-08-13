<?php

use App\Enums\AccountType;
use App\Enums\ChartAccountType;
use App\Enums\MovementScope;
use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\Movement;
use App\Services\Finance\ChartAccountAdminService;
use App\Services\Finance\ChartConceptCompatibility;
use App\Services\Finance\ChartStructuralMigrationService;
use App\Services\Finance\FinancialAccountChartLinker;
use App\Services\Finance\MovementService;
use App\Services\Finance\ScopeOriginRules;
use Database\Seeders\ChartAccountSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ExchangeRateSeeder;

function seedChartTree(): void
{
    test()->seed(ChartAccountSeeder::class);
}

test('cinco raíces protegidas', function () {
    seedChartTree();
    $roots = ChartAccount::query()->roots()->where('is_protected', true)->orderBy('code')->get();
    expect($roots)->toHaveCount(5)
        ->and($roots->pluck('code')->all())->toBe(['1', '2', '3', '4', '5'])
        ->and($roots->pluck('name')->all())->toBe(['ACTIVO', 'PASIVO', 'PATRIMONIO NETO', 'INGRESOS', 'EGRESOS']);

    $admin = app(ChartAccountAdminService::class);
    $root = $roots->first();
    expect(fn () => $admin->delete($root, ['disposition' => 'unassign']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $admin->update($root, ['parent_id' => $roots->last()->id]))
        ->toThrow(InvalidArgumentException::class);
});

test('CRUD cuentas inferiores mover y eliminar', function () {
    seedChartTree();
    $admin = app(ChartAccountAdminService::class);
    $alimentacion = ChartAccount::query()->where('code', '5.1')->firstOrFail();

    $leaf = $admin->create([
        'code' => '5.1.9',
        'name' => 'Panadería',
        'type' => ChartAccountType::Expense->value,
        'parent_id' => $alimentacion->id,
    ]);
    expect($leaf->pathLabel())->toContain('Alimentación');

    $servicios = ChartAccount::query()->where('code', '5.2')->firstOrFail();
    $admin->move($leaf, $servicios->id);
    expect($leaf->fresh()->parent_id)->toBe($servicios->id);

    $admin->delete($leaf->fresh(), ['disposition' => 'unassign']);
    expect(ChartAccount::query()->where('code', '5.1.9')->exists())->toBeFalse();
});

test('eliminacion con reasignacion', function () {
    seedChartTree();
    test()->seed(CurrencySeeder::class);
    test()->seed(ExchangeRateSeeder::class);
    $this->actingAs(makeAdmin());

    $ars = Currency::query()->where('code', 'ARS')->firstOrFail();
    $fa = FinancialAccount::query()->create([
        'name' => 'Caja test',
        'type' => AccountType::Cash,
        'currency_id' => $ars->id,
        'status' => 'active',
        'cached_balance' => 0,
    ]);

    $carniceria = ChartAccount::query()->where('code', '5.1.3')->firstOrFail();
    $super = ChartAccount::query()->where('code', '5.1.1')->firstOrFail();
    $mov = app(MovementService::class)->createSimple([
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'chart_account_id' => $carniceria->id,
        'amount' => '100',
        'movement_date' => now()->toDateString(),
        'description' => 'carne',
    ]);

    app(ChartAccountAdminService::class)->delete($carniceria, [
        'disposition' => 'reassign',
        'reassign_to' => $super->id,
    ]);

    expect($mov->fresh()->chart_account_id)->toBe($super->id);
});

test('arbol multinivel combustible', function () {
    seedChartTree();
    $comb = ChartAccount::query()->where('code', '5.3.1')->firstOrFail();
    expect($comb->pathLabel())->toBe('EGRESOS › Automotor › Combustible');
});

test('cuenta financiera ubicacion contable', function () {
    seedChartTree();
    test()->seed(CurrencySeeder::class);
    $ars = Currency::query()->where('code', 'ARS')->firstOrFail();
    $bank = FinancialAccount::query()->create([
        'name' => 'Patagonia',
        'type' => AccountType::Bank,
        'currency_id' => $ars->id,
        'status' => 'active',
        'cached_balance' => 0,
    ]);
    $card = FinancialAccount::query()->create([
        'name' => 'VISA',
        'type' => AccountType::CreditCard,
        'currency_id' => $ars->id,
        'status' => 'active',
        'is_liability' => true,
        'cached_balance' => 0,
        'card_expiry_month' => 12,
        'card_expiry_year' => 2030,
        'card_last4' => '1234',
    ]);

    $linker = app(FinancialAccountChartLinker::class);
    $linker->link($bank, true);
    $linker->link($card, true);

    expect($bank->fresh()->chartAccount?->code)->toBe('1.1.2')
        ->and($card->fresh()->chartAccount?->code)->toBe('2.1');
});

test('categoria historica a cuenta nueva', function () {
    seedChartTree();
    $compat = app(ChartConceptCompatibility::class);
    expect($compat->chartForName('Supermercado')?->code)->toBe('5.1.1')
        ->and($compat->chartForName('Miranda')?->code)->toBe('5.4.1')
        ->and($compat->chartForName('Abonos')?->code)->toBe('4.2.1');
});

test('scopes ingreso egreso nueva carga', function () {
    seedChartTree();
    test()->seed(CurrencySeeder::class);
    test()->seed(ExchangeRateSeeder::class);
    $this->actingAs(makeAdmin());
    $ars = Currency::query()->where('code', 'ARS')->firstOrFail();
    $fa = FinancialAccount::query()->create([
        'name' => 'MP',
        'type' => AccountType::Wallet,
        'currency_id' => $ars->id,
        'status' => 'active',
        'cached_balance' => 0,
    ]);
    $svc = app(MovementService::class);
    $abonos = ChartAccount::query()->where('code', '4.2.1')->firstOrFail();
    $interes = ChartAccount::query()->where('code', '4.3.1')->firstOrFail();
    $comb = ChartAccount::query()->where('code', '5.3.1')->firstOrFail();

    $svc->createSimple([
        'type' => 'income', 'scope' => 'professional', 'financial_account_id' => $fa->id,
        'chart_account_id' => $abonos->id, 'amount' => '10', 'movement_date' => now()->toDateString(),
    ]);
    $svc->createSimple([
        'type' => 'income', 'scope' => 'financial', 'financial_account_id' => $fa->id,
        'chart_account_id' => $interes->id, 'amount' => '10', 'movement_date' => now()->toDateString(),
    ]);
    expect(fn () => $svc->createSimple([
        'type' => 'income', 'scope' => 'personal', 'financial_account_id' => $fa->id,
        'chart_account_id' => $abonos->id, 'amount' => '10', 'movement_date' => now()->toDateString(),
    ]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $svc->createSimple([
        'type' => 'income', 'scope' => 'mixed', 'financial_account_id' => $fa->id,
        'chart_account_id' => $abonos->id, 'amount' => '10', 'movement_date' => now()->toDateString(),
    ]))->toThrow(InvalidArgumentException::class);

    foreach (['personal', 'professional', 'mixed'] as $scope) {
        $svc->createSimple([
            'type' => 'expense', 'scope' => $scope, 'financial_account_id' => $fa->id,
            'chart_account_id' => $comb->id, 'amount' => '5', 'movement_date' => now()->toDateString(),
        ]);
    }
    expect(Movement::query()->where('type', 'expense')->count())->toBe(3);
});

test('sugerencia ambito y override', function () {
    seedChartTree();
    $rules = app(ScopeOriginRules::class);
    $abonos = ChartAccount::query()->where('code', '4.2.1')->firstOrFail();
    $interes = ChartAccount::query()->where('code', '4.3.1')->firstOrFail();
    $miranda = ChartAccount::query()->where('code', '5.4.1')->firstOrFail();

    expect($rules->suggestFromChartAccount($abonos))->toBe('professional')
        ->and($rules->suggestFromChartAccount($interes))->toBe('financial')
        ->and($rules->suggestFromChartAccount($miranda))->toBe('personal');
});

test('preservacion ambito historico personal en ingreso', function () {
    seedChartTree();
    $report = app(ChartStructuralMigrationService::class)->dryRun();
    expect($report['mode'])->toBe('DRY-RUN')
        ->and($report['apply'])->toBeFalse()
        ->and($report)->toHaveKey('income_personal_incompatible');
});

test('busqueda de cuentas', function () {
    seedChartTree();
    $admin = makeAdmin();
    $this->actingAs($admin);
    $res = $this->getJson(route('chart-accounts.search', ['q' => 'comb', 'type' => 'expense']))
        ->assertOk()
        ->json('results');
    expect(collect($res)->pluck('code'))->toContain('5.3.1');

    $res2 = $this->getJson(route('chart-accounts.search', ['q' => 'susc', 'type' => 'expense']))
        ->json('results');
    expect(collect($res2)->pluck('code'))->toContain('5.2.5');
});

test('ui plan drill-down y menu redirects', function () {
    seedChartTree();
    $admin = makeAdmin();
    $this->actingAs($admin);

    $this->get(route('chart-accounts.index'))->assertOk()->assertSee('ACTIVO')->assertSee('EGRESOS');
    $alimentacion = ChartAccount::query()->where('code', '5.1')->firstOrFail();
    $this->get(route('chart-accounts.index', ['account' => $alimentacion->id]))
        ->assertOk()
        ->assertSee('Alimentación')
        ->assertSee('Supermercado');

    $this->get(route('chart-accounts.map'))->assertRedirect(route('chart-accounts.index'));
    $this->get(route('categories.index'))->assertRedirect(route('chart-accounts.index'));
});

test('quick entry valida scopes', function () {
    seedChartTree();
    test()->seed(CurrencySeeder::class);
    test()->seed(ExchangeRateSeeder::class);
    $this->actingAs(makeAdmin());
    $ars = Currency::query()->where('code', 'ARS')->firstOrFail();
    $fa = FinancialAccount::query()->create([
        'name' => 'Caja Q',
        'type' => AccountType::Cash,
        'currency_id' => $ars->id,
        'status' => 'active',
        'cached_balance' => 0,
    ]);
    $html = $this->get(route('movements.quick'))->assertOk()->getContent();
    expect($html)->toContain('Concepto')->toContain('Egreso');

    $this->post(route('movements.quick.store'), [
        'type' => 'income',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '100',
        'movement_date' => now()->toDateString(),
    ])->assertSessionHasErrors();
});

test('cc clientes y stock intactos en dry-run', function () {
    seedChartTree();
    $client = Client::query()->create([
        'name' => 'DAASA',
        'status' => 'active',
    ]);
    $before = Client::query()->count();
    $report = app(ChartStructuralMigrationService::class)->dryRun();
    expect(Client::query()->count())->toBe($before)
        ->and(Client::query()->find($client->id))->not->toBeNull()
        ->and($report['stop'])->toContain('DETENERSE');
});

test('no duplicacion conceptual categoria plan', function () {
    seedChartTree();
    $compat = app(ChartConceptCompatibility::class);
    $comb = ChartAccount::query()->where('code', '5.3.1')->firstOrFail();
    $bridge = $compat->ensureOperationalBridge($comb);
    expect($bridge['category_id'])->not->toBeNull()
        ->and($bridge['subcategory_id'])->not->toBeNull();
    $cat = Category::query()->find($bridge['category_id']);
    expect($cat?->name)->toBe('Automotor');
});
