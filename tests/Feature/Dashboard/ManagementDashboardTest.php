<?php

use App\Enums\AccountType;
use App\Enums\MovementScope;
use App\Enums\MovementType;
use App\Enums\SaleStatus;
use App\Models\AccountHolder;
use App\Models\Category;
use App\Models\Client;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\Sale;
use App\Services\Clients\ClientLedgerService;
use App\Services\Dashboard\ManagementDashboardService;
use App\Services\Finance\MovementService;
use App\Support\Money;
use Carbon\Carbon;
use Database\Seeders\CategorySeeder;
use Database\Seeders\ChartAccountSeeder;
use Database\Seeders\CurrencySeeder;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00'));
    $this->seed(CurrencySeeder::class);
    $this->seed(ChartAccountSeeder::class);
    $this->seed(CategorySeeder::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

function mgmtArsAccount(string $name = 'Caja Gestión', ?int $holderId = null, AccountType $type = AccountType::Cash): FinancialAccount
{
    return FinancialAccount::query()->create([
        'name' => $name,
        'type' => $type->value,
        'currency_id' => Currency::where('code', 'ARS')->firstOrFail()->id,
        'account_holder_id' => $holderId,
        'is_liability' => $type === AccountType::CreditCard,
        'status' => 'active',
        'cached_balance' => 0,
    ]);
}

function mgmtUsdAccount(string $name = 'Caja USD Gestión'): FinancialAccount
{
    return FinancialAccount::query()->create([
        'name' => $name,
        'type' => AccountType::Cash->value,
        'currency_id' => Currency::where('code', 'USD')->firstOrFail()->id,
        'status' => 'active',
        'cached_balance' => 0,
    ]);
}

function mgmtSeedRate(): void
{
    app(\App\Services\Finance\ExchangeRateService::class)->storeManual('1000.000000', 'test');
}

function mgmtCategory(string $name = 'Ventas'): Category
{
    return Category::query()->where('name', $name)->first()
        ?? Category::query()->create([
            'name' => $name,
            'scope' => 'professional',
            'default_scope' => 'professional',
            'is_active' => true,
            'sort_order' => 1,
        ]);
}

it('renderiza dashboard de gestión y aparece en navegación', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)
        ->get(route('dashboard.management'))
        ->assertOk()
        ->assertSee('Tablero de gestión')
        ->assertSee('Período analizado:');

    $this->actingAs($admin)
        ->get(route('movements.quick'))
        ->assertOk()
        ->assertSee('Tablero de gestión');
});

it('selector mensual y navegación mes anterior/siguiente', function () {
    $admin = makeAdmin();
    mgmtSeedRate();
    $this->actingAs($admin);

    $svc = app(ManagementDashboardService::class);
    $aug = $svc->build(['preset' => 'month', 'ym' => '2026-08', 'scope' => 'all']);
    expect($aug['period']['from'])->toBe('2026-08-01')
        ->and($aug['period']['to'])->toBe('2026-08-31')
        ->and($aug['period']['label'])->toContain('Agosto');

    $this->get(route('dashboard.management', ['preset' => 'month', 'ym' => '2026-07']))
        ->assertOk()
        ->assertSee('01/07/2026')
        ->assertSee('31/07/2026');

    $this->get(route('dashboard.management', ['preset' => 'this_month']))
        ->assertOk()
        ->assertSee('01/08/2026');

    $this->get(route('dashboard.management', ['preset' => 'previous_month']))
        ->assertOk()
        ->assertSee('01/07/2026');
});

it('acepta rango personalizado DD/MM/AAAA', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);

    $this->get(route('dashboard.management', [
        'preset' => 'custom',
        'from' => '01/06/2026',
        'to' => '15/06/2026',
    ]))
        ->assertOk()
        ->assertSee('01/06/2026')
        ->assertSee('15/06/2026');

    $data = app(ManagementDashboardService::class)->build([
        'preset' => 'custom',
        'from' => '01/06/2026',
        'to' => '15/06/2026',
    ]);
    expect($data['period']['from'])->toBe('2026-06-01')
        ->and($data['period']['to'])->toBe('2026-06-15');
});

