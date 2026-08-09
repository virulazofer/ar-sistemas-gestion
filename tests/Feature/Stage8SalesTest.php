<?php

use App\Enums\EquipmentStatus;
use App\Enums\QuotationStatus;
use App\Enums\SaleStatus;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientLedgerEntry;
use App\Models\Equipment;
use App\Models\EquipmentComponentCategory;
use App\Models\EquipmentType;
use App\Models\FinancialAccount;
use App\Models\InventoryLot;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\Supplier;
use App\Services\Catalog\ProductService;
use App\Services\Clients\ClientLedgerService;
use App\Services\Equipment\EquipmentAssemblyService;
use App\Services\Purchases\PurchaseService;
use App\Services\Quotations\QuotationService;
use App\Services\Sales\SaleService;
use Database\Seeders\CommercialCatalogSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\EquipmentCatalogSeeder;
use Database\Seeders\ExchangeRateSeeder;
use Database\Seeders\FinancialAccountSeeder;
use Database\Seeders\InventoryCatalogSeeder;

function seedCommercialStage(): void
{
    test()->seed(CurrencySeeder::class);
    test()->seed(FinancialAccountSeeder::class);
    test()->seed(ExchangeRateSeeder::class);
    test()->seed(InventoryCatalogSeeder::class);
    test()->seed(EquipmentCatalogSeeder::class);
    test()->seed(CommercialCatalogSeeder::class);
}

function makeCommercialClient(string $name = 'Cliente de prueba'): Client
{
    return Client::query()->create([
        'name' => $name,
        'status' => 'active',
        'tax_condition' => 'Consumidor Final',
    ]);
}

function stockSsd(string $sku, string $qty, string $unitCost = '60'): Product
{
    $supplier = Supplier::query()->create(['name' => 'Prov '.$sku, 'status' => 'active']);
    $ssd = app(ProductService::class)->create([
        'sku' => $sku,
        'name' => 'SSD '.$sku,
        'type' => 'physical',
    ]);
    app(PurchaseService::class)->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [['product_id' => $ssd->id, 'description' => 'SSD', 'quantity' => $qty, 'unit_price' => $unitCost]],
    ]);

    return $ssd->fresh();
}

test('presupuesto no altera stock ni CC ni finanzas', function () {
    $admin = makeAdmin();
    seedCommercialStage();
    $this->actingAs($admin);

    $client = makeCommercialClient();
    $ssd = stockSsd('SSD-Q', '10', '60');
    $stockBefore = $ssd->qty_on_hand;
    $movBefore = Movement::count();
    $ledgerBefore = ClientLedgerEntry::count();

    $q = app(QuotationService::class)->create([
        'client_id' => $client->id,
        'currency_code' => 'USD',
        'items' => [[
            'item_type' => 'product',
            'description' => 'SSD',
            'product_id' => $ssd->id,
            'quantity' => '2',
            'unit_price' => '90',
        ]],
    ]);

    expect($q->number)->toBe('P-000001');
    expect($q->total)->toBe('180.00');
    expect($ssd->fresh()->qty_on_hand)->toBe($stockBefore);
    expect(Movement::count())->toBe($movBefore);
    expect(ClientLedgerEntry::count())->toBe($ledgerBefore);
    expect(app(ClientLedgerService::class)->balanceFor($client, 'USD'))->toBe('0.00');
});

test('venta a crédito — stock FIFO, CC y sin finanzas', function () {
    $admin = makeAdmin();
    seedCommercialStage();
    $this->actingAs($admin);

    $client = makeCommercialClient('Crédito');
    $ssd = stockSsd('SSD-CR', '10', '60');
    $movBefore = Movement::count();

    $sale = app(SaleService::class)->create([
        'client_id' => $client->id,
        'currency_code' => 'USD',
        'items' => [[
            'item_type' => 'product',
            'description' => 'SSD',
            'product_id' => $ssd->id,
            'quantity' => '2',
            'unit_price' => '90',
        ]],
    ]);

    expect($ssd->fresh()->qty_on_hand)->toBe('10.0000'); // borrador sin consumo

    $confirmed = app(SaleService::class)->confirm($sale, ['payment_mode' => 'credit']);

    expect($confirmed->status)->toBe(SaleStatus::Confirmed);
    expect($confirmed->number)->toStartWith('V-');
    expect($ssd->fresh()->qty_on_hand)->toBe('8.0000');
    expect($confirmed->total)->toBe('180.00');
    expect($confirmed->total_cost_usd)->toBe('120.00');
    expect($confirmed->gross_margin)->toBe('60.00');
    expect(app(ClientLedgerService::class)->balanceFor($client, 'USD'))->toBe('-180.00');
    expect(Movement::count())->toBe($movBefore); // sin movimiento bancario
    expect(AuditLog::where('action', 'sale_confirmed')->exists())->toBeTrue();
});

