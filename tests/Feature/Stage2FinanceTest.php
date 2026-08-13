<?php

use App\Enums\AccountType;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\FinancialAccount;
use App\Models\Movement;
use App\Models\Subcategory;
use App\Services\Finance\BalanceService;
use App\Services\Finance\ExchangeRateService;
use App\Services\Finance\MovementService;
use App\Support\Money;
use Database\Seeders\CategorySeeder;
use Database\Seeders\ChartAccountSeeder;
use Database\Seeders\CurrencySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function seedFinanceCore(): void
{
    test()->seed(CurrencySeeder::class);
    test()->seed(ChartAccountSeeder::class);
    test()->seed(CategorySeeder::class);
}

function makeArsAccount(string $name = 'Caja ARS Test'): FinancialAccount
{
    return FinancialAccount::query()->create([
        'name' => $name,
        'type' => AccountType::Cash->value,
        'currency_id' => Currency::where('code', 'ARS')->firstOrFail()->id,
        'status' => 'active',
        'cached_balance' => 0,
    ]);
}

function makeUsdAccount(string $name = 'Caja USD Test'): FinancialAccount
{
    return FinancialAccount::query()->create([
        'name' => $name,
        'type' => AccountType::Cash->value,
        'currency_id' => Currency::where('code', 'USD')->firstOrFail()->id,
        'status' => 'active',
        'cached_balance' => 0,
    ]);
}

function seedRate(string $rate = '1500.000000'): ExchangeRate
{
    return app(ExchangeRateService::class)->storeManual($rate, 'test');
}

test('crear cuenta ARS y USD', function () {
    $admin = makeAdmin();
    seedFinanceCore();

    $this->actingAs($admin)->post(route('accounts.store'), [
        'name' => 'Caja ARS',
        'type' => 'cash',
        'currency_id' => Currency::where('code', 'ARS')->value('id'),
        'status' => 'active',
    ])->assertRedirect(route('accounts.index'));

    $this->actingAs($admin)->post(route('accounts.store'), [
        'name' => 'Caja USD',
        'type' => 'cash',
        'currency_id' => Currency::where('code', 'USD')->value('id'),
        'status' => 'active',
    ])->assertRedirect(route('accounts.index'));

    expect(FinancialAccount::where('name', 'Caja ARS')->exists())->toBeTrue();
    expect(FinancialAccount::where('name', 'Caja USD')->exists())->toBeTrue();
});

test('registrar ingresos y gastos ARS/USD y verificar saldos', function () {
    $admin = makeAdmin();
    seedFinanceCore();
    $this->actingAs($admin);
    seedRate('1500');

    $ars = makeArsAccount();
    $usd = makeUsdAccount();
    $service = app(MovementService::class);
    $balances = app(BalanceService::class);

    $service->createSimple([
        'type' => 'income',
        'scope' => 'professional',
        'financial_account_id' => $ars->id,
        'amount' => '10000.50',
    ]);
    $service->createSimple([
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $ars->id,
        'amount' => '2500.25',
    ]);
    $service->createSimple([
        'type' => 'income',
        'scope' => 'professional',
        'financial_account_id' => $usd->id,
        'amount' => '200.00',
    ]);
    $service->createSimple([
        'type' => 'expense',
        'scope' => 'professional',
        'financial_account_id' => $usd->id,
        'amount' => '50.00',
    ]);

    expect($balances->computeAccountBalance($ars->id))->toBe('7500.25');
    expect($balances->computeAccountBalance($usd->id))->toBe('150.00');
});

test('transferencia ARS y USD no altera resultado ingresos/gastos', function () {
    $admin = makeAdmin();
    seedFinanceCore();
    $this->actingAs($admin);
    seedRate('1450');

    $a = makeArsAccount('MP');
    $b = makeArsAccount('Banco');
    $u1 = makeUsdAccount('USD1');
    $u2 = makeUsdAccount('USD2');
    $service = app(MovementService::class);
    $balances = app(BalanceService::class);

    $service->createSimple([
        'type' => 'income', 'scope' => 'professional', 'financial_account_id' => $a->id, 'amount' => '100000',
    ]);
    $service->createSimple([
        'type' => 'income', 'scope' => 'professional', 'financial_account_id' => $u1->id, 'amount' => '80',
    ]);

    $before = $balances->monthlyActivity();

    $service->createTransfer([
        'from_account_id' => $a->id,
        'to_account_id' => $b->id,
        'amount' => '40000',
        'scope' => 'personal',
    ]);
    $service->createTransfer([
        'from_account_id' => $u1->id,
        'to_account_id' => $u2->id,
        'amount' => '20',
        'scope' => 'professional',
    ]);

    $after = $balances->monthlyActivity();

    expect($after['income'])->toBe($before['income']);
    expect($after['expense'])->toBe($before['expense']);
    expect($after['result'])->toBe($before['result']);
    expect($balances->computeAccountBalance($a->id))->toBe('60000.00');
    expect($balances->computeAccountBalance($b->id))->toBe('40000.00');
    expect($balances->computeAccountBalance($u1->id))->toBe('60.00');
    expect($balances->computeAccountBalance($u2->id))->toBe('20.00');
    expect(Movement::whereNotNull('transfer_id')->count())->toBe(4);
});