it('filtra ámbitos personal y profesional sin recalcular clasificación', function () {
    $admin = makeAdmin();
    mgmtSeedRate();
    $this->actingAs($admin);
    $cash = mgmtArsAccount();
    $cat = mgmtCategory();
    $mov = app(MovementService::class);

    $mov->createSimple([
        'type' => MovementType::Income->value,
        'financial_account_id' => $cash->id,
        'amount' => '1000.00',
        'scope' => MovementScope::Professional->value,
        'category_id' => $cat->id,
        'movement_date' => '2026-08-05',
        'description' => 'Ing profesional',
    ]);
    $mov->createSimple([
        'type' => MovementType::Income->value,
        'financial_account_id' => $cash->id,
        'amount' => '200.00',
        'scope' => MovementScope::Personal->value,
        'category_id' => $cat->id,
        'movement_date' => '2026-08-06',
        'description' => 'Ing personal',
    ]);

    $svc = app(ManagementDashboardService::class);
    $all = $svc->build(['preset' => 'this_month', 'scope' => 'all']);
    $pro = $svc->build(['preset' => 'this_month', 'scope' => 'professional']);
    $per = $svc->build(['preset' => 'this_month', 'scope' => 'personal']);

    expect($all['financial']['income_ars'])->toBe('1200.00')
        ->and($pro['financial']['income_ars'])->toBe('1000.00')
        ->and($per['financial']['income_ars'])->toBe('200.00')
        ->and($per['economic']['sales_ars'])->toBe('0.00')
        ->and($per['cc']['applicable'])->toBeFalse();
});

it('calcula financiero ingresos egresos resultado', function () {
    $admin = makeAdmin();
    mgmtSeedRate();
    $this->actingAs($admin);
    $cash = mgmtArsAccount();
    $cat = mgmtCategory();
    $mov = app(MovementService::class);

    $mov->createSimple([
        'type' => 'income',
        'financial_account_id' => $cash->id,
        'amount' => '5000.00',
        'scope' => 'professional',
        'category_id' => $cat->id,
        'movement_date' => '2026-08-02',
    ]);
    $mov->createSimple([
        'type' => 'expense',
        'financial_account_id' => $cash->id,
        'amount' => '1500.00',
        'scope' => 'professional',
        'category_id' => $cat->id,
        'movement_date' => '2026-08-03',
    ]);

    $fin = app(ManagementDashboardService::class)->financialKpis([
        'from' => '2026-08-01',
        'to' => '2026-08-31',
    ], 'all');

    expect($fin['income_ars'])->toBe('5000.00')
        ->and($fin['expense_ars'])->toBe('1500.00')
        ->and($fin['result_ars'])->toBe('3500.00');
});

it('económico usa ventas confirmadas y distingue utilidad de ingreso financiero', function () {
    $admin = makeAdmin();
    mgmtSeedRate();
    $this->actingAs($admin);
    $cash = mgmtArsAccount();
    $cat = mgmtCategory();
    $client = Client::query()->create(['name' => 'Cliente Eco', 'status' => 'active']);

    // Cobro financiero sin venta nueva
    app(MovementService::class)->createSimple([
        'type' => 'income',
        'financial_account_id' => $cash->id,
        'amount' => '800.00',
        'scope' => 'professional',
        'category_id' => $cat->id,
        'movement_date' => '2026-08-04',
        'description' => 'Cobro CC',
    ]);

    Sale::query()->create([
        'number' => 'V-TEST-1',
        'sequence' => 1,
        'client_id' => $client->id,
        'status' => SaleStatus::Confirmed->value,
        'origin' => 'manual',
        'sold_on' => '2026-08-05',
        'currency_code' => 'ARS',
        'payment_mode' => Sale::MODE_CREDIT,
        'subtotal' => '2000.00',
        'discount_amount' => '0.00',
        'tax_amount' => '0.00',
        'total' => '2000.00',
        'total_ars' => '2000.00',
        'total_usd' => '2.00',
        'total_cost' => '1200.00',
        'total_cost_ars' => '1200.00',
        'total_cost_usd' => '1.20',
        'gross_margin' => '800.00',
        'confirmed_at' => now(),
        'user_id' => $admin->id,
    ]);

    $data = app(ManagementDashboardService::class)->build(['preset' => 'this_month', 'scope' => 'all']);

    expect($data['economic']['sales_ars'])->toBe('2000.00')
        ->and($data['economic']['cost_ars'])->toBe('1200.00')
        ->and($data['economic']['utility_ars'])->toBe('800.00')
        ->and($data['financial']['income_ars'])->toBe('800.00') // solo cobro, no la venta crédito
        ->and($data['financial']['income_ars'])->not->toBe($data['economic']['sales_ars']);
});

it('comparación período anterior y sin base / evita división por cero', function () {
    expect(Money::percentChange('100.00', '0.00'))->toBeNull()
        ->and(Money::percentChange('110.00', '100.00'))->toBe('10.00');

    $admin = makeAdmin();
    $this->actingAs($admin);
    $data = app(ManagementDashboardService::class)->build([
        'preset' => 'custom',
        'from' => '01/01/2020',
        'to' => '31/01/2020',
    ]);
    expect($data['comparison']['has_base'])->toBeFalse()
        ->and($data['financial']['comparison_available'])->toBeFalse()
        ->and($data['financial']['comparison_label'])->toBe('Sin base de comparación');
});