test('venta contado — banco + CC sin deuda', function () {
    $admin = makeAdmin();
    seedCommercialStage();
    $this->actingAs($admin);

    $client = makeCommercialClient('Contado');
    $ssd = stockSsd('SSD-CA', '5', '60');
    $account = FinancialAccount::query()->where('name', 'Banco USD')->firstOrFail();
    $balanceBefore = (string) $account->fresh()->cached_balance;

    $sale = app(SaleService::class)->create([
        'client_id' => $client->id,
        'currency_code' => 'USD',
        'items' => [[
            'item_type' => 'product',
            'description' => 'SSD',
            'product_id' => $ssd->id,
            'quantity' => '2',
            'unit_price' => '90',
        ]],
    ]);

    $confirmed = app(SaleService::class)->confirm($sale, [
        'payment_mode' => 'cash',
        'financial_account_id' => $account->id,
    ]);

    expect($ssd->fresh()->qty_on_hand)->toBe('3.0000');
    expect($confirmed->total_cost_usd)->toBe('120.00');
    expect($confirmed->gross_margin)->toBe('60.00');
    expect(app(ClientLedgerService::class)->balanceFor($client, 'USD'))->toBe('0.00');
    expect((string) $account->fresh()->cached_balance)->not->toBe($balanceBefore);
    expect($confirmed->financial_movement_id)->not->toBeNull();
    expect($confirmed->payment_ledger_entry_id)->not->toBeNull();
});

test('FIFO venta 12 unidades — 10×60 + 2×70 = 740', function () {
    $admin = makeAdmin();
    seedCommercialStage();
    $this->actingAs($admin);

    $client = makeCommercialClient('FIFO');
    $supplier = Supplier::query()->create(['name' => 'Prov FIFO', 'status' => 'active']);
    $ssd = app(ProductService::class)->create(['sku' => 'SSD-FIFO-S', 'name' => 'SSD', 'type' => 'physical']);
    $purchases = app(PurchaseService::class);
    $purchases->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [['product_id' => $ssd->id, 'description' => 'A', 'quantity' => '10', 'unit_price' => '60']],
    ]);
    InventoryLot::where('product_id', $ssd->id)->first()->update(['received_at' => now()->subDay()]);
    $purchases->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [['product_id' => $ssd->id, 'description' => 'B', 'quantity' => '5', 'unit_price' => '70']],
    ]);

    $sale = app(SaleService::class)->create([
        'client_id' => $client->id,
        'currency_code' => 'USD',
        'items' => [[
            'item_type' => 'product',
            'description' => 'SSD',
            'product_id' => $ssd->id,
            'quantity' => '12',
            'unit_price' => '100',
        ]],
    ]);
    $confirmed = app(SaleService::class)->confirm($sale, ['payment_mode' => 'credit']);

    expect($confirmed->total_cost_usd)->toBe('740.00');
    expect($confirmed->total)->toBe('1200.00');
    expect($confirmed->gross_margin)->toBe('460.00');
    expect($ssd->fresh()->qty_on_hand)->toBe('3.0000');
});

test('venta de equipo — estado sold, sin re-consumir componentes', function () {
    $admin = makeAdmin();
    seedCommercialStage();
    $this->actingAs($admin);

    $client = makeCommercialClient('Equipo');
    $ssd = stockSsd('SSD-EQ-S', '5', '60');
    $type = EquipmentType::where('slug', 'pc-oficina')->firstOrFail();
    $storage = EquipmentComponentCategory::where('slug', 'storage')->firstOrFail();

    $equipment = app(EquipmentAssemblyService::class)->assemble([
        'equipment_type_id' => $type->id,
        'name' => 'PC Venta',
        'components' => [[
            'product_id' => $ssd->id,
            'component_category_id' => $storage->id,
            'quantity' => '1',
        ]],
    ]);
    // dejar disponible
    app(EquipmentAssemblyService::class)->changeStatus($equipment, EquipmentStatus::Available, 'listo');
    $stockAfterAssemble = $ssd->fresh()->qty_on_hand;
    $costUsd = (string) $equipment->fresh()->total_cost_usd;

    $sale = app(SaleService::class)->create([
        'client_id' => $client->id,
        'currency_code' => 'USD',
        'items' => [[
            'item_type' => 'equipment',
            'description' => $equipment->code,
            'equipment_id' => $equipment->id,
            'quantity' => '1',
            'unit_price' => '1500',
        ]],
    ]);
    $confirmed = app(SaleService::class)->confirm($sale, ['payment_mode' => 'credit']);

    expect($equipment->fresh()->status)->toBe(EquipmentStatus::Sold);
    expect($ssd->fresh()->qty_on_hand)->toBe($stockAfterAssemble); // no re-consume
    expect($confirmed->total_cost_usd)->toBe($costUsd);
    expect($equipment->fresh()->components)->not->toBeEmpty();
});