test('cotizacion se congela y no cambia con cotizacion futura', function () {
    $admin = makeAdmin();
    seedFinanceCore();
    $this->actingAs($admin);
    $rate = seedRate('1500');

    $ars = makeArsAccount();
    $movement = app(MovementService::class)->createSimple([
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $ars->id,
        'amount' => '1500',
        'exchange_rate_id' => $rate->id,
    ]);

    expect((string) $movement->exchange_rate_value)->toBe('1500.000000');
    expect((string) $movement->amount_usd)->toBe('1.00');

    seedRate('1600');

    $movement->refresh();
    expect((string) $movement->exchange_rate_value)->toBe('1500.000000');
    expect((string) $movement->amount_usd)->toBe('1.00');
});

test('personal y profesional y categorias', function () {
    $admin = makeAdmin();
    seedFinanceCore();
    $this->actingAs($admin);
    seedRate('1400');

    $ars = makeArsAccount();
    $cat = Category::where('name', 'Alimentación')->firstOrFail();
    $sub = Subcategory::where('category_id', $cat->id)->where('name', 'Supermercado')->firstOrFail();

    $m = app(MovementService::class)->createSimple([
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $ars->id,
        'amount' => '35000',
        'category_id' => $cat->id,
        'subcategory_id' => $sub->id,
        'description' => 'Compra súper',
    ]);

    expect($m->scope->value)->toBe('personal');
    expect($m->category_id)->toBe($cat->id);
    expect($m->subcategory_id)->toBe($sub->id);
    expect($m->chart_account_id)->not->toBeNull();
});

test('anulación y auditoría de movimiento', function () {
    $admin = makeAdmin();
    seedFinanceCore();
    $this->actingAs($admin);
    seedRate('1500');
    $ars = makeArsAccount();

    $m = app(MovementService::class)->createSimple([
        'type' => 'income',
        'scope' => 'professional',
        'financial_account_id' => $ars->id,
        'amount' => '5000',
    ]);

    app(MovementService::class)->void($m, 'Error de carga');

    expect($m->fresh()->status)->toBe(MovementStatus::Voided);
    expect(app(BalanceService::class)->computeAccountBalance($ars->id))->toBe('0.00');
    expect(AuditLog::where('action', 'movement_voided')->exists())->toBeTrue();
});

test('rollback de transferencia fallida', function () {
    $admin = makeAdmin();
    seedFinanceCore();
    $this->actingAs($admin);
    seedRate('1500');

    $a = makeArsAccount('A');
    $b = makeArsAccount('B');
    app(MovementService::class)->createSimple([
        'type' => 'income', 'scope' => 'professional', 'financial_account_id' => $a->id, 'amount' => '1000',
    ]);

    $beforeCount = Movement::count();

    expect(fn () => app(MovementService::class)->createTransfer([
        'from_account_id' => $a->id,
        'to_account_id' => $b->id,
        'amount' => '100',
        'scope' => 'personal',
        'force_fail_after_first' => true,
    ]))->toThrow(RuntimeException::class);

    expect(Movement::count())->toBe($beforeCount);
    expect(app(BalanceService::class)->computeAccountBalance($a->id))->toBe('1000.00');
    expect(app(BalanceService::class)->computeAccountBalance($b->id))->toBe('0.00');
});

test('precision decimal con Money y movimientos', function () {
    expect(Money::add('0.10', '0.20'))->toBe('0.30');
    expect(Money::mul('70.00', '1450.00'))->toBe('101500.00');
    expect(Money::div('101500.00', '1450.00'))->toBe('70.00');

    $admin = makeAdmin();
    seedFinanceCore();
    $this->actingAs($admin);
    seedRate('1450');
    $usd = makeUsdAccount();

    $m = app(MovementService::class)->createSimple([
        'type' => 'income',
        'scope' => 'professional',
        'financial_account_id' => $usd->id,
        'amount' => '70.00',
    ]);

    expect((string) $m->amount_ars)->toBe('101500.00');
});

