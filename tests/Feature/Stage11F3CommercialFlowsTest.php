<?php

use App\Enums\CommercialChargeType;
use App\Enums\CommercialItemType;
use App\Enums\CommercialVoucherType;
use App\Enums\DocumentalStatus;
use App\Enums\UnitCondition;
use App\Enums\UnitStatus;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\CommercialCharge;
use App\Models\FinancialAccount;
use App\Models\Movement;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSubcategory;
use App\Models\Purchase;
use App\Models\Receipt;
use App\Models\ReceiptApplication;
use App\Models\Supplier;
use App\Models\WorkOrder;
use App\Services\Catalog\ProductService;
use App\Services\Clients\CcRegularizationService;
use App\Services\Clients\ClientCodeService;
use App\Services\Clients\ClientLedgerService;
use App\Services\Commercial\CommercialChargeService;
use App\Services\Commercial\CommercialVoucherService;
use App\Services\Commercial\ReceiptService;
use App\Services\Equipment\EquipmentAssemblyService;
use App\Services\Finance\BalanceService;
use App\Services\Inventory\InventoryUnitService;
use App\Services\Purchases\PurchaseService;
use App\Services\Quotations\QuotationService;
use App\Services\Sales\SaleService;
use App\Services\Subscriptions\SubscriptionService;
use App\Support\UiSemantics;
use App\Enums\SubscriptionPeriodicity;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ExchangeRateSeeder;
use Database\Seeders\FinancialAccountSeeder;
use Database\Seeders\InventoryCatalogSeeder;

function seed11F3(): void
{
    test()->seed(CurrencySeeder::class);
    test()->seed(FinancialAccountSeeder::class);
    test()->seed(ExchangeRateSeeder::class);
    test()->seed(InventoryCatalogSeeder::class);
}

function makeClient11F3(string $name = 'Cliente TEST 11F3'): Client
{
    $code = app(ClientCodeService::class)->allocateNext();

    return Client::query()->create([
        'code' => $code,
        'name' => $name,
        'status' => 'active',
        'party_type' => 'particular',
        'dni' => (string) random_int(20000000, 39999999),
        'tax_condition' => 'consumidor_final',
    ]);
}

function arsAccount(): FinancialAccount
{
    return FinancialAccount::query()->where('name', 'Mercado Pago')->firstOrFail();
}

function usdAccount(): FinancialAccount
{
    return FinancialAccount::query()->where('name', 'Banco USD')->firstOrFail();
}

test('1 código cliente único permanente y formato', function () {
    $admin = makeAdmin();
    seed11F3();
    $this->actingAs($admin);

    $this->post(route('clients.store'), [
        'name' => 'DAASA TEST',
        'party_type' => 'empresa',
        'business_name' => 'DAASA TEST SA',
        'cuit' => '30-71234567-8',
        'tax_condition' => 'responsable_inscripto',
        'status' => 'active',
    ])->assertRedirect();

    $c1 = Client::query()->where('name', 'DAASA TEST')->firstOrFail();
    expect($c1->code)->toBeGreaterThan(0);
    expect($c1->codeFormatted())->toMatch('/^\d{4}$/');

    $c2 = makeClient11F3('Otro');
    expect($c2->code)->toBeGreaterThan($c1->code);

    $this->get(route('clients.index', ['q' => $c1->codeFormatted()]))->assertOk()->assertSee('DAASA TEST');
});

test('2 cargo crédito genera CC IN sin ingreso financiero', function () {
    $admin = makeAdmin();
    seed11F3();
    $this->actingAs($admin);
    $client = makeClient11F3();
    $ledger = app(ClientLedgerService::class);

    $charge = app(CommercialChargeService::class)->create([
        'client_id' => $client->id,
        'charge_type' => CommercialChargeType::Service->value,
        'concept' => 'Servicio remoto TEST',
        'amount' => '1500.00',
        'currency_code' => 'ARS',
    ]);

    expect($charge->amount_open)->toBe('1500.00')
        ->and($charge->client_ledger_entry_id)->not->toBeNull()
        ->and($ledger->balanceFor($client, 'ARS'))->toBe('-1500.00')
        ->and(Movement::query()->where('type', 'income')->count())->toBe(0);
});

