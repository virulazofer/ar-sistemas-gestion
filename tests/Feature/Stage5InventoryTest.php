<?php

use App\Models\AuditLog;
use App\Models\ExchangeRate;
use App\Models\FinancialAccount;
use App\Models\InventoryLot;
use App\Models\InventoryLotAllocation;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSubcategory;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\Catalog\ProductService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\StockBalanceService;
use App\Services\Purchases\PurchaseService;
use App\Support\Money;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ExchangeRateSeeder;
use Database\Seeders\FinancialAccountSeeder;
use Database\Seeders\InventoryCatalogSeeder;
use Illuminate\Support\Facades\DB;

function seedStockStage(): void
{
    test()->seed(CurrencySeeder::class);
    test()->seed(FinancialAccountSeeder::class);
    test()->seed(ExchangeRateSeeder::class);
    test()->seed(InventoryCatalogSeeder::class);
}

function makeSupplierStock(array $attrs = []): Supplier
{
    return Supplier::query()->create(array_merge([
        'name' => 'Proveedor Stock',
        'status' => 'active',
    ], $attrs));
}

function makeSsdProduct(array $attrs = []): Product
{
    $cat = ProductCategory::query()->where('slug', 'hardware')->first();
    $sub = ProductSubcategory::query()->where('slug', 'discos')->first();

    return app(ProductService::class)->create(array_merge([
        'sku' => 'SSD-1TB',
        'name' => 'SSD 1 TB',
        'type' => 'physical',
        'status' => 'active',
        'unit' => 'u',
        'product_category_id' => $cat?->id,
        'product_subcategory_id' => $sub?->id,
        'brand' => 'TestBrand',
        'model' => '1TB',
        'stock_min' => 1,
    ], $attrs));
}

test('1-4 productos: crear, editar, categoría y sin stock', function () {
    $admin = makeAdmin();
    seedStockStage();
    $this->actingAs($admin);

    $service = app(ProductService::class);
    $cat = ProductCategory::where('slug', 'hardware')->firstOrFail();
    $sub = ProductSubcategory::where('slug', 'discos')->firstOrFail();

    $product = $service->create([
        'sku' => 'PROD-001',
        'name' => 'Producto cero',
        'type' => 'physical',
        'product_category_id' => $cat->id,
        'product_subcategory_id' => $sub->id,
    ]);

    expect($product->qty_on_hand)->toBe('0.0000');
    expect($product->category->name)->toBe('Hardware');
    expect($product->subcategory->name)->toBe('Discos');

    $updated = $service->update($product, [
        'sku' => 'PROD-001',
        'name' => 'Producto editado',
        'type' => 'physical',
        'product_category_id' => $cat->id,
        'product_subcategory_id' => $sub->id,
        'status' => 'active',
    ]);

    expect($updated->name)->toBe('Producto editado');
    expect(AuditLog::where('action', 'product_created')->exists())->toBeTrue();
    expect(AuditLog::where('action', 'product_updated')->exists())->toBeTrue();
});

test('5-10 stock: ingreso, salida, ajustes, insuficiente y negativo rechazado', function () {
    $admin = makeAdmin();
    seedStockStage();
    $this->actingAs($admin);

    $product = makeSsdProduct(['sku' => 'SSD-ADJ']);
    $inv = app(InventoryService::class);

    $inv->receive($product, [
        'quantity' => '10',
        'unit_cost' => '50',
        'currency_code' => 'USD',
        'exchange_rate_value' => '1450',
        'reason' => 'Ingreso inicial',
    ]);
    expect($product->fresh()->qty_on_hand)->toBe('10.0000');

    $inv->issue($product, ['quantity' => '2', 'reason' => 'Salida']);
    expect($product->fresh()->qty_on_hand)->toBe('8.0000');

    $inv->adjustIn($product, ['quantity' => '1', 'reason' => 'Faltaba en sistema']);
    expect($product->fresh()->qty_on_hand)->toBe('9.0000');

    $inv->adjustOut($product, ['quantity' => '1', 'reason' => 'Sobra en sistema']);
    expect($product->fresh()->qty_on_hand)->toBe('8.0000');

    expect(fn () => $inv->issue($product, ['quantity' => '100', 'reason' => 'Demasiado']))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $inv->issue($product, ['quantity' => '9', 'reason' => 'Negativo']))
        ->toThrow(InvalidArgumentException::class);

    expect($product->fresh()->qty_on_hand)->toBe('8.0000');
});

