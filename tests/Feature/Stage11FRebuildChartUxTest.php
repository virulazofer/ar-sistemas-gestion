<?php

use App\Enums\AccountType;
use App\Enums\ChartAccountType;
use App\Enums\MovementScope;
use App\Models\AuditLog;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\Clients\ClientLedgerService;
use App\Services\Finance\ChartAccountAdminService;
use App\Services\Finance\ChartAccountPeriod;
use App\Services\Finance\ChartAccountWorkspaceService;
use App\Services\Finance\FinancialAccountChartLinker;
use App\Services\Finance\MovementService;
use App\Services\Finance\RememberedClassificationService;
use App\Services\Finance\ScopeOriginRules;
use App\Support\UiSemantics;
use Database\Seeders\ChartAccountSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ExchangeRateSeeder;

function rebuildSeed(): void
{
    test()->seed(CurrencySeeder::class);
    test()->seed(ExchangeRateSeeder::class);
    test()->seed(ChartAccountSeeder::class);
}

function rebuildFa(string $name, AccountType $type): FinancialAccount
{
    $ars = Currency::query()->where('code', 'ARS')->firstOrFail();

    $fa = FinancialAccount::query()->create([
        'name' => $name,
        'type' => $type,
        'currency_id' => $ars->id,
        'status' => 'active',
        'cached_balance' => '1000.00',
        'is_liability' => $type === AccountType::CreditCard,
    ]);

    return app(FinancialAccountChartLinker::class)->link($fa);
}

test('A raíces protegidas', function () {
    rebuildSeed();
    $roots = ChartAccount::query()->roots()->where('is_protected', true)->orderBy('code')->get();
    expect($roots)->toHaveCount(5)->and($roots->pluck('code')->all())->toBe(['1', '2', '3', '4', '5']);
    $admin = app(ChartAccountAdminService::class);
    expect(fn () => $admin->delete($roots->first(), ['disposition' => 'delete']))
        ->toThrow(InvalidArgumentException::class);
});

test('B C D E F G crear editar mover ciclo eliminar vacía', function () {
    rebuildSeed();
    $admin = app(ChartAccountAdminService::class);
    $parent = ChartAccount::query()->where('code', '5.1')->firstOrFail();
    $code = $admin->suggestNextCode($parent);

    $leaf = $admin->create([
        'code' => $code,
        'name' => 'TEST Panadería',
        'type' => ChartAccountType::Expense->value,
        'parent_id' => $parent->id,
    ]);
    expect($leaf->code)->toBe($code);

    $admin->update($leaf, ['name' => 'TEST Panadería Edit', 'type' => ChartAccountType::Expense->value, 'parent_id' => $parent->id]);
    expect($leaf->fresh()->name)->toBe('TEST Panadería Edit');

    $serv = ChartAccount::query()->where('code', '5.2')->firstOrFail();
    $admin->move($leaf, $serv->id);
    expect($leaf->fresh()->parent_id)->toBe($serv->id);

    $grand = ChartAccount::query()->where('code', '5.1.1')->firstOrFail();
    expect(fn () => $admin->update($parent, [
        'parent_id' => $grand->id,
        'type' => ChartAccountType::Expense->value,
        'name' => $parent->name,
        'code' => $parent->code,
    ]))->toThrow(InvalidArgumentException::class);

    $admin->delete($leaf->fresh(), ['disposition' => 'delete']);
    expect(ChartAccount::query()->where('id', $leaf->id)->exists())->toBeFalse();
});

test('C código automático sugerido', function () {
    rebuildSeed();
    $parent = ChartAccount::query()->where('code', '5.3')->firstOrFail();
    $suggested = app(ChartAccountAdminService::class)->suggestNextCode($parent);
    expect($suggested)->toStartWith('5.3.');
});