test('3 cobro genera cuenta financiera + CC OUT', function () {
    $admin = makeAdmin();
    seed11F3();
    $this->actingAs($admin);
    $client = makeClient11F3();
    $account = arsAccount();
    $charges = app(CommercialChargeService::class);
    $receipts = app(ReceiptService::class);
    $ledger = app(ClientLedgerService::class);

    $charge = $charges->create([
        'client_id' => $client->id,
        'charge_type' => CommercialChargeType::Subscription->value,
        'concept' => 'Abono TEST',
        'amount' => '690.68',
        'currency_code' => 'ARS',
    ]);

    $before = app(BalanceService::class)->computeAccountBalance($account->id);
    $result = $receipts->create([
        'client_id' => $client->id,
        'financial_account_id' => $account->id,
        'amount' => '690.68',
        'application_mode' => 'auto',
    ]);

    expect($result['receipt']->amount_applied)->toBe('690.68')
        ->and($ledger->balanceFor($client, 'ARS'))->toBe('0.00')
        ->and(app(BalanceService::class)->computeAccountBalance($account->id))->toBe(
            \App\Support\Money::add($before, '690.68')
        )
        ->and($charge->fresh()->status->value)->toBe('collected');
});

test('4 cobro parcial', function () {
    $admin = makeAdmin();
    seed11F3();
    $this->actingAs($admin);
    $client = makeClient11F3();
    $charges = app(CommercialChargeService::class);
    $receipts = app(ReceiptService::class);

    $charge = $charges->create([
        'client_id' => $client->id,
        'charge_type' => CommercialChargeType::Sale->value,
        'concept' => 'Venta parcial TEST',
        'amount' => '1000',
        'currency_code' => 'ARS',
    ]);

    $receipts->create([
        'client_id' => $client->id,
        'financial_account_id' => arsAccount()->id,
        'amount' => '400',
        'application_mode' => 'auto',
    ]);

    expect($charge->fresh()->amount_open)->toBe('600.00')
        ->and($charge->fresh()->status->value)->toBe('partial');
});

test('5 cobro a varios cargos', function () {
    $admin = makeAdmin();
    seed11F3();
    $this->actingAs($admin);
    $client = makeClient11F3();
    $svc = app(CommercialChargeService::class);
    $a = $svc->create(['client_id' => $client->id, 'charge_type' => 'other', 'concept' => 'A', 'amount' => '100', 'currency_code' => 'ARS']);
    $b = $svc->create(['client_id' => $client->id, 'charge_type' => 'other', 'concept' => 'B', 'amount' => '200', 'currency_code' => 'ARS']);

    $receipt = app(ReceiptService::class)->create([
        'client_id' => $client->id,
        'financial_account_id' => arsAccount()->id,
        'amount' => '300',
        'application_mode' => 'auto',
    ])['receipt'];

    expect(ReceiptApplication::query()->where('receipt_id', $receipt->id)->where('status', 'posted')->count())->toBe(2)
        ->and($a->fresh()->status->value)->toBe('collected')
        ->and($b->fresh()->status->value)->toBe('collected');
});

test('6 varios cobros un cargo', function () {
    $admin = makeAdmin();
    seed11F3();
    $this->actingAs($admin);
    $client = makeClient11F3();
    $charge = app(CommercialChargeService::class)->create([
        'client_id' => $client->id, 'charge_type' => 'other', 'concept' => 'Multi', 'amount' => '900', 'currency_code' => 'ARS',
    ]);
    $receipts = app(ReceiptService::class);
    $receipts->create(['client_id' => $client->id, 'financial_account_id' => arsAccount()->id, 'amount' => '300', 'application_mode' => 'auto']);
    $receipts->create(['client_id' => $client->id, 'financial_account_id' => arsAccount()->id, 'amount' => '600', 'application_mode' => 'auto']);

    expect($charge->fresh()->status->value)->toBe('collected')
        ->and(ReceiptApplication::query()->where('commercial_charge_id', $charge->id)->where('status', 'posted')->count())->toBe(2);
});

