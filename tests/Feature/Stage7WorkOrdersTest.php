<?php

use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientLedgerEntry;
use App\Models\InventoryLot;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionPeriod;
use App\Models\WorkOrder;
use App\Models\WorkOrderType;
use App\Services\Catalog\ProductService;
use App\Services\Clients\ClientLedgerService;
use App\Services\Inventory\InventoryService;
use App\Services\Purchases\PurchaseService;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\WorkOrders\WorkOrderService;
use App\Models\Supplier;
use Carbon\Carbon;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\EquipmentCatalogSeeder;
use Database\Seeders\ExchangeRateSeeder;
use Database\Seeders\FinancialAccountSeeder;
use Database\Seeders\InventoryCatalogSeeder;
use Database\Seeders\WorkOrderCatalogSeeder;

function seedWorkOrderStage(): void
{
    test()->seed(CurrencySeeder::class);
    test()->seed(FinancialAccountSeeder::class);
    test()->seed(ExchangeRateSeeder::class);
    test()->seed(InventoryCatalogSeeder::class);
    test()->seed(EquipmentCatalogSeeder::class);
    test()->seed(WorkOrderCatalogSeeder::class);
}

function makeTestClient(string $name = 'Cliente de prueba'): Client
{
    return Client::query()->create([
        'name' => $name,
        'status' => 'active',
        'tax_condition' => 'Consumidor Final',
    ]);
}

test('escenario OT obligatorio — reparación SSD costo 60 precio 90 + MO 100', function () {
    $admin = makeAdmin();
    seedWorkOrderStage();
    $this->actingAs($admin);

    $client = makeTestClient();
    $supplier = Supplier::query()->create(['name' => 'Prov OT', 'status' => 'active']);
    $ssd = app(ProductService::class)->create([
        'sku' => 'SSD-OT',
        'name' => 'SSD 1 TB',
        'type' => 'physical',
    ]);

    app(PurchaseService::class)->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [['product_id' => $ssd->id, 'description' => 'SSD', 'quantity' => '5', 'unit_price' => '60']],
    ]);

    $type = WorkOrderType::where('slug', 'reparacion')->firstOrFail();
    $woSvc = app(WorkOrderService::class);
    $wo = $woSvc->create([
        'client_id' => $client->id,
        'work_order_type_id' => $type->id,
        'title' => 'Reparación PC',
        'currency_code' => 'USD',
    ]);

    $woSvc->addTask($wo, [
        'description' => 'Mano de obra',
        'price_amount' => '100',
        'cost_amount' => '0',
        'currency_code' => 'USD',
    ]);

    $woSvc->addMaterial($wo, [
        'product_id' => $ssd->id,
        'quantity' => '1',
        'price_unit' => '90',
        'currency_code' => 'USD',
    ]);

    $beforeMovements = Movement::count();
    $closed = $woSvc->close($wo->fresh());

    expect($closed->status->value)->toBe('closed');
    expect($ssd->fresh()->qty_on_hand)->toBe('4.0000');
    expect($closed->total_cost_usd)->toBe('60.00');
    expect($closed->total_price_usd)->toBe('190.00');
    expect($closed->client_ledger_entry_id)->not->toBeNull();

    $ledger = ClientLedgerEntry::findOrFail($closed->client_ledger_entry_id);
    expect($ledger->signed_amount)->toBe('-190.00');
    expect($ledger->work_order_id)->toBe($closed->id);
    expect(app(ClientLedgerService::class)->balanceFor($client, 'USD'))->toBe('-190.00');
    expect(Movement::count())->toBe($beforeMovements); // sin movimiento financiero
    expect(AuditLog::where('action', 'work_order_closed')->exists())->toBeTrue();
});

test('cierre OT con rollback no deja efectos parciales', function () {
    $admin = makeAdmin();
    seedWorkOrderStage();
    $this->actingAs($admin);

    $client = makeTestClient('Rollback OT');
    $ssd = app(ProductService::class)->create(['sku' => 'SSD-RB-OT', 'name' => 'SSD', 'type' => 'physical']);
    app(InventoryService::class)->receive($ssd, [
        'quantity' => '2',
        'unit_cost' => '60',
        'currency_code' => 'USD',
        'exchange_rate_value' => '1450',
        'reason' => 'base',
    ]);

    $type = WorkOrderType::where('slug', 'reparacion')->firstOrFail();
    $woSvc = app(WorkOrderService::class);
    $wo = $woSvc->create([
        'client_id' => $client->id,
        'work_order_type_id' => $type->id,
        'title' => 'Fail close',
        'currency_code' => 'USD',
    ]);
    $woSvc->addTask($wo, ['description' => 'MO', 'price_amount' => '50', 'currency_code' => 'USD']);
    $woSvc->addMaterial($wo, [
        'product_id' => $ssd->id,
        'quantity' => '1',
        'price_unit' => '90',
        'currency_code' => 'USD',
    ]);

    $beforeStock = $ssd->fresh()->qty_on_hand;
    $beforeLedger = ClientLedgerEntry::count();

    expect(fn () => $woSvc->close($wo->fresh(), ['force_fail' => true]))
        ->toThrow(RuntimeException::class);

    expect($ssd->fresh()->qty_on_hand)->toBe($beforeStock);
    expect(ClientLedgerEntry::count())->toBe($beforeLedger);
    expect($wo->fresh()->status->value)->toBe('open');
});

