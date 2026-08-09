<?php

/**
 * Etapa 10 — validación integral MySQL 8.x (grupo `mysql`).
 * No usa RefreshDatabase ni SQLite; proceso aislado vía --group=mysql.
 */

use App\Enums\EquipmentStatus;
use App\Enums\InventorySerialStatus;
use App\Enums\QuotationStatus;
use App\Enums\SaleStatus;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientLedgerEntry;
use App\Models\EquipmentComponentCategory;
use App\Models\EquipmentType;
use App\Models\FinancialAccount;
use App\Models\InventoryLot;
use App\Models\InventorySerial;
use App\Models\Movement;
use App\Models\Product;
use App\Models\SubscriptionPeriod;
use App\Models\Supplier;
use App\Models\WorkOrderType;
use App\Services\Catalog\ProductService;
use App\Services\Clients\ClientLedgerService;
use App\Services\Dashboard\DashboardService;
use App\Services\Equipment\EquipmentAssemblyService;
use App\Services\Finance\BalanceService;
use App\Services\Inventory\InventoryService;
use App\Services\Purchases\PurchaseService;
use App\Services\Quotations\QuotationService;
use App\Services\Reports\ReportService;
use App\Services\Sales\SaleService;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\WorkOrders\WorkOrderService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('mysql transversal: flujo integral módulos críticos', function () {
    bootMysqlIntegration();

    // DECIMAL / tipos MySQL
    expect(Schema::getColumnType('movements', 'amount'))->toBe('decimal');
    expect(Schema::getColumnType('inventory_lots', 'unit_cost'))->toBe('decimal');
    expect(Schema::getColumnType('inventory_lots', 'unit_cost_usd'))->toBe('decimal');
    expect(Schema::getColumnType('exchange_rates', 'rate'))->toBe('decimal');

    // FK enforce (producto inexistente)
    expect(fn () => DB::table('inventory_lots')->insert([
        'product_id' => 999999,
        'inventory_location_id' => 1,
        'qty_received' => 1,
        'qty_remaining' => 1,
        'unit_cost' => 1,
        'unit_cost_ars' => 1,
        'unit_cost_usd' => 1,
        'currency_id' => 1,
        'status' => 'open',
        'received_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // 1. Cliente
    $client = Client::query()->create([
        'name' => 'Cliente MySQL E10',
        'status' => 'active',
        'tax_condition' => 'Consumidor Final',
    ]);
    expect($client->id)->toBeGreaterThan(0);

    // 2. Proveedor
    $supplier = Supplier::query()->create(['name' => 'Proveedor MySQL E10', 'status' => 'active']);

    $products = app(ProductService::class);
    $purchases = app(PurchaseService::class);
    $inventory = app(InventoryService::class);

    // 3. Productos físicos
    $ssdFifo = $products->create(['sku' => 'SSD-FIFO-E10', 'name' => 'SSD FIFO', 'type' => 'physical']);
    $ssdEq = $products->create(['sku' => 'SSD-EQ-E10', 'name' => 'SSD Equipo', 'type' => 'physical']);
    $ssdOt = $products->create(['sku' => 'SSD-OT-E10', 'name' => 'SSD OT', 'type' => 'physical']);
    $ssdSale = $products->create(['sku' => 'SSD-SALE-E10', 'name' => 'SSD Venta', 'type' => 'physical']);
    $gpu = $products->create([
        'sku' => 'GPU-E10',
        'name' => 'GPU Serial',
        'type' => 'physical',
        'requires_serial' => true,
    ]);

    // 4-5. Compra USD + lote A (10 × 60)
    $purchases->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [[
            'product_id' => $ssdFifo->id,
            'description' => 'Lote A',
            'quantity' => '10',
            'unit_price' => '60',
        ]],
    ]);
    $lotA = InventoryLot::where('product_id', $ssdFifo->id)->firstOrFail();
    $lotA->update(['received_at' => now()->subDay()]);
    expect($lotA->qty_remaining)->toBe('10.0000');
    expect($lotA->unit_cost_usd)->toBe('60.000000');

    // 6. Stock
    expect($ssdFifo->fresh()->qty_on_hand)->toBe('10.0000');

    // 7. Lote B (5 × 70)
    $purchases->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [[
            'product_id' => $ssdFifo->id,
            'description' => 'Lote B',
            'quantity' => '5',
            'unit_price' => '70',
        ]],
    ]);
    expect($ssdFifo->fresh()->qty_on_hand)->toBe('15.0000');

    // 8. Consumo FIFO 12 → 10×60 + 2×70 = 740
    $consume = $inventory->consume($ssdFifo, ['quantity' => '12', 'reason' => 'FIFO E10']);
    expect($consume->total_cost_usd)->toBe('740.00');
    expect($consume->allocations)->toHaveCount(2);
    expect($consume->allocations[0]->quantity)->toBe('10.0000');
    expect($consume->allocations[0]->unit_cost)->toBe('60.000000');
    expect($consume->allocations[1]->quantity)->toBe('2.0000');
    expect($consume->allocations[1]->unit_cost)->toBe('70.000000');
    $lotsFifo = InventoryLot::where('product_id', $ssdFifo->id)->orderBy('id')->get();
    expect($lotsFifo[0]->qty_remaining)->toBe('0.0000');
    expect($lotsFifo[1]->qty_remaining)->toBe('3.0000');
    expect($ssdFifo->fresh()->qty_on_hand)->toBe('3.0000');

    // Stock para equipo / OT / venta
    foreach ([$ssdEq, $ssdOt, $ssdSale] as $p) {
        $purchases->create([
            'supplier_id' => $supplier->id,
            'currency_code' => 'USD',
            'payment_mode' => 'credit',
            'items' => [[
                'product_id' => $p->id,
                'description' => $p->sku,
                'quantity' => '5',
                'unit_price' => '60',
            ]],
        ]);
    }

    // 9-10. Equipo + serialización
    $inventory->receive($gpu, [
        'quantity' => '2',
        'unit_cost' => '300',
        'currency_code' => 'USD',
        'exchange_rate_value' => '1450',
        'reason' => 'Ingreso GPU E10',
        'serials' => ['E10-SN001', 'E10-SN002'],
    ]);
    expect(InventorySerial::where('product_id', $gpu->id)->count())->toBe(2);

    $type = EquipmentType::where('slug', 'pc-gamer')->firstOrFail();
    $storage = EquipmentComponentCategory::where('slug', 'storage')->firstOrFail();
    $gpuCat = EquipmentComponentCategory::where('slug', 'gpu')->firstOrFail();

    $equipment = app(EquipmentAssemblyService::class)->assemble([
        'equipment_type_id' => $type->id,
        'name' => 'PC MySQL E10',
        'components' => [
            [
                'product_id' => $ssdEq->id,
                'component_category_id' => $storage->id,
                'quantity' => 1,
            ],
            [
                'product_id' => $gpu->id,
                'component_category_id' => $gpuCat->id,
                'serial_number' => 'E10-SN002',
            ],
        ],
    ]);
    expect($equipment->total_cost_usd)->toBe('360.00'); // 60 + 300
    expect($ssdEq->fresh()->qty_on_hand)->toBe('4.0000');
    expect(InventorySerial::where('serial_number', 'E10-SN002')->first()->status)
        ->toBe(InventorySerialStatus::Consumed);
    expect($equipment->components)->toHaveCount(2);

    app(EquipmentAssemblyService::class)->changeStatus($equipment, EquipmentStatus::Available, 'listo E10');

    // 11-13. OT + consumo FIFO + cargo CC
    $woType = WorkOrderType::where('slug', 'reparacion')->firstOrFail();
    $woSvc = app(WorkOrderService::class);
    $wo = $woSvc->create([
        'client_id' => $client->id,
        'work_order_type_id' => $woType->id,
        'title' => 'Reparación MySQL E10',
        'currency_code' => 'USD',
    ]);
    $woSvc->addTask($wo, [
        'description' => 'Mano de obra',
        'price_amount' => '100',
        'cost_amount' => '0',
        'currency_code' => 'USD',
    ]);
    $woSvc->addMaterial($wo, [
        'product_id' => $ssdOt->id,
        'quantity' => '1',
        'price_unit' => '90',
        'currency_code' => 'USD',
    ]);

    $movBeforeWo = Movement::count();
    $closed = $woSvc->close($wo->fresh());
    expect($closed->status->value)->toBe('closed');
    expect($ssdOt->fresh()->qty_on_hand)->toBe('4.0000');
    expect($closed->total_cost_usd)->toBe('60.00');
    expect($closed->total_price_usd)->toBe('190.00');
    expect($closed->client_ledger_entry_id)->not->toBeNull();
    expect(app(ClientLedgerService::class)->balanceFor($client, 'USD'))->toBe('-190.00');
    expect(Movement::count())->toBe($movBeforeWo);

    // 14. Abono + período idempotente
    $subSvc = app(SubscriptionService::class);
    $sub = $subSvc->create([
        'client_id' => $client->id,
        'name' => 'Mantenimiento E10',
        'periodicity' => 'monthly',
        'amount' => '150',
        'currency_code' => 'USD',
        'starts_on' => '2026-09-01',
        'next_generation_on' => '2026-09-01',
    ]);
    $sep1 = $subSvc->generatePeriod($sub, Carbon::parse('2026-09-15'), '2026-09');
    $sep2 = $subSvc->generatePeriod($sub->fresh(), Carbon::parse('2026-09-20'), '2026-09');
    expect($sep2->id)->toBe($sep1->id);
    expect(SubscriptionPeriod::where('subscription_id', $sub->id)->where('period_key', '2026-09')->count())->toBe(1);
    expect(app(ClientLedgerService::class)->balanceFor($client, 'USD'))->toBe('-340.00'); // -190 -150

    // 15-16. Presupuesto sin efectos
    $stockSaleBefore = $ssdSale->fresh()->qty_on_hand;
    $ledgerBeforeQuote = ClientLedgerEntry::count();
    $movBeforeQuote = Movement::count();
    $ccBeforeQuote = app(ClientLedgerService::class)->balanceFor($client, 'USD');

    $qSvc = app(QuotationService::class);
    $quote = $qSvc->create([
        'client_id' => $client->id,
        'currency_code' => 'USD',
        'items' => [[
            'item_type' => 'product',
            'description' => 'SSD',
            'product_id' => $ssdSale->id,
            'quantity' => '2',
            'unit_price' => '90',
        ]],
    ]);
    expect($ssdSale->fresh()->qty_on_hand)->toBe($stockSaleBefore);
    expect(ClientLedgerEntry::count())->toBe($ledgerBeforeQuote);
    expect(Movement::count())->toBe($movBeforeQuote);
    expect(app(ClientLedgerService::class)->balanceFor($client, 'USD'))->toBe($ccBeforeQuote);

    // 17-18. Convertir → borrador → confirmar crédito
    $qSvc->changeStatus($quote, QuotationStatus::Accepted);
    $sale = $qSvc->convert($quote->fresh());
    expect($sale->status)->toBe(SaleStatus::Draft);
    expect($ssdSale->fresh()->qty_on_hand)->toBe($stockSaleBefore);

    $confirmed = app(SaleService::class)->confirm($sale, ['payment_mode' => 'credit']);
    expect($confirmed->status)->toBe(SaleStatus::Confirmed);

    // 19. Stock / costo / margen
    expect($ssdSale->fresh()->qty_on_hand)->toBe('3.0000');
    expect($confirmed->total)->toBe('180.00');
    expect($confirmed->total_cost_usd)->toBe('120.00');
    expect($confirmed->gross_margin)->toBe('60.00');

    // 20. CC
    expect(app(ClientLedgerService::class)->balanceFor($client, 'USD'))->toBe('-520.00'); // -340 -180

    // 21-23. Pago + movimiento financiero + saldos
    $cajaUsd = FinancialAccount::where('name', 'Caja USD')->firstOrFail();
    $balanceBeforePay = app(BalanceService::class)->computeAccountBalance($cajaUsd->id);
    $pay = app(ClientLedgerService::class)->registerPayment($client, [
        'financial_account_id' => $cajaUsd->id,
        'amount' => '200.00',
    ]);
    expect($pay['movement'])->not->toBeNull();
    expect(app(ClientLedgerService::class)->balanceFor($client, 'USD'))->toBe('-320.00');
    expect(app(BalanceService::class)->computeAccountBalance($cajaUsd->id))
        ->not->toBe($balanceBeforePay);

    // 24. Dashboard / reportes básicos
    app(DashboardService::class)->clearCache();
    $dash = app(DashboardService::class)->snapshot('all');
    expect($dash['liquid'])->toHaveKeys(['ARS', 'USD']);
    expect((float) $dash['sales']['total_usd'])->toBeGreaterThanOrEqual(180);
    expect($dash['stock']['value_usd'])->not->toBe('0.00');

    $reports = app(ReportService::class);
    $stockRow = collect($reports->stockCurrent()['rows'])->firstWhere('sku', 'SSD-SALE-E10');
    expect($stockRow['qty_on_hand'])->toBe('3.0000');
    $salesRows = collect($reports->salesReport([])['rows']);
    expect($salesRows->contains(fn ($r) => $r['total'] === '180.00'))->toBeTrue();

    // 25. Auditoría
    expect(AuditLog::where('action', 'product_created')->exists())->toBeTrue();
    expect(AuditLog::where('action', 'equipment_assembled')->exists())->toBeTrue();
    expect(AuditLog::where('action', 'work_order_closed')->exists())->toBeTrue();
    expect(AuditLog::where('action', 'sale_confirmed')->exists())->toBeTrue();

    expect(DB::getDriverName())->toBe('mysql');
    expect(config('database.connections.mysql.database'))->toBe(env('MYSQL_TEST_DATABASE', 'ar_sistemas_test'));
});

