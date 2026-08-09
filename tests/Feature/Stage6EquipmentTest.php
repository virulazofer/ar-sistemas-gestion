<?php

use App\Enums\EquipmentStatus;
use App\Enums\InventorySerialStatus;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\EquipmentComponent;
use App\Models\EquipmentComponentCategory;
use App\Models\EquipmentType;
use App\Models\InventoryLot;
use App\Models\InventorySerial;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Catalog\ProductService;
use App\Services\Equipment\EquipmentAssemblyService;
use App\Services\Equipment\EquipmentTypeService;
use App\Services\Inventory\InventoryService;
use App\Services\Purchases\PurchaseService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\EquipmentCatalogSeeder;
use Database\Seeders\ExchangeRateSeeder;
use Database\Seeders\FinancialAccountSeeder;
use Database\Seeders\InventoryCatalogSeeder;

function seedEquipmentStage(): void
{
    test()->seed(CurrencySeeder::class);
    test()->seed(FinancialAccountSeeder::class);
    test()->seed(ExchangeRateSeeder::class);
    test()->seed(InventoryCatalogSeeder::class);
    test()->seed(EquipmentCatalogSeeder::class);
}

function makeEquipSupplier(): Supplier
{
    return Supplier::query()->create(['name' => 'Prov Equipos', 'status' => 'active']);
}

function makePhysicalProduct(string $sku, array $extra = []): Product
{
    return app(ProductService::class)->create(array_merge([
        'sku' => $sku,
        'name' => $sku,
        'type' => 'physical',
        'status' => 'active',
    ], $extra));
}

test('1-3 tipos, plantilla y sugeridos', function () {
    $admin = makeAdmin();
    seedEquipmentStage();
    $this->actingAs($admin);

    $service = app(EquipmentTypeService::class);
    $type = $service->createType(['name' => 'Mini PC Custom', 'code_prefix' => 'MPC']);
    $cat = EquipmentComponentCategory::where('slug', 'storage')->firstOrFail();
    $service->addTemplateItem($type, [
        'component_category_id' => $cat->id,
        'qty_min' => 1,
        'qty_default' => 2,
        'qty_max' => 4,
        'is_required' => true,
    ]);

    $suggested = $service->suggestedComponents($type->fresh('templateItems.category'));
    expect($suggested)->not->toBeEmpty();
    expect($suggested[0]['qty_default'])->toBe(2);
    expect($suggested[0]['category'])->toBe('Storage');
});

test('4-10 y 24 armado FIFO SSD 2x60 = USD 120', function () {
    $admin = makeAdmin();
    seedEquipmentStage();
    $this->actingAs($admin);

    $supplier = makeEquipSupplier();
    $ssd = makePhysicalProduct('SSD-1TB-EQ');
    $purchases = app(PurchaseService::class);

    $purchases->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [['product_id' => $ssd->id, 'description' => 'SSD A', 'quantity' => '10', 'unit_price' => '60']],
    ]);
    $lotA = InventoryLot::where('product_id', $ssd->id)->firstOrFail();
    $lotA->update(['received_at' => now()->subDay()]);

    $purchases->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [['product_id' => $ssd->id, 'description' => 'SSD B', 'quantity' => '5', 'unit_price' => '70']],
    ]);

    $type = EquipmentType::where('slug', 'pc-oficina')->firstOrFail();
    $storageCat = EquipmentComponentCategory::where('slug', 'storage')->firstOrFail();

    $equipment = app(EquipmentAssemblyService::class)->assemble([
        'equipment_type_id' => $type->id,
        'name' => 'PC Oficina SSD',
        'code' => 'PC-000001',
        'components' => [[
            'product_id' => $ssd->id,
            'quantity' => 2,
            'component_category_id' => $storageCat->id,
        ]],
    ]);

    expect($equipment->code)->toBe('PC-000001');
    expect($equipment->status)->toBe(EquipmentStatus::Assembled);
    expect($equipment->total_cost_usd)->toBe('120.00');
    expect($ssd->fresh()->qty_on_hand)->toBe('13.0000');
    expect($lotA->fresh()->qty_remaining)->toBe('8.0000');
    expect(InventoryLot::where('product_id', $ssd->id)->orderBy('id')->skip(1)->first()->qty_remaining)->toBe('5.0000');

    $comp = $equipment->components->first();
    expect($comp->inventory_lot_id)->toBe($lotA->id);
    expect($comp->total_cost_usd)->toBe('120.00');
    expect($comp->inventory_lot_allocation_id)->not->toBeNull();
});