test('H I eliminar con movimientos exige reasignación', function () {
    rebuildSeed();
    $this->actingAs(makeAdmin());
    $fa = rebuildFa('Caja rebuild', AccountType::Cash);
    $carniceria = ChartAccount::query()->where('code', '5.1.3')->firstOrFail();
    $super = ChartAccount::query()->where('code', '5.1.1')->firstOrFail();

    $mov = app(MovementService::class)->createSimple([
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'chart_account_id' => $carniceria->id,
        'amount' => '100',
        'movement_date' => now()->toDateString(),
        'description' => 'carne rebuild',
    ]);

    $admin = app(ChartAccountAdminService::class);
    expect(fn () => $admin->delete($carniceria, ['disposition' => 'unassign']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $admin->delete($carniceria, ['disposition' => 'delete']))
        ->toThrow(InvalidArgumentException::class);

    $admin->delete($carniceria, ['disposition' => 'reassign', 'reassign_to' => $super->id]);
    expect($mov->fresh()->chart_account_id)->toBe($super->id);
});

test('J totales padre = descendientes', function () {
    rebuildSeed();
    $this->actingAs(makeAdmin());
    $fa = rebuildFa('Caja tot', AccountType::Cash);
    $svc = app(MovementService::class);
    $super = ChartAccount::query()->where('code', '5.1.1')->firstOrFail();
    $comidas = ChartAccount::query()->where('code', '5.1.2')->firstOrFail();
    $alimentacion = ChartAccount::query()->where('code', '5.1')->firstOrFail();

    $svc->createSimple([
        'type' => 'expense', 'scope' => 'personal', 'financial_account_id' => $fa->id,
        'chart_account_id' => $super->id, 'amount' => '200', 'movement_date' => now()->toDateString(), 'description' => 'a',
    ]);
    $svc->createSimple([
        'type' => 'expense', 'scope' => 'personal', 'financial_account_id' => $fa->id,
        'chart_account_id' => $comidas->id, 'amount' => '50', 'movement_date' => now()->toDateString(), 'description' => 'b',
    ]);

    $period = ChartAccountPeriod::resolve('this_month', null, null);
    $tree = app(ChartAccountWorkspaceService::class)->treeWithTotals($period['from'], $period['to'], null);
    $byId = $tree['by_id'];
    expect((float) $byId[$alimentacion->id]['total_ars'])->toBe(250.0)
        ->and((float) $byId[$alimentacion->id]['total_ars'])
        ->toBe((float) $byId[$super->id]['total_ars'] + (float) $byId[$comidas->id]['total_ars']);
});

test('K selector de período', function () {
    $p = ChartAccountPeriod::resolve('this_month', null, null);
    expect($p['preset'])->toBe('this_month')->and($p['from'])->not->toBeNull();
    $y = ChartAccountPeriod::resolve('this_year', null, null);
    expect($y['from'])->toEndWith('-01-01');
    $c = ChartAccountPeriod::resolve('custom', '2026-01-01', '2026-01-31');
    expect($c['from'])->toBe('2026-01-01')->and($c['to'])->toBe('2026-01-31');
});

test('L M N O P Q ámbitos egreso/ingreso', function () {
    rebuildSeed();
    $this->actingAs(makeAdmin());
    $fa = rebuildFa('MP rebuild', AccountType::Wallet);
    $rules = app(ScopeOriginRules::class);
    $svc = app(MovementService::class);
    $comb = ChartAccount::query()->where('code', '5.3.1')->firstOrFail();
    $abono = ChartAccount::query()->where('code', 'like', '4.%')->where('name', 'like', '%Abono%')->first()
        ?? ChartAccount::query()->where('type', ChartAccountType::Income)->whereNotNull('parent_id')->firstOrFail();

    foreach (['personal', 'professional', 'mixed'] as $scope) {
        $rules->assertAllowed('expense', $scope);
        $svc->createSimple([
            'type' => 'expense', 'scope' => $scope, 'financial_account_id' => $fa->id,
            'chart_account_id' => $comb->id, 'amount' => '10', 'movement_date' => now()->toDateString(),
            'description' => 'egreso '.$scope,
        ]);
    }
    foreach (['professional', 'financial'] as $scope) {
        $rules->assertAllowed('income', $scope);
        $svc->createSimple([
            'type' => 'income', 'scope' => $scope, 'financial_account_id' => $fa->id,
            'chart_account_id' => $abono->id, 'amount' => '10', 'movement_date' => now()->toDateString(),
            'description' => 'ingreso '.$scope,
        ]);
    }
    expect(fn () => $rules->assertAllowed('income', 'personal'))->toThrow(InvalidArgumentException::class);
});

test('R búsqueda de cuentas', function () {
    rebuildSeed();
    $this->actingAs(makeAdmin());
    $res = app(ChartAccountAdminService::class)->search('comb', 'expense');
    expect(collect($res)->pluck('name')->join(' '))->toContain('Combustible');
});

test('S T U V FA linker por tipo', function () {
    rebuildSeed();
    $linker = app(FinancialAccountChartLinker::class);
    expect($linker->link(rebuildFa('Banco X', AccountType::Bank))->chartAccount->code)->toBe('1.1.2');
    expect($linker->link(rebuildFa('Billetera X', AccountType::Wallet))->chartAccount->code)->toBe('1.1.3');
    expect($linker->link(rebuildFa('Efectivo X', AccountType::Cash))->chartAccount->code)->toBe('1.1.1');
    expect($linker->link(rebuildFa('Visa X', AccountType::CreditCard))->chartAccount->code)->toBe('2.1');
});

test('W X créditos clientes ranking y semántica CC', function () {
    rebuildSeed();
    $this->actingAs(makeAdmin());
    $client = Client::query()->create([
        'name' => 'Cliente Test CC',
        'status' => 'active',
    ]);
    app(ClientLedgerService::class)->registerCharge($client, [
        'currency_code' => 'ARS',
        'amount' => '500',
        'entry_date' => now()->toDateString(),
    ]);

    $account = ChartAccount::query()->where('code', '1.2.1')->firstOrFail();
    $detail = app(ChartAccountWorkspaceService::class)->detailPayload($account, null, null, null);
    expect($detail['derived']['kind'])->toBe('clients_cc')
        ->and((float) $detail['total_ars'])->toBeGreaterThan(0);

    expect(UiSemantics::tone('100.00', UiSemantics::MODE_CLIENT_CC))->toBe(UiSemantics::TONE_ATTENTION)
        ->and(UiSemantics::tone('-50.00', UiSemantics::MODE_CLIENT_CC))->toBe(UiSemantics::TONE_FAVORABLE)
        ->and(UiSemantics::tone('80.00', UiSemantics::MODE_EXPENSE))->toBe(UiSemantics::TONE_NEUTRAL);
});

test('Y Z AA AB clasificaciones recordadas', function () {
    rebuildSeed();
    $this->actingAs(makeAdmin());
    $svc = app(RememberedClassificationService::class);
    $super = ChartAccount::query()->where('code', '5.1.1')->firstOrFail();
    $comidas = ChartAccount::query()->where('code', '5.1.2')->firstOrFail();

    $svc->remember('Piatto Rosso', 'expense', $super->id, 'personal');
    $exact = $svc->lookup('Piatto Rosso', 'expense');
    expect($exact['match'])->toBe('exact')
        ->and($exact['suggested_chart_account_id'])->toBe($super->id)
        ->and($exact['suggested_scope'])->toBe('personal');

    $probable = $svc->lookup('Piatto Rosso Devoto', 'expense');
    expect($probable['match'])->toBe('probable')
        ->and($probable['suggested_chart_account_id'])->toBe($super->id);

    // Manual gana: recordar otra clasificación no se aplica silenciosamente al movimiento (solo memoria).
    $svc->remember('Piatto Rosso', 'expense', $comidas->id, 'personal');
    expect($svc->lookup('Piatto Rosso', 'expense')['suggested_chart_account_id'])->toBe($comidas->id);

    $svc->forgetByDescription('Piatto Rosso', 'expense');
    expect($svc->lookup('Piatto Rosso', 'expense'))->toBeNull();
});

test('AC AD AE filtros y drill-down UI', function () {
    rebuildSeed();
    $this->actingAs(makeAdmin());
    $comb = ChartAccount::query()->where('code', '5.3.1')->firstOrFail();
    $auto = ChartAccount::query()->where('code', '5.3')->firstOrFail();

    $this->get(route('chart-accounts.index', ['period' => 'this_month', 'scope' => 'personal']))
        ->assertOk()
        ->assertSee('Radiografía')
        ->assertSee('Este mes');

    $this->get(route('chart-accounts.index', ['account' => $comb->id, 'period' => 'this_year']))
        ->assertOk()
        ->assertSee('Combustible')
        ->assertSee('Fecha')
        ->assertSee('Cuenta financiera');

    $this->get(route('chart-accounts.index', ['account' => $auto->id]))
        ->assertOk()
        ->assertSee('incluye subcuentas');
});

test('AF sin datos suficientes ≠ $0 engañoso', function () {
    rebuildSeed();
    $inv = ChartAccount::query()->where('code', '1.4')->firstOrFail();
    $equity = ChartAccount::query()->where('code', '3')->firstOrFail();
    $ws = app(ChartAccountWorkspaceService::class);
    expect($ws->derivedSummaryForCode('1.4')['display'])->toBe('unavailable')
        ->and($ws->derivedSummaryForCode('2.2')['display'])->toBe('insufficient')
        ->and($ws->derivedPanel($equity)['display'])->toBe('insufficient');

    $this->actingAs(makeAdmin());
    $this->get(route('chart-accounts.index', ['account' => $inv->id]))
        ->assertOk()
        ->assertSee('Valuación de inventario no disponible');
});

test('AG AH permisos y auditoría', function () {
    rebuildSeed();
    $user = User::factory()->create();
    $this->actingAs($user);
    $this->get(route('chart-accounts.index'))->assertForbidden();

    $admin = makeAdmin();
    $this->actingAs($admin);
    $parent = ChartAccount::query()->where('code', '5.1')->firstOrFail();
    $before = AuditLog::query()->count();
    $leaf = app(ChartAccountAdminService::class)->create([
        'code' => app(ChartAccountAdminService::class)->suggestNextCode($parent),
        'name' => 'TEST Audit',
        'type' => ChartAccountType::Expense->value,
        'parent_id' => $parent->id,
    ]);
    expect(AuditLog::query()->count())->toBeGreaterThan($before);

    app(ChartAccountAdminService::class)->delete($leaf, ['disposition' => 'delete']);
});

test('AI index responsive markers y nav simplificada', function () {
    rebuildSeed();
    $this->actingAs(makeAdmin());
    $html = $this->get(route('chart-accounts.index'))->assertOk()->getContent();
    expect($html)->toContain('lg:grid-cols-12')
        ->and($html)->toContain('lg:hidden')
        ->and($html)->toContain('Configuración avanzada')
        ->and($html)->not->toContain('Asignación al plan</span>')
        ->and($html)->not->toContain('Reglas automáticas');

    $this->get(route('chart-accounts.advanced'))->assertOk()->assertSee('Clasificaciones recordadas');
    $this->get(route('remembered-classifications.index'))->assertOk();
});

test('nav pendientes solo si N>0', function () {
    rebuildSeed();
    $this->actingAs(makeAdmin());
    $home = $this->get(route('dashboard'))->assertOk()->getContent();
    expect($home)->toContain('Plan de cuentas')
        ->and($home)->not->toContain('Asignación al plan');
});