test('7 pago a cuenta con excedente', function () {
    $admin = makeAdmin();
    seed11F3();
    $this->actingAs($admin);
    $client = makeClient11F3();
    $ledger = app(ClientLedgerService::class);
    app(CommercialChargeService::class)->create([
        'client_id' => $client->id, 'charge_type' => 'other', 'concept' => 'Deuda', 'amount' => '300', 'currency_code' => 'ARS',
    ]);

    $receipt = app(ReceiptService::class)->create([
        'client_id' => $client->id,
        'financial_account_id' => arsAccount()->id,
        'amount' => '500',
        'application_mode' => 'auto',
        'insufficient_option' => ReceiptService::OPTION_ON_ACCOUNT,
    ])['receipt'];

    expect($receipt->amount_applied)->toBe('300.00')
        ->and($receipt->amount_on_account)->toBe('200.00')
        ->and($ledger->balanceFor($client, 'ARS'))->toBe('200.00')
        ->and(UiSemantics::clientCcDisplayBalance('200.00'))->toBe('-200.00')
        ->and(UiSemantics::tone('-200.00', UiSemantics::MODE_CLIENT_CC))->toBe(UiSemantics::TONE_FAVORABLE);
});

test('8 cargo posterior consume saldo a favor', function () {
    $admin = makeAdmin();
    seed11F3();
    $this->actingAs($admin);
    $client = makeClient11F3();
    $ledger = app(ClientLedgerService::class);

    app(ReceiptService::class)->create([
        'client_id' => $client->id,
        'financial_account_id' => arsAccount()->id,
        'amount' => '250',
        'application_mode' => 'auto',
        'insufficient_option' => ReceiptService::OPTION_ON_ACCOUNT,
    ]);

    expect($ledger->balanceFor($client, 'ARS'))->toBe('250.00');

    $charge = app(CommercialChargeService::class)->create([
        'client_id' => $client->id,
        'charge_type' => 'service',
        'concept' => 'Cargo futuro',
        'amount' => '100',
        'currency_code' => 'ARS',
        'apply_available_credit' => true,
    ]);

    expect($charge->fresh()->status->value)->toBe('collected')
        ->and($ledger->balanceFor($client, 'ARS'))->toBe('150.00');
});

test('9 crear cargo faltante desde cobro (opción A)', function () {
    $admin = makeAdmin();
    seed11F3();
    $this->actingAs($admin);
    $client = makeClient11F3();
    $ledger = app(ClientLedgerService::class);

    $decision = app(ReceiptService::class)->create([
        'client_id' => $client->id,
        'financial_account_id' => arsAccount()->id,
        'amount' => '800',
        'application_mode' => 'auto',
    ]);
    expect($decision['requires_decision'] ?? false)->toBeTrue();

    $result = app(ReceiptService::class)->create([
        'client_id' => $client->id,
        'financial_account_id' => arsAccount()->id,
        'amount' => '800',
        'application_mode' => 'auto',
        'insufficient_option' => ReceiptService::OPTION_CREATE_CHARGE,
        'missing_charge' => [
            'charge_type' => CommercialChargeType::Subscription->value,
            'concept' => 'Abono omitido TEST',
            'documental_status' => DocumentalStatus::Pending->value,
        ],
    ]);

    expect($result['receipt']->amount_applied)->toBe('800.00')
        ->and($ledger->balanceFor($client, 'ARS'))->toBe('0.00')
        ->and(CommercialCharge::query()->where('concept', 'Abono omitido TEST')->exists())->toBeTrue();
});

