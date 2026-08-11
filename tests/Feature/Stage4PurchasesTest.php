<?php

use App\Models\AuditLog;
use App\Models\ExchangeRate;
use App\Models\FinancialAccount;
use App\Models\Movement;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Services\Finance\BalanceService;
use App\Services\Purchases\PurchaseService;
use App\Services\Suppliers\SupplierLedgerService;
use App\Services\Suppliers\SupplierPaymentService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ExchangeRateSeeder;
use Database\Seeders\FinancialAccountSeeder;

function seedPurchasesStage(): void
{
    test()->seed(CurrencySeeder::class);
    test()->seed(FinancialAccountSeeder::class);
    test()->seed(ExchangeRateSeeder::class);
}

function makeSupplier(array $attrs = []): Supplier
{
    return Supplier::query()->create(array_merge([
        'name' => 'Proveedor X',
        'business_name' => 'Proveedor X SA',
        'cuit' => '30-11111111-8',
        'tax_condition' => 'Responsable Inscripto',
        'status' => 'active',
    ], $attrs));
}

test('crear proveedor con datos fiscales', function () {
    $admin = makeAdmin();
    seedPurchasesStage();

    $this->actingAs($admin)->post(route('suppliers.store'), [
        'name' => 'Insumos Norte',
        'party_type' => 'empresa',
        'business_name' => 'Insumos Norte SRL',
        'cuit' => '30-22222222-9',
        'dni' => null,
        'tax_condition' => 'responsable_inscripto',
        'phone' => '111',
        'email' => 'prov@example.com',
        'address' => 'Calle 1',
        'contact_name' => 'Juan',
        'status' => 'active',
        'notes' => 'Nota',
    ])->assertRedirect();

    expect(Supplier::where('cuit', '30222222229')->exists())->toBeTrue();
    expect(AuditLog::where('action', 'supplier_created')->exists())->toBeTrue();
});

test('escenario A — compra contado ARS', function () {
    $admin = makeAdmin();
    seedPurchasesStage();
    $this->actingAs($admin);

    $supplier = makeSupplier();
    $bankArs = FinancialAccount::where('name', 'Banco ARS')->firstOrFail();
    $service = app(PurchaseService::class);
    $ledger = app(SupplierLedgerService::class);

    $purchase = $service->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'ARS',
        'payment_mode' => 'cash',
        'financial_account_id' => $bankArs->id,
        'items' => [
            ['description' => 'Ítem ARS', 'quantity' => '1', 'unit_price' => '100000'],
        ],
    ]);

    expect($purchase->total)->toBe('100000.00');
    expect($purchase->financial_movement_id)->not->toBeNull();
    expect($purchase->obligation_ledger_entry_id)->toBeNull();
    expect(app(BalanceService::class)->computeAccountBalance($bankArs->id))->toBe('-100000.00');
    expect($ledger->balanceFor($supplier, 'ARS'))->toBe('0.00');
    expect(SupplierLedgerEntry::where('supplier_id', $supplier->id)->count())->toBe(0);
});

test('escenario B — compra contado USD', function () {
    $admin = makeAdmin();
    seedPurchasesStage();
    $this->actingAs($admin);

    $supplier = makeSupplier(['name' => 'Prov USD']);
    $bankUsd = FinancialAccount::where('name', 'Banco USD')->firstOrFail();
    $service = app(PurchaseService::class);
    $ledger = app(SupplierLedgerService::class);

    $purchase = $service->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'cash',
        'financial_account_id' => $bankUsd->id,
        'items' => [
            ['description' => 'Ítem USD', 'quantity' => '1', 'unit_price' => '500'],
        ],
    ]);

    expect($purchase->total)->toBe('500.00');
    expect(app(BalanceService::class)->computeAccountBalance($bankUsd->id))->toBe('-500.00');
    expect($ledger->balanceFor($supplier, 'USD'))->toBe('0.00');
    expect(Movement::where('id', $purchase->financial_movement_id)->value('supplier_id'))->toBe($supplier->id);
});

test('escenario C — compra a crédito genera obligación sin banco', function () {
    $admin = makeAdmin();
    seedPurchasesStage();
    $this->actingAs($admin);

    $supplier = makeSupplier();
    $bankUsd = FinancialAccount::where('name', 'Banco USD')->firstOrFail();
    $service = app(PurchaseService::class);
    $ledger = app(SupplierLedgerService::class);

    $purchase = $service->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [
            ['description' => 'Crédito', 'quantity' => '1', 'unit_price' => '1000'],
        ],
    ]);

    expect($purchase->obligation_ledger_entry_id)->not->toBeNull();
    expect($purchase->financial_movement_id)->toBeNull();
    expect($ledger->balanceFor($supplier, 'USD'))->toBe('-1000.00');
    expect(app(BalanceService::class)->computeAccountBalance($bankUsd->id))->toBe('0.00');
});