test('11-13 lotes desde compra con costo histórico', function () {
    $admin = makeAdmin();
    seedStockStage();
    $this->actingAs($admin);

    $supplier = makeSupplierStock();
    $product = makeSsdProduct(['sku' => 'SSD-LOT']);
    $purchase = app(PurchaseService::class)->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [[
            'product_id' => $product->id,
            'description' => 'SSD 1 TB',
            'quantity' => '10',
            'unit_price' => '60',
        ]],
    ]);

    $lot = InventoryLot::where('purchase_id', $purchase->id)->firstOrFail();
    expect($lot->qty_received)->toBe('10.0000');
    expect($lot->qty_remaining)->toBe('10.0000');
    expect($lot->unit_cost)->toBe('60.000000');
    expect($lot->unit_cost_usd)->toBe('60.000000');
    expect($lot->purchase_item_id)->not->toBeNull();
    expect($product->fresh()->qty_on_hand)->toBe('10.0000');
    expect($purchase->items->first()->qty_pending_stock)->toBe('0.0000');
    expect(InventoryMovement::where('purchase_id', $purchase->id)->where('type', 'receipt')->count())->toBe(1);
});

test('14-20 y 28 FIFO obligatorio SSD 10@60 + 5@70 consumir 12 = USD 740', function () {
    $admin = makeAdmin();
    seedStockStage();
    $this->actingAs($admin);

    $supplier = makeSupplierStock();
    $product = makeSsdProduct();
    $purchases = app(PurchaseService::class);

    $purchases->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [[
            'product_id' => $product->id,
            'description' => 'SSD lote A',
            'quantity' => '10',
            'unit_price' => '60',
        ]],
    ]);

    // Garantizar orden FIFO por received_at
    $lotA = InventoryLot::where('product_id', $product->id)->firstOrFail();
    $lotA->update(['received_at' => now()->subDay()]);

    $purchases->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [[
            'product_id' => $product->id,
            'description' => 'SSD lote B',
            'quantity' => '5',
            'unit_price' => '70',
        ]],
    ]);

    expect($product->fresh()->qty_on_hand)->toBe('15.0000');

    $movement = app(InventoryService::class)->consume($product, [
        'quantity' => '12',
        'reason' => 'Consumo FIFO test',
    ]);

    expect($movement->total_cost_usd)->toBe('740.00');
    expect($movement->allocations)->toHaveCount(2);
    expect($movement->allocations[0]->quantity)->toBe('10.0000');
    expect($movement->allocations[0]->unit_cost)->toBe('60.000000');
    expect($movement->allocations[1]->quantity)->toBe('2.0000');
    expect($movement->allocations[1]->unit_cost)->toBe('70.000000');

    $lots = InventoryLot::where('product_id', $product->id)->orderBy('id')->get();
    expect($lots[0]->qty_remaining)->toBe('0.0000');
    expect($lots[1]->qty_remaining)->toBe('3.0000');
    expect($product->fresh()->qty_on_hand)->toBe('3.0000');

    // Cotización posterior no altera costos históricos
    $rate = ExchangeRate::latest('id')->first();
    ExchangeRate::query()->create([
        'base_currency_id' => $rate->base_currency_id,
        'quote_currency_id' => $rate->quote_currency_id,
        'rate_type' => $rate->rate_type ?? 'sell',
        'rate' => '9999.000000',
        'rate_at' => now(),
        'source' => 'manual',
        'provider' => 'manual',
    ]);

    expect($lots[0]->fresh()->unit_cost_usd)->toBe('60.000000');
    expect($movement->fresh()->total_cost_usd)->toBe('740.00');
});

test('21-23 reservas', function () {
    $admin = makeAdmin();
    seedStockStage();
    $this->actingAs($admin);

    $product = makeSsdProduct(['sku' => 'SSD-RES']);
    $inv = app(InventoryService::class);
    $inv->receive($product, [
        'quantity' => '10',
        'unit_cost' => '1',
        'currency_code' => 'ARS',
        'reason' => 'Base',
    ]);

    $inv->reserve($product, ['quantity' => '3', 'reason' => 'Pedido']);
    $p = $product->fresh();
    expect($p->qty_on_hand)->toBe('10.0000');
    expect($p->qty_reserved)->toBe('3.0000');
    expect($p->qtyAvailable())->toBe('7.0000');

    expect(fn () => $inv->reserve($product, ['quantity' => '8', 'reason' => 'Exceso']))
        ->toThrow(InvalidArgumentException::class);

    $inv->release($product, ['quantity' => '2', 'reason' => 'Libera']);
    expect($product->fresh()->qty_reserved)->toBe('1.0000');
    expect($product->fresh()->qtyAvailable())->toBe('9.0000');
});

test('24-25 concurrencia: segunda operación sobre stock insuficiente falla', function () {
    $admin = makeAdmin();
    seedStockStage();
    $this->actingAs($admin);

    $product = makeSsdProduct(['sku' => 'SSD-CONC']);
    $inv = app(InventoryService::class);
    $inv->receive($product, [
        'quantity' => '5',
        'unit_cost' => '10',
        'currency_code' => 'USD',
        'exchange_rate_value' => '1450',
        'reason' => 'Base',
    ]);

    DB::transaction(function () use ($inv, $product) {
        $inv->consume($product, ['quantity' => '4', 'reason' => 'Primera', 'wrap_transaction' => false]);
        expect(fn () => $inv->consume($product->fresh(), ['quantity' => '2', 'reason' => 'Segunda', 'wrap_transaction' => false]))
            ->toThrow(InvalidArgumentException::class);
    });

    expect($product->fresh()->qty_on_hand)->toBe('1.0000');
});