test('mysql FIFO obligatorio: 10@60 + 5@70 consumir 12 = USD 740', function () {
    bootMysqlIntegration();

    $supplier = Supplier::query()->create(['name' => 'Prov FIFO E10', 'status' => 'active']);
    $ssd = app(ProductService::class)->create([
        'sku' => 'SSD-FIFO-ONLY',
        'name' => 'SSD FIFO Only',
        'type' => 'physical',
    ]);
    $purchases = app(PurchaseService::class);

    $purchases->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [[
            'product_id' => $ssd->id,
            'description' => 'A',
            'quantity' => '10',
            'unit_price' => '60',
        ]],
    ]);
    InventoryLot::where('product_id', $ssd->id)->firstOrFail()->update(['received_at' => now()->subDay()]);

    $purchases->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [[
            'product_id' => $ssd->id,
            'description' => 'B',
            'quantity' => '5',
            'unit_price' => '70',
        ]],
    ]);

    $movement = app(InventoryService::class)->consume($ssd, [
        'quantity' => '12',
        'reason' => 'FIFO obligatorio MySQL',
    ]);

    expect($movement->total_cost_usd)->toBe('740.00');
    expect($movement->allocations)->toHaveCount(2);
    expect($movement->allocations[0]->quantity)->toBe('10.0000');
    expect($movement->allocations[0]->unit_cost)->toBe('60.000000');
    expect($movement->allocations[1]->quantity)->toBe('2.0000');
    expect($movement->allocations[1]->unit_cost)->toBe('70.000000');

    $lots = InventoryLot::where('product_id', $ssd->id)->orderBy('id')->get();
    expect($lots[0]->qty_remaining)->toBe('0.0000');
    expect($lots[1]->qty_remaining)->toBe('3.0000');
    expect($ssd->fresh()->qty_on_hand)->toBe('3.0000');
});