test('11-15 y 25 serialización GPU SN002', function () {
    $admin = makeAdmin();
    seedEquipmentStage();
    $this->actingAs($admin);

    $gpu = makePhysicalProduct('GPU-RTX', ['requires_serial' => true, 'name' => 'RTX X']);
    $inv = app(InventoryService::class);

    $inv->receive($gpu, [
        'quantity' => '3',
        'unit_cost' => '300',
        'currency_code' => 'USD',
        'exchange_rate_value' => '1450',
        'reason' => 'Ingreso GPU',
        'serials' => ['SN001', 'SN002', 'SN003'],
    ]);

    expect(InventorySerial::where('product_id', $gpu->id)->count())->toBe(3);

    expect(fn () => $inv->receive($gpu, [
        'quantity' => '1',
        'unit_cost' => '300',
        'currency_code' => 'USD',
        'exchange_rate_value' => '1450',
        'reason' => 'Dup',
        'serials' => ['SN001'],
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => $inv->consume($gpu, ['quantity' => '1', 'reason' => 'sin serial']))
        ->toThrow(InvalidArgumentException::class);

    $type = EquipmentType::where('slug', 'pc-gamer')->firstOrFail();
    $gpuCat = EquipmentComponentCategory::where('slug', 'gpu')->firstOrFail();

    $pc = app(EquipmentAssemblyService::class)->assemble([
        'equipment_type_id' => $type->id,
        'name' => 'Gamer SN',
        'components' => [[
            'product_id' => $gpu->id,
            'component_category_id' => $gpuCat->id,
            'serial_number' => 'SN002',
        ]],
    ]);

    $serial = InventorySerial::where('serial_number', 'SN002')->firstOrFail();
    expect($serial->status)->toBe(InventorySerialStatus::Consumed);
    expect($pc->components->first()->serial->serial_number)->toBe('SN002');
    expect($gpu->fresh()->qty_on_hand)->toBe('2.0000');

    expect(fn () => app(EquipmentAssemblyService::class)->assemble([
        'equipment_type_id' => $type->id,
        'name' => 'Otro',
        'components' => [[
            'product_id' => $gpu->id,
            'serial_number' => 'SN002',
        ]],
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => app(EquipmentAssemblyService::class)->assemble([
        'equipment_type_id' => $type->id,
        'name' => 'Fantasma',
        'components' => [[
            'product_id' => $gpu->id,
            'serial_number' => 'SN999',
        ]],
    ]))->toThrow(InvalidArgumentException::class);
});

test('16-18 FIFO en armado con dos lotes parcial', function () {
    $admin = makeAdmin();
    seedEquipmentStage();
    $this->actingAs($admin);

    $supplier = makeEquipSupplier();
    $ram = makePhysicalProduct('RAM-16');
    $purchases = app(PurchaseService::class);
    $purchases->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [['product_id' => $ram->id, 'description' => 'RAM1', 'quantity' => '1', 'unit_price' => '40']],
    ]);
    InventoryLot::where('product_id', $ram->id)->first()->update(['received_at' => now()->subDays(2)]);
    $purchases->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [['product_id' => $ram->id, 'description' => 'RAM2', 'quantity' => '2', 'unit_price' => '50']],
    ]);

    $type = EquipmentType::where('slug', 'pc-oficina')->firstOrFail();
    $eq = app(EquipmentAssemblyService::class)->assemble([
        'equipment_type_id' => $type->id,
        'name' => 'RAM mix',
        'components' => [['product_id' => $ram->id, 'quantity' => 2]],
    ]);

    expect($eq->total_cost_usd)->toBe('90.00'); // 40 + 50
    expect($eq->components)->toHaveCount(2);
});

test('19-20 rollback armado', function () {
    $admin = makeAdmin();
    seedEquipmentStage();
    $this->actingAs($admin);

    $ssd = makePhysicalProduct('SSD-RB');
    app(InventoryService::class)->receive($ssd, [
        'quantity' => '5',
        'unit_cost' => '60',
        'currency_code' => 'USD',
        'exchange_rate_value' => '1450',
        'reason' => 'base',
    ]);

    $type = EquipmentType::where('slug', 'pc-oficina')->firstOrFail();
    $beforeStock = $ssd->fresh()->qty_on_hand;
    $beforeEq = Equipment::count();
    $beforeComp = EquipmentComponent::count();

    expect(fn () => app(EquipmentAssemblyService::class)->assemble([
        'equipment_type_id' => $type->id,
        'name' => 'Fail',
        'force_fail' => true,
        'components' => [['product_id' => $ssd->id, 'quantity' => 2]],
    ]))->toThrow(RuntimeException::class);

    expect($ssd->fresh()->qty_on_hand)->toBe($beforeStock);
    expect(Equipment::count())->toBe($beforeEq);
    expect(EquipmentComponent::count())->toBe($beforeComp);
});