test('26-28 reconstrucción de stock', function () {
    $admin = makeAdmin();
    seedStockStage();
    $this->actingAs($admin);

    $product = makeSsdProduct(['sku' => 'SSD-REB']);
    $inv = app(InventoryService::class);
    $balances = app(StockBalanceService::class);

    $inv->receive($product, [
        'quantity' => '10',
        'unit_cost' => '20',
        'currency_code' => 'USD',
        'exchange_rate_value' => '1450',
        'reason' => 'Base',
    ]);
    $inv->consume($product, ['quantity' => '3', 'reason' => 'Uso']);

    // Corromper cache
    $product->update(['qty_on_hand' => '999', 'qty_reserved' => '50']);
    $lot = InventoryLot::where('product_id', $product->id)->first();
    $lot->update(['qty_remaining' => '0']);

    $result = $balances->rebuildProduct($product->fresh());
    expect($result['qty_on_hand'])->toBe('7.0000');
    expect($result['qty_reserved'])->toBe('0.0000');
    expect($lot->fresh()->qty_remaining)->toBe('7.0000');
    expect($product->fresh()->qty_on_hand)->toBe('7.0000');
});

test('29-31 rollback e integridad', function () {
    $admin = makeAdmin();
    seedStockStage();
    $this->actingAs($admin);

    $product = makeSsdProduct(['sku' => 'SSD-RB']);
    $inv = app(InventoryService::class);

    $beforeLots = InventoryLot::count();
    $beforeMov = InventoryMovement::count();

    expect(fn () => $inv->receive($product, [
        'quantity' => '5',
        'unit_cost' => '10',
        'currency_code' => 'USD',
        'exchange_rate_value' => '1450',
        'reason' => 'Fail',
        'force_fail' => true,
    ]))->toThrow(RuntimeException::class);

    expect(InventoryLot::count())->toBe($beforeLots);
    expect(InventoryMovement::count())->toBe($beforeMov);
    expect($product->fresh()->qty_on_hand)->toBe('0.0000');

    $inv->receive($product, [
        'quantity' => '5',
        'unit_cost' => '10',
        'currency_code' => 'USD',
        'exchange_rate_value' => '1450',
        'reason' => 'OK',
    ]);

    expect(fn () => $inv->consume($product, [
        'quantity' => '2',
        'reason' => 'Fail after',
        'force_fail_after' => true,
    ]))->toThrow(RuntimeException::class);

    expect($product->fresh()->qty_on_hand)->toBe('5.0000');
    expect(InventoryLotAllocation::count())->toBe(0);
    expect(AuditLog::where('action', 'like', 'inventory_%')->exists())->toBeTrue();
});

test('32 permisos stock y productos', function () {
    seedStockStage();
    $viewer = makeUserWithPermissions(['products.view', 'stock.view']);
    $product = null;

    $admin = makeAdmin();
    $this->actingAs($admin);
    $product = makeSsdProduct(['sku' => 'SSD-PERM']);

    $this->actingAs($viewer)
        ->post(route('products.store'), [
            'sku' => 'X',
            'name' => 'Y',
            'type' => 'physical',
            'status' => 'active',
        ])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->post(route('stock.consume.store', $product), [
            'quantity' => 1,
            'reason' => 'no',
            'movement_date' => now()->toDateString(),
        ])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->post(route('stock.adjust.store', $product), [
            'direction' => 'in',
            'quantity' => 1,
            'reason' => 'no',
            'movement_date' => now()->toDateString(),
        ])
        ->assertForbidden();
});

test('compra sin product_id no genera stock duplicado', function () {
    $admin = makeAdmin();
    seedStockStage();
    $this->actingAs($admin);

    $supplier = makeSupplierStock();
    app(PurchaseService::class)->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [[
            'description' => 'Sin producto',
            'quantity' => '3',
            'unit_price' => '10',
        ]],
    ]);

    expect(InventoryLot::count())->toBe(0);
    expect(InventoryMovement::count())->toBe(0);
});

test('servicio no admite stock físico', function () {
    $admin = makeAdmin();
    seedStockStage();
    $this->actingAs($admin);

    $service = app(ProductService::class)->create([
        'sku' => 'SRV-1',
        'name' => 'Instalación',
        'type' => 'service',
    ]);

    expect(fn () => app(InventoryService::class)->receive($service, [
        'quantity' => '1',
        'unit_cost' => '0',
        'currency_code' => 'ARS',
        'reason' => 'no',
    ]))->toThrow(InvalidArgumentException::class);
});