test('mysql rollback: confirmación venta multi-módulo sin efectos parciales', function () {
    bootMysqlIntegration();

    $client = Client::query()->create([
        'name' => 'Cliente Rollback E10',
        'status' => 'active',
        'tax_condition' => 'Consumidor Final',
    ]);
    $supplier = Supplier::query()->create(['name' => 'Prov RB E10', 'status' => 'active']);
    $ssd = app(ProductService::class)->create(['sku' => 'SSD-RB-E10', 'name' => 'SSD RB', 'type' => 'physical']);

    app(PurchaseService::class)->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [[
            'product_id' => $ssd->id,
            'description' => 'SSD',
            'quantity' => '3',
            'unit_price' => '60',
        ]],
    ]);

    $stock = $ssd->fresh()->qty_on_hand;
    $ledgerCount = ClientLedgerEntry::count();
    $movCount = Movement::count();
    $cc = app(ClientLedgerService::class)->balanceFor($client, 'USD');

    $sale = app(SaleService::class)->create([
        'client_id' => $client->id,
        'currency_code' => 'USD',
        'items' => [[
            'item_type' => 'product',
            'description' => 'SSD',
            'product_id' => $ssd->id,
            'quantity' => '1',
            'unit_price' => '90',
        ]],
    ]);

    expect(fn () => app(SaleService::class)->confirm($sale, [
        'payment_mode' => 'credit',
        'force_fail' => true,
    ]))->toThrow(RuntimeException::class);

    expect($ssd->fresh()->qty_on_hand)->toBe($stock);
    expect(ClientLedgerEntry::count())->toBe($ledgerCount);
    expect(Movement::count())->toBe($movCount);
    expect(app(ClientLedgerService::class)->balanceFor($client, 'USD'))->toBe($cc);
    expect($sale->fresh()->status)->toBe(SaleStatus::Draft);
});

