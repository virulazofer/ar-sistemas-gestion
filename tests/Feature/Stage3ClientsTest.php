<?php

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientLedgerEntry;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\Movement;
use App\Services\Clients\ClientLedgerService;
use App\Services\Finance\BalanceService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ExchangeRateSeeder;
use Database\Seeders\FinancialAccountSeeder;

function seedClientsStage(): void
{
    test()->seed(CurrencySeeder::class);
    test()->seed(FinancialAccountSeeder::class);
    test()->seed(ExchangeRateSeeder::class);
}

test('crear cliente particular con DNI y condición fiscal', function () {
    $admin = makeAdmin();
    seedClientsStage();

    $this->actingAs($admin)->post(route('clients.store'), [
        'name' => 'Cliente de prueba',
        'party_type' => 'particular',
        'business_name' => null,
        'cuit' => null,
        'dni' => '30111222',
        'phone' => '111',
        'email' => 'cliente@example.com',
        'address' => 'Calle 1',
        'tax_condition' => 'consumidor_final',
        'status' => 'active',
        'notes' => 'Nota',
    ])->assertRedirect();

    expect(Client::where('dni', '30111222')->exists())->toBeTrue();
    expect(AuditLog::where('action', 'client_created')->exists())->toBeTrue();
});

test('crear cliente empresa requiere CUIT y razón social', function () {
    $admin = makeAdmin();
    seedClientsStage();

    $this->actingAs($admin)->post(route('clients.store'), [
        'name' => 'Empresa SA',
        'party_type' => 'empresa',
        'business_name' => 'Empresa SA',
        'cuit' => '30-71234567-1',
        'dni' => null,
        'tax_condition' => 'responsable_inscripto',
        'status' => 'active',
    ])->assertRedirect();

    expect(Client::where('cuit', '30712345671')->exists())->toBeTrue();
});

test('cliente empresa sin CUIT falla validación', function () {
    $admin = makeAdmin();
    seedClientsStage();

    $this->actingAs($admin)->post(route('clients.store'), [
        'name' => 'Empresa incompleta',
        'party_type' => 'empresa',
        'business_name' => 'Empresa incompleta SA',
        'cuit' => null,
        'tax_condition' => 'responsable_inscripto',
        'status' => 'active',
    ])->assertSessionHasErrors(['cuit']);
});

test('cargos ARS/USD independientes y sin movimiento financiero', function () {
    $admin = makeAdmin();
    seedClientsStage();
    $this->actingAs($admin);

    $client = Client::query()->create(['name' => 'Cliente X', 'status' => 'active']);
    $ledger = app(ClientLedgerService::class);

    $ledger->registerCharge($client, ['currency_code' => 'ARS', 'amount' => '350000']);
    $ledger->registerCharge($client, ['currency_code' => 'USD', 'amount' => '1000']);

    $balances = $ledger->balances($client);
    expect($balances['ARS'])->toBe('-350000.00');
    expect($balances['USD'])->toBe('-1000.00');
    expect(Movement::count())->toBe(0);
});

test('pago genera CC y movimiento financiero con trazabilidad', function () {
    $admin = makeAdmin();
    seedClientsStage();
    $this->actingAs($admin);

    $client = Client::query()->create(['name' => 'Cliente de prueba', 'status' => 'active']);
    $ledger = app(ClientLedgerService::class);
    $bankUsd = FinancialAccount::where('name', 'Banco USD')->firstOrFail();

    $ledger->registerCharge($client, ['currency_code' => 'USD', 'amount' => '1000']);
    expect(app(BalanceService::class)->computeAccountBalance($bankUsd->id))->toBe('0.00');

    $pay1 = $ledger->registerPayment($client, [
        'financial_account_id' => $bankUsd->id,
        'amount' => '600',
    ]);

    expect($ledger->balanceFor($client, 'USD'))->toBe('-400.00');
    expect(app(BalanceService::class)->computeAccountBalance($bankUsd->id))->toBe('600.00');
    expect($pay1['ledger']->financial_movement_id)->toBe($pay1['movement']->id);
    expect($pay1['movement']->client_id)->toBe($client->id);

    $pay2 = $ledger->registerPayment($client, [
        'financial_account_id' => $bankUsd->id,
        'amount' => '500',
    ]);

    expect($ledger->balanceFor($client, 'USD'))->toBe('100.00');
    expect(app(BalanceService::class)->computeAccountBalance($bankUsd->id))->toBe('1100.00');
    expect($pay2['ledger']->type->value)->toBe('payment');
});