test('OT cerrada no admite modificaciones libres', function () {
    $admin = makeAdmin();
    seedWorkOrderStage();
    $this->actingAs($admin);

    $client = makeTestClient('Cerrada');
    $type = WorkOrderType::where('slug', 'soporte-remoto')->firstOrFail();
    $woSvc = app(WorkOrderService::class);
    $wo = $woSvc->create([
        'client_id' => $client->id,
        'work_order_type_id' => $type->id,
        'title' => 'Remoto',
        'currency_code' => 'USD',
    ]);
    $woSvc->addTask($wo, ['description' => 'Soporte 1h', 'price_amount' => '80', 'currency_code' => 'USD']);
    $woSvc->close($wo->fresh());

    expect(fn () => $woSvc->addTask($wo->fresh(), [
        'description' => 'Extra',
        'price_amount' => '10',
        'currency_code' => 'USD',
    ]))->toThrow(InvalidArgumentException::class);
});

test('escenario abono idempotente septiembre/octubre', function () {
    $admin = makeAdmin();
    seedWorkOrderStage();
    $this->actingAs($admin);

    $client = makeTestClient('Abono Client');
    $subSvc = app(SubscriptionService::class);
    $sub = $subSvc->create([
        'client_id' => $client->id,
        'name' => 'Mantenimiento mensual',
        'periodicity' => 'monthly',
        'amount' => '150',
        'currency_code' => 'USD',
        'starts_on' => '2026-09-01',
        'next_generation_on' => '2026-09-01',
    ]);

    $sep1 = $subSvc->generatePeriod($sub, Carbon::parse('2026-09-15'), '2026-09');
    expect($sep1->period_key)->toBe('2026-09');
    expect($sep1->amount)->toBe('150.00');
    expect(app(ClientLedgerService::class)->balanceFor($client, 'USD'))->toBe('-150.00');

    $sep2 = $subSvc->generatePeriod($sub->fresh(), Carbon::parse('2026-09-20'), '2026-09');
    expect($sep2->id)->toBe($sep1->id);
    expect(SubscriptionPeriod::where('subscription_id', $sub->id)->where('period_key', '2026-09')->count())->toBe(1);
    expect(ClientLedgerEntry::where('subscription_id', $sub->id)->count())->toBe(1);

    $oct = $subSvc->generatePeriod($sub->fresh(), Carbon::parse('2026-10-05'), '2026-10');
    expect($oct->period_key)->toBe('2026-10');
    expect(app(ClientLedgerService::class)->balanceFor($client, 'USD'))->toBe('-300.00');
    expect($sep1->fresh()->amount)->toBe('150.00');

    // Cambio de precio no altera histórico
    $subSvc->update($sub->fresh(), ['amount' => '200']);
    expect($sep1->fresh()->amount)->toBe('150.00');
});

test('abono pausado no genera cargos', function () {
    $admin = makeAdmin();
    seedWorkOrderStage();
    $this->actingAs($admin);

    $client = makeTestClient('Pausa');
    $subSvc = app(SubscriptionService::class);
    $sub = $subSvc->create([
        'client_id' => $client->id,
        'name' => 'Pausable',
        'periodicity' => 'monthly',
        'amount' => '100',
        'currency_code' => 'USD',
        'starts_on' => '2026-09-01',
    ]);
    $subSvc->changeStatus($sub, SubscriptionStatus::Paused);
    expect($subSvc->generatePeriod($sub->fresh(), now(), '2026-09'))->toBeNull();
    expect(ClientLedgerEntry::where('subscription_id', $sub->id)->count())->toBe(0);
});

test('comando subscriptions:generate y permisos', function () {
    $admin = makeAdmin();
    seedWorkOrderStage();
    $this->actingAs($admin);

    $client = makeTestClient('Cron');
    app(SubscriptionService::class)->create([
        'client_id' => $client->id,
        'name' => 'Cron abono',
        'periodicity' => 'monthly',
        'amount' => '50',
        'currency_code' => 'USD',
        'starts_on' => '2026-08-01',
        'next_generation_on' => '2026-08-01',
    ]);

    $this->artisan('subscriptions:generate', ['--date' => '2026-08-15'])->assertSuccessful();
    expect(SubscriptionPeriod::count())->toBeGreaterThan(0);

    $viewer = makeUserWithPermissions(['work_orders.view', 'subscriptions.view']);
    $this->actingAs($viewer)
        ->post(route('work-orders.store'), [
            'client_id' => $client->id,
            'work_order_type_id' => WorkOrderType::first()->id,
            'title' => 'X',
        ])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->post(route('subscriptions.generate-due'))
        ->assertForbidden();
});