it('cc cierre histórico inicial + IN - OUT y clientes con saldo', function () {
    $admin = makeAdmin();
    mgmtSeedRate();
    $this->actingAs($admin);
    $ledger = app(ClientLedgerService::class);

    $daasa = Client::query()->create(['name' => 'DAASA', 'status' => 'active']);
    $cintas = Client::query()->create(['name' => 'Cintas', 'status' => 'active']);
    $lider = Client::query()->create(['name' => 'Lidercar', 'status' => 'active']);

    $ledger->registerCharge($daasa, ['currency_code' => 'ARS', 'amount' => '1000', 'entry_date' => '2026-07-20']);
    $ledger->registerCharge($cintas, ['currency_code' => 'ARS', 'amount' => '500', 'entry_date' => '2026-08-05']);
    $cash = mgmtArsAccount();
    $ledger->registerPayment($daasa, [
        'financial_account_id' => $cash->id,
        'amount' => '300',
        'entry_date' => '2026-08-10',
        'description' => 'Cobro DAASA',
    ]);
    $ledger->registerCharge($lider, ['currency_code' => 'ARS', 'amount' => '200', 'entry_date' => '2026-08-08']);

    $svc = app(ManagementDashboardService::class);
    $cc = $svc->ccKpis(['from' => '2026-08-01', 'to' => '2026-08-31'], 'all');

    expect($cc['opening']['ARS'])->toBe('1000.00')
        ->and($cc['new_debt']['ARS'])->toBe('700.00')
        ->and($cc['collections']['ARS'])->toBe('300.00')
        ->and($cc['closing']['ARS'])->toBe('1400.00')
        ->and($cc['bridge']['ARS']['computed_final'])->toBe($cc['bridge']['ARS']['final']);

    $names = collect($cc['clients'])->pluck('name')->all();
    expect($names)->toBe(['DAASA', 'Cintas', 'Lidercar'])
        ->and($cc['clients'][0]['balance'])->toBe('700.00');

    $built = $svc->build(['preset' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31']);
    expect($built['drilldown']['clients'])->toContain('cuentas-corrientes');
});

it('posición histórica al cierre no usa saldo de hoy', function () {
    $admin = makeAdmin();
    mgmtSeedRate();
    $this->actingAs($admin);
    $fer = AccountHolder::query()->create(['code' => 'fernando', 'name' => 'Fernando', 'is_active' => true]);
    $cash = mgmtArsAccount('Caja Fer', $fer->id);
    $card = mgmtArsAccount('VISA Fer', $fer->id, AccountType::CreditCard);
    $mov = app(MovementService::class);

    $mov->createSimple([
        'type' => 'income',
        'financial_account_id' => $cash->id,
        'amount' => '1000.00',
        'scope' => 'personal',
        'movement_date' => '2026-07-15',
    ]);
    // Movimiento posterior al período: no debe entrar en cierre julio
    $mov->createSimple([
        'type' => 'income',
        'financial_account_id' => $cash->id,
        'amount' => '5000.00',
        'scope' => 'personal',
        'movement_date' => '2026-08-05',
    ]);
    $mov->createSimple([
        'type' => 'expense',
        'financial_account_id' => $card->id,
        'amount' => '200.00',
        'scope' => 'personal',
        'movement_date' => '2026-07-20',
        'description' => 'Compra tarjeta',
    ]);

    $pos = app(ManagementDashboardService::class)->positionAtClose('2026-07-31');
    expect($pos['liquid']['ARS']['total'])->toBe('1000.00')
        ->and($pos['liabilities']['ARS'])->toBe('200.00')
        ->and($pos['net']['ARS'])->toBe('800.00');

    $holders = collect($pos['by_holder'])->pluck('name')->all();
    expect($holders)->toContain('Fernando');
});

it('pago tarjeta no duplica gasto financiero (transferencia)', function () {
    $admin = makeAdmin();
    mgmtSeedRate();
    $this->actingAs($admin);
    $cash = mgmtArsAccount('Caja TC');
    $card = mgmtArsAccount('MC', null, AccountType::CreditCard);
    $mov = app(MovementService::class);

    $mov->createSimple([
        'type' => 'expense',
        'financial_account_id' => $card->id,
        'amount' => '400.00',
        'scope' => 'personal',
        'movement_date' => '2026-08-01',
        'description' => 'Compra TC',
    ]);
    $mov->createTransfer([
        'from_account_id' => $cash->id,
        'to_account_id' => $card->id,
        'amount' => '400.00',
        'scope' => 'personal',
        'movement_date' => '2026-08-15',
        'description' => 'Pago resumen',
    ]);
    // Fondo en caja
    $mov->createSimple([
        'type' => 'income',
        'financial_account_id' => $cash->id,
        'amount' => '1000.00',
        'scope' => 'personal',
        'movement_date' => '2026-07-01',
    ]);

    $fin = app(ManagementDashboardService::class)->financialKpis([
        'from' => '2026-08-01',
        'to' => '2026-08-31',
    ], 'all');

    // Solo el expense de compra en tarjeta, NO el pago (transfer)
    expect($fin['expense_ars'])->toBe('400.00')
        ->and($fin['income_ars'])->toBe('0.00');
});

it('venta crédito sin ingreso financiero y cobro CC sin venta', function () {
    $admin = makeAdmin();
    mgmtSeedRate();
    $this->actingAs($admin);
    $client = Client::query()->create(['name' => 'Credito SA', 'status' => 'active']);
    $cash = mgmtArsAccount();
    $ledger = app(ClientLedgerService::class);

    // Venta crédito económica
    Sale::query()->create([
        'number' => 'V-CRED-1',
        'sequence' => 2,
        'client_id' => $client->id,
        'status' => SaleStatus::Confirmed->value,
        'origin' => 'manual',
        'sold_on' => '2026-08-03',
        'currency_code' => 'ARS',
        'payment_mode' => Sale::MODE_CREDIT,
        'subtotal' => '1500.00',
        'discount_amount' => '0',
        'tax_amount' => '0',
        'total' => '1500.00',
        'total_ars' => '1500.00',
        'total_usd' => '1.50',
        'total_cost' => '900.00',
        'total_cost_ars' => '900.00',
        'total_cost_usd' => '0.90',
        'gross_margin' => '600.00',
        'confirmed_at' => now(),
        'user_id' => $admin->id,
    ]);
    $ledger->registerCharge($client, [
        'currency_code' => 'ARS',
        'amount' => '1500',
        'entry_date' => '2026-08-03',
    ]);

    $beforePay = app(ManagementDashboardService::class)->build(['preset' => 'this_month', 'scope' => 'all']);
    expect($beforePay['economic']['sales_ars'])->toBe('1500.00')
        ->and($beforePay['financial']['income_ars'])->toBe('0.00');

    // Cobro CC = financiero sin venta nueva
    $ledger->registerPayment($client, [
        'financial_account_id' => $cash->id,
        'amount' => '1500',
        'entry_date' => '2026-08-20',
        'description' => 'Cobro',
    ]);

    $after = app(ManagementDashboardService::class)->build(['preset' => 'this_month', 'scope' => 'all']);
    expect($after['financial']['income_ars'])->toBe('1500.00')
        ->and($after['economic']['sales_ars'])->toBe('1500.00') // misma venta, no duplica
        ->and($after['economic']['count'])->toBe(1);
});

it('separa ARS y USD sin sumarlos y maneja cero', function () {
    $admin = makeAdmin();
    mgmtSeedRate();
    $this->actingAs($admin);
    $ars = mgmtArsAccount();
    $usd = mgmtUsdAccount();
    $mov = app(MovementService::class);

    $mov->createSimple([
        'type' => 'income',
        'financial_account_id' => $ars->id,
        'amount' => '100.00',
        'scope' => 'professional',
        'movement_date' => '2026-08-01',
    ]);
    $mov->createSimple([
        'type' => 'income',
        'financial_account_id' => $usd->id,
        'amount' => '50.00',
        'scope' => 'professional',
        'movement_date' => '2026-08-01',
    ]);

    $fin = app(ManagementDashboardService::class)->financialKpis([
        'from' => '2026-08-01',
        'to' => '2026-08-31',
    ], 'all');

    expect($fin['income_ars'])->toBe('100.00')
        ->and($fin['income_usd'])->toBe('50.00')
        ->and(Money::formatAr('0.00'))->toBe('$ 0,00')
        ->and(Money::formatAr('1234.56', 'USD'))->toBe('U$S 1.234,56');

    // Resultado cero neutro
    expect(Money::isZero('0.00'))->toBeTrue();
});

it('sin datos del período muestra ceros y tablas vacías', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);

    $data = app(ManagementDashboardService::class)->build(['preset' => 'this_month', 'scope' => 'all']);
    expect($data['financial']['income_ars'])->toBe('0.00')
        ->and($data['financial']['result_ars'])->toBe('0.00')
        ->and($data['income_by_type'])->toBe([])
        ->and($data['expense_by_type'])->toBe([]);

    $this->get(route('dashboard.management'))
        ->assertOk()
        ->assertSee('Sin datos en el período');
});

it('tabla resumen mensual es clickeable hacia el mes', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);

    $this->get(route('dashboard.management'))
        ->assertOk()
        ->assertSee('Resumen mensual')
        ->assertSee('Agosto 2026')
        ->assertSee('ym=2026-08', false);
});