test('escenario D y E — pago posterior y pago superior a deuda', function () {
    $admin = makeAdmin();
    seedPurchasesStage();
    $this->actingAs($admin);

    $supplier = makeSupplier();
    $bankUsd = FinancialAccount::where('name', 'Banco USD')->firstOrFail();
    $purchases = app(PurchaseService::class);
    $payments = app(SupplierPaymentService::class);
    $ledger = app(SupplierLedgerService::class);

    $purchase = $purchases->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [
            ['description' => 'Deuda', 'quantity' => '1', 'unit_price' => '1000'],
        ],
    ]);

    $pay1 = $payments->pay($supplier, [
        'financial_account_id' => $bankUsd->id,
        'amount' => '600',
        'purchase_id' => $purchase->id,
    ]);

    expect($ledger->balanceFor($supplier, 'USD'))->toBe('-400.00');
    expect(app(BalanceService::class)->computeAccountBalance($bankUsd->id))->toBe('-600.00');
    expect($pay1['ledger']->financial_movement_id)->toBe($pay1['movement']->id);
    expect($pay1['movement']->supplier_id)->toBe($supplier->id);

    $payments->pay($supplier, [
        'financial_account_id' => $bankUsd->id,
        'amount' => '700',
    ]);

    expect($ledger->balanceFor($supplier, 'USD'))->toBe('300.00');
    expect(app(BalanceService::class)->computeAccountBalance($bankUsd->id))->toBe('-1300.00');
});

test('escenario F — anulación compra contado y crédito', function () {
    $admin = makeAdmin();
    seedPurchasesStage();
    $this->actingAs($admin);

    $supplier = makeSupplier();
    $bankUsd = FinancialAccount::where('name', 'Banco USD')->firstOrFail();
    $service = app(PurchaseService::class);
    $ledger = app(SupplierLedgerService::class);

    $cash = $service->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'cash',
        'financial_account_id' => $bankUsd->id,
        'items' => [['description' => 'Cash', 'quantity' => '1', 'unit_price' => '200']],
    ]);
    expect(app(BalanceService::class)->computeAccountBalance($bankUsd->id))->toBe('-200.00');

    $service->void($cash, 'Error carga');
    expect($cash->fresh()->status->value)->toBe('voided');
    expect(app(BalanceService::class)->computeAccountBalance($bankUsd->id))->toBe('0.00');
    expect(Movement::find($cash->financial_movement_id)->status->value)->toBe('voided');

    $credit = $service->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [['description' => 'Credit', 'quantity' => '1', 'unit_price' => '300']],
    ]);
    expect($ledger->balanceFor($supplier, 'USD'))->toBe('-300.00');

    $service->void($credit, 'Anulación crédito');
    expect($ledger->balanceFor($supplier, 'USD'))->toBe('0.00');
    expect(SupplierLedgerEntry::find($credit->obligation_ledger_entry_id)->status->value)->toBe('voided');
});

test('escenario F — no anular crédito con pagos vinculados', function () {
    $admin = makeAdmin();
    seedPurchasesStage();
    $this->actingAs($admin);

    $supplier = makeSupplier();
    $bankUsd = FinancialAccount::where('name', 'Banco USD')->firstOrFail();
    $service = app(PurchaseService::class);
    $payments = app(SupplierPaymentService::class);

    $purchase = $service->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [['description' => 'Con pago', 'quantity' => '1', 'unit_price' => '500']],
    ]);
    $payments->pay($supplier, [
        'financial_account_id' => $bankUsd->id,
        'amount' => '100',
        'purchase_id' => $purchase->id,
    ]);

    expect(fn () => $service->void($purchase, 'No debería'))->toThrow(InvalidArgumentException::class);
    expect($purchase->fresh()->status->value)->toBe('posted');
});