test('mysql concurrencia: lockForUpdate real entre dos conexiones InnoDB', function () {
    bootMysqlIntegration();

    $supplier = Supplier::query()->create(['name' => 'Prov LOCK E10', 'status' => 'active']);
    $ssd = app(ProductService::class)->create(['sku' => 'SSD-LOCK-E10', 'name' => 'SSD Lock', 'type' => 'physical']);
    app(PurchaseService::class)->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [[
            'product_id' => $ssd->id,
            'description' => 'Stock',
            'quantity' => '5',
            'unit_price' => '60',
        ]],
    ]);

    $lotId = InventoryLot::where('product_id', $ssd->id)->value('id');
    expect($lotId)->not->toBeNull();

    // Segunda conexión MySQL independiente (mismo servidor/DB).
    config([
        'database.connections.mysql_lock' => array_merge(
            config('database.connections.mysql'),
            ['name' => 'mysql_lock']
        ),
    ]);
    DB::purge('mysql_lock');
    DB::reconnect('mysql_lock');
    DB::connection('mysql_lock')->statement('SET SESSION innodb_lock_wait_timeout = 1');

    DB::connection('mysql')->beginTransaction();
    try {
        // Simula FifoService: bloquea el lote en la conexión principal.
        $locked = InventoryLot::on('mysql')
            ->where('id', $lotId)
            ->lockForUpdate()
            ->first();
        expect($locked)->not->toBeNull();

        $timedOut = false;
        try {
            DB::connection('mysql_lock')->transaction(function () use ($lotId) {
                InventoryLot::on('mysql_lock')
                    ->where('id', $lotId)
                    ->lockForUpdate()
                    ->first();
            });
        } catch (QueryException $e) {
            $timedOut = str_contains($e->getMessage(), 'Lock wait timeout')
                || (int) $e->getCode() === 1205
                || str_contains($e->getMessage(), '1205');
        }

        expect($timedOut)->toBeTrue();
    } finally {
        DB::connection('mysql')->rollBack();
    }

    // Tras liberar el lock, el consumo FIFO vía servicio debe funcionar.
    $movement = app(InventoryService::class)->consume($ssd->fresh(), [
        'quantity' => '2',
        'reason' => 'Post-lock',
    ]);
    expect($movement->total_cost_usd)->toBe('120.00');
    expect($ssd->fresh()->qty_on_hand)->toBe('3.0000');
});