test('conversión presupuesto → venta borrador sin efectos; luego crédito', function () {
    $admin = makeAdmin();
    seedCommercialStage();
    $this->actingAs($admin);

    $client = makeCommercialClient('Conv');
    $ssd = stockSsd('SSD-CONV', '4', '60');
    $qSvc = app(QuotationService::class);
    $q = $qSvc->create([
        'client_id' => $client->id,
        'currency_code' => 'USD',
        'items' => [[
            'item_type' => 'product',
            'description' => 'SSD',
            'product_id' => $ssd->id,
            'quantity' => '2',
            'unit_price' => '90',
        ]],
    ]);
    $qSvc->changeStatus($q, QuotationStatus::Accepted);
    $sale = $qSvc->convert($q->fresh());

    expect($sale->status)->toBe(SaleStatus::Draft);
    expect($q->fresh()->status)->toBe(QuotationStatus::Converted);
    expect($ssd->fresh()->qty_on_hand)->toBe('4.0000');

    app(SaleService::class)->confirm($sale, ['payment_mode' => 'credit']);
    expect($ssd->fresh()->qty_on_hand)->toBe('2.0000');
});

test('rollback confirmación venta — sin efectos parciales', function () {
    $admin = makeAdmin();
    seedCommercialStage();
    $this->actingAs($admin);

    $client = makeCommercialClient('RB');
    $ssd = stockSsd('SSD-RB-S', '3', '60');
    $stock = $ssd->fresh()->qty_on_hand;
    $ledger = ClientLedgerEntry::count();

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
    expect(ClientLedgerEntry::count())->toBe($ledger);
    expect($sale->fresh()->status)->toBe(SaleStatus::Draft);
});

test('anulación venta crédito revierte stock y CC', function () {
    $admin = makeAdmin();
    seedCommercialStage();
    $this->actingAs($admin);

    $client = makeCommercialClient('Void');
    $ssd = stockSsd('SSD-VOID', '3', '60');
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
    app(SaleService::class)->confirm($sale, ['payment_mode' => 'credit']);
    expect($ssd->fresh()->qty_on_hand)->toBe('2.0000');

    app(SaleService::class)->void($sale->fresh(), 'Error de carga');
    expect($sale->fresh()->status)->toBe(SaleStatus::Voided);
    expect($ssd->fresh()->qty_on_hand)->toBe('3.0000');
    expect(app(ClientLedgerService::class)->balanceFor($client, 'USD'))->toBe('0.00');
});

test('presupuesto vencido no convierte; permisos sales', function () {
    $admin = makeAdmin();
    seedCommercialStage();
    $this->actingAs($admin);

    $client = makeCommercialClient('Venc');
    $qSvc = app(QuotationService::class);
    $q = $qSvc->create([
        'client_id' => $client->id,
        'currency_code' => 'USD',
        'valid_until' => now()->addDays(10)->toDateString(),
        'items' => [[
            'item_type' => 'service',
            'description' => 'Instalación',
            'quantity' => '1',
            'unit_price' => '100',
        ]],
    ]);
    $qSvc->changeStatus($q->fresh(), QuotationStatus::Accepted);
    $q->update([
        'valid_until' => now()->subDay()->toDateString(),
        'status' => QuotationStatus::Expired,
    ]);
    expect(fn () => $qSvc->convert($q->fresh()))->toThrow(InvalidArgumentException::class);

    $viewer = makeUserWithPermissions(['sales.view', 'quotations.view']);
    $this->actingAs($viewer)
        ->post(route('sales.store'), [
            'client_id' => $client->id,
            'currency_code' => 'USD',
            'items' => [['item_type' => 'service', 'description' => 'X', 'quantity' => 1, 'unit_price' => 10]],
        ])
        ->assertForbidden();
});