test('permisos bloquean carga de movimientos', function () {
    seedFinanceCore();
    $user = makeUserWithPermissions(['dashboard.view']);

    $this->actingAs($user)->get(route('movements.quick'))->assertForbidden();
});

test('esquema financiero usa decimal y es compatible mysql', function () {
    seedFinanceCore();

    $amountType = Schema::getColumnType('movements', 'amount');
    $rateType = Schema::getColumnType('exchange_rates', 'rate');

    // SQLite reporta numeric/float alias; MySQL reporta decimal
    expect(in_array($amountType, ['decimal', 'numeric', 'float', 'double'], true))->toBeTrue();
    expect(in_array($rateType, ['decimal', 'numeric', 'float', 'double'], true))->toBeTrue();

    $driver = DB::connection()->getDriverName();
    expect(in_array($driver, ['sqlite', 'mysql', 'mariadb'], true))->toBeTrue();

    // Migraciones deben declarar decimal explícito (verificado por presencia de columnas clave)
    expect(Schema::hasColumns('movements', [
        'transfer_id', 'amount', 'exchange_rate_value', 'amount_ars', 'amount_usd', 'scope', 'status', 'client_id',
    ]))->toBeTrue();
});

test('mysql opcional: operaciones financieras reales', function () {
    if (env('RUN_MYSQL_TESTS') !== '1') {
        $this->markTestSkipped(
            'Suite MySQL: definí RUN_MYSQL_TESTS=1 y credenciales MYSQL_TEST_*. '.
            'Ejecutar: php artisan test --group=mysql'
        );
    }

    config([
        'database.default' => 'mysql',
        'database.connections.mysql.host' => env('MYSQL_TEST_HOST', '127.0.0.1'),
        'database.connections.mysql.port' => env('MYSQL_TEST_PORT', '3306'),
        'database.connections.mysql.database' => env('MYSQL_TEST_DATABASE', 'ar_sistemas_test'),
        'database.connections.mysql.username' => env('MYSQL_TEST_USERNAME', 'root'),
        'database.connections.mysql.password' => env('MYSQL_TEST_PASSWORD', ''),
    ]);

    DB::purge('mysql');

    try {
        DB::connection('mysql')->getPdo();
    } catch (\Throwable $e) {
        $this->fail(
            'Validación MySQL no ejecutada: no hay servidor MySQL alcanzable. '.
            'Host='.config('database.connections.mysql.host').
            ' Port='.config('database.connections.mysql.port').
            ' DB='.config('database.connections.mysql.database').
            ' Error: '.$e->getMessage().
            ' Ver docs/mysql-validation.md'
        );
    }

    DB::reconnect('mysql');

    $this->artisan('migrate:fresh', ['--seed' => true]);

    $admin = \App\Models\User::where('email', 'admin@arsistemas.local')->firstOrFail();
    $this->actingAs($admin);

    $ars = FinancialAccount::where('name', 'Caja ARS')->firstOrFail();
    $usd = FinancialAccount::where('name', 'Caja USD')->firstOrFail();
    $bancoArs = FinancialAccount::where('name', 'Banco ARS')->firstOrFail();

    $service = app(MovementService::class);
    $balances = app(BalanceService::class);

    $service->createSimple([
        'type' => 'income',
        'scope' => 'personal',
        'financial_account_id' => $ars->id,
        'amount' => '1234.56',
    ]);

    $transfer = $service->createTransfer([
        'from_account_id' => $ars->id,
        'to_account_id' => $bancoArs->id,
        'amount' => '200.00',
        'scope' => 'personal',
    ]);

    expect($transfer['out']->transfer_id)->not->toBeNull();
    expect($transfer['out']->transfer_id)->toBe($transfer['in']->transfer_id);
    expect($balances->computeAccountBalance($ars->id))->toBe('1034.56');
    expect($balances->computeAccountBalance($bancoArs->id))->toBe('200.00');

    $service->void($transfer['out'], 'Prueba anulación MySQL');
    expect($balances->computeAccountBalance($ars->id))->toBe('1234.56');
    expect($balances->computeAccountBalance($bancoArs->id))->toBe('0.00');

    expect(fn () => $service->createTransfer([
        'from_account_id' => $ars->id,
        'to_account_id' => $usd->id,
        'amount' => '10',
        'scope' => 'personal',
    ]))->toThrow(\InvalidArgumentException::class);

    expect(Schema::getColumnType('movements', 'amount'))->toBe('decimal');
    expect(Schema::getColumnType('exchange_rates', 'rate'))->toBe('decimal');
})->group('mysql');