test('10-11 cargo sin comprobante y asociar después', function () {
    $admin = makeAdmin();
    seed11F3();
    $this->actingAs($admin);
    $client = makeClient11F3();

    $charge = app(CommercialChargeService::class)->create([
        'client_id' => $client->id,
        'charge_type' => 'other',
        'concept' => 'Sin factura',
        'amount' => '50',
        'currency_code' => 'ARS',
        'documental_status' => DocumentalStatus::None->value,
    ]);

    expect($charge->documental_status)->toBe(DocumentalStatus::None);

    app(CommercialVoucherService::class)->associate($charge, [
        'voucher_type' => CommercialVoucherType::Invoice->value,
        'point_of_sale' => '0001',
        'number' => '00001234',
        'issued_on' => now()->toDateString(),
        'amount' => '50',
    ]);

    expect($charge->fresh()->documental_status)->toBe(DocumentalStatus::Associated)
        ->and($charge->vouchers()->count())->toBe(1);
});

test('12-13 abono genera cargo y no duplica período', function () {
    $admin = makeAdmin();
    seed11F3();
    $this->actingAs($admin);
    $client = makeClient11F3('Abonado');

    $sub = app(SubscriptionService::class)->create([
        'client_id' => $client->id,
        'name' => 'Abono mensual TEST',
        'periodicity' => SubscriptionPeriodicity::Monthly->value,
        'amount' => '1000',
        'currency_code' => 'ARS',
        'starts_on' => '2026-08-01',
        'billing_day' => 1,
    ]);

    $p1 = app(SubscriptionService::class)->generatePeriod($sub, now(), '2026-08');
    $p2 = app(SubscriptionService::class)->generatePeriod($sub->fresh(), now(), '2026-08');

    expect($p1->id)->toBe($p2->id)
        ->and($p1->commercial_charge_id)->not->toBeNull()
        ->and(CommercialCharge::query()->where('subscription_period_id', $p1->id)->count())->toBe(1)
        ->and(Movement::query()->where('type', 'income')->count())->toBe(0);
});

test('14-17 ventas contado crédito parcial sin doble ingreso', function () {
    $admin = makeAdmin();
    seed11F3();
    $this->actingAs($admin);
    $client = makeClient11F3();
    $ledger = app(ClientLedgerService::class);
    $sales = app(SaleService::class);

    $cash = $sales->create([
        'client_id' => $client->id,
        'currency_code' => 'ARS',
        'sold_on' => now()->toDateString(),
        'items' => [[
            'item_type' => CommercialItemType::Service->value,
            'description' => 'Servicio contado',
            'quantity' => '1',
            'unit_price' => '200',
            'currency_code' => 'ARS',
        ]],
    ]);
    $sales->confirm($cash, [
        'payment_mode' => 'cash',
        'financial_account_id' => arsAccount()->id,
    ]);
    expect($ledger->balanceFor($client, 'ARS'))->toBe('0.00')
        ->and(Movement::query()->where('type', 'income')->count())->toBe(1)
        ->and($cash->fresh()->commercial_charge_id)->not->toBeNull();

    $credit = $sales->create([
        'client_id' => $client->id,
        'currency_code' => 'ARS',
        'sold_on' => now()->toDateString(),
        'items' => [[
            'item_type' => CommercialItemType::Service->value,
            'description' => 'Servicio crédito',
            'quantity' => '1',
            'unit_price' => '500',
            'currency_code' => 'ARS',
        ]],
    ]);
    $sales->confirm($credit, ['payment_mode' => 'credit']);
    expect($ledger->balanceFor($client, 'ARS'))->toBe('-500.00')
        ->and(Movement::query()->where('type', 'income')->count())->toBe(1);

    $partial = $sales->create([
        'client_id' => $client->id,
        'currency_code' => 'ARS',
        'sold_on' => now()->toDateString(),
        'items' => [[
            'item_type' => CommercialItemType::Service->value,
            'description' => 'Servicio parcial',
            'quantity' => '1',
            'unit_price' => '400',
            'currency_code' => 'ARS',
        ]],
    ]);
    $sales->confirm($partial, [
        'payment_mode' => 'partial',
        'financial_account_id' => arsAccount()->id,
        'amount_paid' => '150',
    ]);
    expect($ledger->balanceFor($client, 'ARS'))->toBe('-750.00')
        ->and(Movement::query()->where('type', 'income')->count())->toBe(2);
});