test('21-22 estados válidos e inválidos', function () {
    $admin = makeAdmin();
    seedEquipmentStage();
    $this->actingAs($admin);

    $ssd = makePhysicalProduct('SSD-ST');
    app(InventoryService::class)->receive($ssd, [
        'quantity' => '2', 'unit_cost' => '10', 'currency_code' => 'USD', 'exchange_rate_value' => '1450', 'reason' => 'x',
    ]);
    $type = EquipmentType::where('slug', 'pc-oficina')->firstOrFail();
    $eq = app(EquipmentAssemblyService::class)->assemble([
        'equipment_type_id' => $type->id,
        'name' => 'Estados',
        'components' => [['product_id' => $ssd->id, 'quantity' => 1]],
    ]);

    $svc = app(EquipmentAssemblyService::class);
    $svc->changeStatus($eq, EquipmentStatus::Available);
    expect($eq->fresh()->status)->toBe(EquipmentStatus::Available);

    expect(fn () => $svc->changeStatus($eq->fresh(), EquipmentStatus::Assembled))
        ->toThrow(InvalidArgumentException::class);

    $svc->changeStatus($eq->fresh(), EquipmentStatus::Sold, 'Venta');
    expect(fn () => $svc->disassemble($eq->fresh(), 'no'))
        ->toThrow(InvalidArgumentException::class);
});

test('23-27 reemplazo y desarmado', function () {
    $admin = makeAdmin();
    seedEquipmentStage();
    $this->actingAs($admin);

    $ssd = makePhysicalProduct('SSD-DS');
    $ssd2 = makePhysicalProduct('SSD-NEW');
    $inv = app(InventoryService::class);
    $inv->receive($ssd, ['quantity' => '3', 'unit_cost' => '60', 'currency_code' => 'USD', 'exchange_rate_value' => '1450', 'reason' => 'a']);
    $inv->receive($ssd2, ['quantity' => '2', 'unit_cost' => '80', 'currency_code' => 'USD', 'exchange_rate_value' => '1450', 'reason' => 'b']);

    $type = EquipmentType::where('slug', 'pc-oficina')->firstOrFail();
    $svc = app(EquipmentAssemblyService::class);
    $eq = $svc->assemble([
        'equipment_type_id' => $type->id,
        'name' => 'Replace',
        'components' => [['product_id' => $ssd->id, 'quantity' => 1]],
    ]);

    $old = $eq->components->first();
    $svc->replaceComponent($eq, $old, ['product_id' => $ssd2->id, 'quantity' => 1], 'Upgrade');

    expect($old->fresh()->status->value)->toBe('recovered');
    expect($eq->fresh()->components()->where('status', 'installed')->count())->toBe(1);
    expect($ssd->fresh()->qty_on_hand)->toBe('3.0000'); // 3-1+1 recovered
    expect($ssd2->fresh()->qty_on_hand)->toBe('1.0000');

    $svc->changeStatus($eq->fresh(), EquipmentStatus::Available);
    $svc->disassemble($eq->fresh(), 'Fin de vida');
    expect($eq->fresh()->status)->toBe(EquipmentStatus::Disassembled);
    expect($ssd2->fresh()->qty_on_hand)->toBe('2.0000');
    expect(AuditLog::where('action', 'equipment_disassembled')->exists())->toBeTrue();
});

test('28-29 permisos y auditoría', function () {
    seedEquipmentStage();
    $viewer = makeUserWithPermissions(['equipment.view']);

    $this->actingAs($viewer)
        ->post(route('equipment.store'), [
            'equipment_type_id' => EquipmentType::first()->id,
            'name' => 'X',
            'components' => [['product_id' => 1, 'quantity' => 1]],
        ])
        ->assertForbidden();

    $admin = makeAdmin();
    $this->actingAs($admin);
    $ssd = makePhysicalProduct('SSD-AUD');
    app(InventoryService::class)->receive($ssd, [
        'quantity' => '1', 'unit_cost' => '10', 'currency_code' => 'USD', 'exchange_rate_value' => '1450', 'reason' => 'a',
    ]);
    app(EquipmentAssemblyService::class)->assemble([
        'equipment_type_id' => EquipmentType::where('slug', 'pc-oficina')->first()->id,
        'name' => 'Aud',
        'components' => [['product_id' => $ssd->id, 'quantity' => 1]],
    ]);

    expect(AuditLog::where('action', 'equipment_assembled')->exists())->toBeTrue();
});