test('escenario G — rollback en compra y en pago', function () {
    $admin = makeAdmin();
    seedPurchasesStage();
    $this->actingAs($admin);

    $supplier = makeSupplier();
    $bankUsd = FinancialAccount::where('name', 'Banco USD')->firstOrFail();
    $service = app(PurchaseService::class);
    $payments = app(SupplierPaymentService::class);
    $ledger = app(SupplierLedgerService::class);

    $beforePurchases = Purchase::count();
    $beforeItems = PurchaseItem::count();
    $beforeMovements = Movement::count();
    $beforeLedger = SupplierLedgerEntry::count();

    expect(fn () => $service->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'cash',
        'financial_account_id' => $bankUsd->id,
        'force_fail' => true,
        'items' => [['description' => 'Fail', 'quantity' => '1', 'unit_price' => '50']],
    ]))->toThrow(RuntimeException::class);

    expect(Purchase::count())->toBe($beforePurchases);
    expect(PurchaseItem::count())->toBe($beforeItems);
    expect(Movement::count())->toBe($beforeMovements);
    expect(app(BalanceService::class)->computeAccountBalance($bankUsd->id))->toBe('0.00');

    $service->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [['description' => 'OK', 'quantity' => '1', 'unit_price' => '100']],
    ]);

    expect(fn () => $payments->pay($supplier, [
        'financial_account_id' => $bankUsd->id,
        'amount' => '40',
        'force_fail_finance' => true,
    ]))->toThrow(RuntimeException::class);

    expect(Movement::count())->toBe($beforeMovements);
    expect(SupplierLedgerEntry::count())->toBe($beforeLedger + 1);
    expect($ledger->balanceFor($supplier, 'USD'))->toBe('-100.00');
    expect(app(BalanceService::class)->computeAccountBalance($bankUsd->id))->toBe('0.00');

    expect(fn () => $payments->pay($supplier, [
        'financial_account_id' => $bankUsd->id,
        'amount' => '40',
        'force_fail_after_ledger' => true,
    ]))->toThrow(RuntimeException::class);

    expect(Movement::count())->toBe($beforeMovements);
    expect(SupplierLedgerEntry::count())->toBe($beforeLedger + 1);
    expect(app(BalanceService::class)->computeAccountBalance($bankUsd->id))->toBe('0.00');
});

test('escenario H — cotización histórica congelada', function () {
    $admin = makeAdmin();
    seedPurchasesStage();
    $this->actingAs($admin);

    $supplier = makeSupplier();
    $rate = ExchangeRate::query()->latest('id')->firstOrFail();
    $frozen = (string) $rate->rate;

    $purchase = app(PurchaseService::class)->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'exchange_rate_id' => $rate->id,
        'items' => [
            ['description' => '5 x 70', 'quantity' => '5', 'unit_price' => '70'],
        ],
    ]);

    $item = $purchase->items->first();
    expect($purchase->exchange_rate_value)->toBe(\App\Support\Money::normalize($frozen, 6));
    expect($item->exchange_rate_value)->toBe(\App\Support\Money::normalize($frozen, 6));
    expect($item->line_total)->toBe('350.00');
    expect($item->line_total_usd)->toBe('350.00');
    expect($item->line_total_ars)->toBe(\App\Support\Money::mul('350', \App\Support\Money::normalize($frozen, 6)));

    ExchangeRate::query()->create([
        'base_currency_id' => $rate->base_currency_id,
        'quote_currency_id' => $rate->quote_currency_id,
        'rate_type' => $rate->rate_type ?? 'sell',
        'rate' => '9999.000000',
        'rate_at' => now(),
        'source' => 'manual',
        'provider' => 'manual',
    ]);

    $purchase->refresh();
    $item->refresh();
    expect($purchase->exchange_rate_value)->toBe(\App\Support\Money::normalize($frozen, 6));
    expect($item->unit_cost_usd)->toBe('70.000000');
    expect($item->line_total_ars)->toBe(\App\Support\Money::mul('350', \App\Support\Money::normalize($frozen, 6)));
});

test('escenario I — permisos compras y pagos', function () {
    seedPurchasesStage();
    $supplier = makeSupplier();
    $bankUsd = FinancialAccount::where('name', 'Banco USD')->firstOrFail();

    $viewer = makeUserWithPermissions(['purchases.view', 'suppliers.view']);
    $this->actingAs($viewer)
        ->post(route('purchases.store'), [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->toDateString(),
            'currency_code' => 'USD',
            'payment_mode' => 'credit',
            'items' => [
                ['description' => 'X', 'quantity' => '1', 'unit_price' => '10'],
            ],
        ])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->post(route('suppliers.ledger.payment.store', $supplier), [
            'financial_account_id' => $bankUsd->id,
            'amount' => '10',
            'entry_date' => now()->toDateString(),
        ])
        ->assertForbidden();

    $admin = makeAdmin();
    $this->actingAs($admin);
    $purchase = app(PurchaseService::class)->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [['description' => 'Y', 'quantity' => '1', 'unit_price' => '25']],
    ]);

    $this->actingAs($viewer)
        ->post(route('purchases.void', $purchase), ['void_reason' => 'no'])
        ->assertForbidden();

    expect($purchase->fresh()->status->value)->toBe('posted');
});