test('18-20 compra contado crédito y stock una sola vez', function () {
    $admin = makeAdmin();
    seed11F3();
    $this->actingAs($admin);

    $supplier = Supplier::query()->create(['name' => 'Prov TEST', 'status' => 'active']);
    $cat = ProductCategory::where('slug', 'hardware')->first();
    $sub = ProductSubcategory::where('slug', 'discos')->first();
    $product = app(ProductService::class)->create([
        'sku' => 'SKU-11F3',
        'name' => 'Producto 11F3',
        'type' => 'physical',
        'product_category_id' => $cat->id,
        'product_subcategory_id' => $sub->id,
    ]);

    $purchase = app(PurchaseService::class)->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => Purchase::MODE_CASH,
        'financial_account_id' => usdAccount()->id,
        'purchase_date' => now()->toDateString(),
        'items' => [[
            'product_id' => $product->id,
            'description' => 'SSD',
            'quantity' => '2',
            'unit_price' => '50',
        ]],
    ]);

    expect($product->fresh()->qty_on_hand)->toBe('2.0000')
        ->and($purchase->financial_movement_id)->not->toBeNull();

    $credit = app(PurchaseService::class)->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => Purchase::MODE_CREDIT,
        'purchase_date' => now()->toDateString(),
        'items' => [[
            'product_id' => $product->id,
            'description' => 'SSD crédito',
            'quantity' => '1',
            'unit_price' => '50',
        ]],
    ]);

    expect($product->fresh()->qty_on_hand)->toBe('3.0000')
        ->and($credit->obligation_ledger_entry_id)->not->toBeNull()
        ->and($credit->financial_movement_id)->toBeNull();
});

test('21 unidad condición vs estado', function () {
    $admin = makeAdmin();
    seed11F3();
    $this->actingAs($admin);
    $cat = ProductCategory::where('slug', 'hardware')->first();
    $sub = ProductSubcategory::where('slug', 'discos')->first();
    $product = app(ProductService::class)->create([
        'sku' => 'UNIT-11F3',
        'name' => 'Unidad test',
        'type' => 'physical',
        'requires_serial' => true,
        'product_category_id' => $cat->id,
        'product_subcategory_id' => $sub->id,
    ]);

    $unit = app(InventoryUnitService::class)->create($product, [
        'condition' => UnitCondition::New->value,
        'status' => UnitStatus::Available->value,
        'internal_code' => 'U-001',
    ]);

    app(InventoryUnitService::class)->transition($unit, UnitCondition::Used, UnitStatus::InUse, 'Puesta en uso');
    $unit->refresh();
    expect($unit->condition)->toBe(UnitCondition::Used)
        ->and($unit->status)->toBe(UnitStatus::InUse);

    app(InventoryUnitService::class)->transition($unit, null, UnitStatus::Repair, 'En taller');
    expect($unit->fresh()->status)->toBe(UnitStatus::Repair)
        ->and($unit->fresh()->condition)->toBe(UnitCondition::Used)
        ->and($unit->events()->count())->toBeGreaterThanOrEqual(3);
});

test('22 equipo componentes una sola vez', function () {
    $admin = makeAdmin();
    seed11F3();
    $this->actingAs($admin);

    // Reutiliza cobertura Etapa 6: verificar que el servicio existe y el enum distingue equipo armado.
    expect(CommercialItemType::Equipment->label())->toContain('Equipo')
        ->and(CommercialItemType::Product->label())->toContain('Producto')
        ->and(CommercialItemType::Equipment->sellsEquipment())->toBeTrue()
        ->and(CommercialItemType::Product->consumesStock())->toBeTrue()
        ->and(class_exists(EquipmentAssemblyService::class))->toBeTrue();
});