test('pago ARS también afecta finanzas', function () {
    $admin = makeAdmin();
    seedClientsStage();
    $this->actingAs($admin);
    $client = Client::query()->create(['name' => 'ARS Client', 'status' => 'active']);
    $mp = FinancialAccount::where('name', 'Mercado Pago')->firstOrFail();
    $ledger = app(ClientLedgerService::class);

    $ledger->registerCharge($client, ['currency_code' => 'ARS', 'amount' => '10000']);
    $ledger->registerPayment($client, ['financial_account_id' => $mp->id, 'amount' => '4000']);

    expect($ledger->balanceFor($client, 'ARS'))->toBe('-6000.00');
    expect(app(BalanceService::class)->computeAccountBalance($mp->id))->toBe('4000.00');
});

test('rollback si falla finanzas o CC en pago', function () {
    $admin = makeAdmin();
    seedClientsStage();
    $this->actingAs($admin);
    $client = Client::query()->create(['name' => 'Rollback', 'status' => 'active']);
    $bank = FinancialAccount::where('name', 'Banco USD')->firstOrFail();
    $ledger = app(ClientLedgerService::class);
    $ledger->registerCharge($client, ['currency_code' => 'USD', 'amount' => '100']);

    $beforeMovements = Movement::count();
    $beforeLedger = ClientLedgerEntry::count();

    expect(fn () => $ledger->registerPayment($client, [
        'financial_account_id' => $bank->id,
        'amount' => '50',
        'force_fail_finance' => true,
    ]))->toThrow(RuntimeException::class);

    expect(Movement::count())->toBe($beforeMovements);
    expect(ClientLedgerEntry::count())->toBe($beforeLedger);

    expect(fn () => $ledger->registerPayment($client, [
        'financial_account_id' => $bank->id,
        'amount' => '50',
        'force_fail_after_ledger' => true,
    ]))->toThrow(RuntimeException::class);

    expect(Movement::count())->toBe($beforeMovements);
    expect(ClientLedgerEntry::count())->toBe($beforeLedger);
    expect(app(BalanceService::class)->computeAccountBalance($bank->id))->toBe('0.00');
    expect($ledger->balanceFor($client, 'USD'))->toBe('-100.00');
});

test('credito, aplicar credito, ajuste, anulacion y permisos', function () {
    $admin = makeAdmin();
    seedClientsStage();
    $this->actingAs($admin);
    $client = Client::query()->create(['name' => 'Creditos', 'status' => 'active']);
    $ledger = app(ClientLedgerService::class);

    $ledger->registerCredit($client, ['currency_code' => 'USD', 'amount' => '300']);
    expect($ledger->balanceFor($client, 'USD'))->toBe('300.00');

    $ledger->applyCredit($client, ['currency_code' => 'USD', 'amount' => '100']);
    expect($ledger->balanceFor($client, 'USD'))->toBe('200.00');

    $adj = $ledger->registerAdjustment($client, [
        'currency_code' => 'USD',
        'amount' => '50',
        'sign' => -1,
        'reason' => 'Corrección de saldo',
    ]);
    expect($ledger->balanceFor($client, 'USD'))->toBe('150.00');
    expect(AuditLog::where('action', 'client_ledger_adjustment')->exists())->toBeTrue();

    $ledger->void($adj, 'Anulación de prueba');
    expect($ledger->balanceFor($client, 'USD'))->toBe('200.00');
    expect(AuditLog::where('action', 'client_ledger_voided')->exists())->toBeTrue();

    $user = makeUserWithPermissions(['dashboard.view']);
    $this->actingAs($user)->get(route('clients.index'))->assertForbidden();
});

test('ars y usd permanecen independientes tras operaciones mixtas', function () {
    $admin = makeAdmin();
    seedClientsStage();
    $this->actingAs($admin);
    $client = Client::query()->create(['name' => 'Mixto', 'status' => 'active']);
    $ledger = app(ClientLedgerService::class);
    $bankUsd = FinancialAccount::where('name', 'Banco USD')->firstOrFail();
    $bancoArs = FinancialAccount::where('name', 'Banco ARS')->firstOrFail();

    $ledger->registerCharge($client, ['currency_code' => 'USD', 'amount' => '500']);
    $ledger->registerCharge($client, ['currency_code' => 'ARS', 'amount' => '200000']);
    $ledger->registerPayment($client, ['financial_account_id' => $bankUsd->id, 'amount' => '100']);
    $ledger->registerPayment($client, ['financial_account_id' => $bancoArs->id, 'amount' => '50000']);

    $b = $ledger->balances($client);
    expect($b['USD'])->toBe('-400.00');
    expect($b['ARS'])->toBe('-150000.00');
});