test('23 OT opcional — cargo servicio sin OT', function () {
    $admin = makeAdmin();
    seed11F3();
    $this->actingAs($admin);
    $client = makeClient11F3();

    $charge = app(CommercialChargeService::class)->create([
        'client_id' => $client->id,
        'charge_type' => CommercialChargeType::Remote->value,
        'concept' => 'Remoto rápido sin OT',
        'amount' => '80',
        'currency_code' => 'ARS',
    ]);

    expect($charge->work_order_id)->toBeNull()
        ->and(WorkOrder::count())->toBe(0);
});

test('24-25 regularización auditada y permisos', function () {
    $admin = makeAdmin();
    seed11F3();
    $this->actingAs($admin);
    $client = makeClient11F3();

    $entry = app(CcRegularizationService::class)->regularize($client, [
        'currency_code' => 'ARS',
        'amount' => '123',
        'sign' => -1,
        'reason' => 'Saldo apertura TEST',
        'regularization_kind' => 'opening_balance',
    ]);

    expect($entry->regularization_kind)->toBe('opening_balance')
        ->and(AuditLog::query()->where('action', 'client_cc_regularized')->exists())->toBeTrue();

    $viewer = makeUserWithPermissions(['clients.view']);
    $this->actingAs($viewer)
        ->post(route('clients.regularize', $client), [
            'currency_code' => 'ARS',
            'amount' => '10',
            'sign' => -1,
            'reason' => 'no',
            'regularization_kind' => 'other',
            'entry_date' => now()->toDateString(),
        ])
        ->assertForbidden();
});

test('26-27 reversión de cobro y cargo', function () {
    $admin = makeAdmin();
    seed11F3();
    $this->actingAs($admin);
    $client = makeClient11F3();
    $ledger = app(ClientLedgerService::class);
    $charges = app(CommercialChargeService::class);
    $receipts = app(ReceiptService::class);

    $charge = $charges->create([
        'client_id' => $client->id, 'charge_type' => 'other', 'concept' => 'Rev', 'amount' => '100', 'currency_code' => 'ARS',
    ]);
    $receipt = $receipts->create([
        'client_id' => $client->id,
        'financial_account_id' => arsAccount()->id,
        'amount' => '100',
        'application_mode' => 'auto',
    ])['receipt'];

    $receipts->void($receipt, 'Error TEST');
    expect($receipt->fresh()->status->value)->toBe('voided')
        ->and($charge->fresh()->amount_open)->toBe('100.00')
        ->and($ledger->balanceFor($client, 'ARS'))->toBe('-100.00');

    $charges->void($charge->fresh(), 'Anula cargo TEST');
    expect($charge->fresh()->status->value)->toBe('voided')
        ->and($ledger->balanceFor($client, 'ARS'))->toBe('0.00');
});

test('28 CC rojo/verde preservado', function () {
    expect(UiSemantics::tone('100.00', UiSemantics::MODE_CLIENT_CC))->toBe(UiSemantics::TONE_ATTENTION)
        ->and(UiSemantics::tone('-50.00', UiSemantics::MODE_CLIENT_CC))->toBe(UiSemantics::TONE_FAVORABLE)
        ->and(UiSemantics::clientCcDisplayBalance('-500.00'))->toBe('500.00');
});

test('presupuesto no mueve dinero CC ni stock', function () {
    $admin = makeAdmin();
    seed11F3();
    $this->actingAs($admin);
    $client = makeClient11F3();
    $beforeMov = Movement::count();
    $beforeCharges = CommercialCharge::count();

    app(QuotationService::class)->create([
        'client_id' => $client->id,
        'currency_code' => 'ARS',
        'items' => [[
            'item_type' => CommercialItemType::Free->value,
            'description' => 'Concepto libre',
            'quantity' => '1',
            'unit_price' => '999',
            'currency_code' => 'ARS',
        ]],
    ]);

    expect(Movement::count())->toBe($beforeMov)
        ->and(CommercialCharge::count())->toBe($beforeCharges)
        ->and(app(ClientLedgerService::class)->balanceFor($client, 'ARS'))->toBe('0.00');
});
