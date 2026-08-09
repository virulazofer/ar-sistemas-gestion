<?php

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Client;
use App\Models\FinancialAccount;
use App\Models\ImportBatch;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Services\Catalog\ProductService;
use App\Services\Dashboard\DashboardService;
use App\Services\Exports\ExportService;
use App\Services\Imports\ImportService;
use App\Services\Purchases\PurchaseService;
use App\Services\Reports\ReportService;
use App\Services\Sales\SaleService;
use App\Services\Search\GlobalSearchService;
use Database\Seeders\CommercialCatalogSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ExchangeRateSeeder;
use Database\Seeders\FinancialAccountSeeder;
use Database\Seeders\InventoryCatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function seedStage9(): void
{
    test()->seed(CurrencySeeder::class);
    test()->seed(FinancialAccountSeeder::class);
    test()->seed(ExchangeRateSeeder::class);
    test()->seed(InventoryCatalogSeeder::class);
    test()->seed(CommercialCatalogSeeder::class);
}

function csvUpload(string $name, string $content): UploadedFile
{
    Storage::fake('local');
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.$name;
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

test('dashboard saldos ARS/USD separados y scopes', function () {
    $admin = makeAdmin();
    seedStage9();
    $this->actingAs($admin);

    $dash = app(DashboardService::class);
    $all = $dash->snapshot('all');
    $personal = $dash->snapshot('personal');
    $professional = $dash->snapshot('professional');

    expect($all['liquid'])->toHaveKeys(['ARS', 'USD']);
    expect($all['liquid']['ARS'])->toHaveKeys(['cash', 'bank', 'wallet', 'other', 'total']);
    expect($all['liquid']['USD'])->toHaveKeys(['cash', 'bank', 'wallet', 'other', 'total']);
    expect($personal['activity']['filter'])->toBe('personal');
    expect($professional['activity']['filter'])->toBe('professional');
    expect($all['activity']['filter'])->toBe('all');
});

test('escenario integrado compra-venta refleja dashboard y reportes', function () {
    $admin = makeAdmin();
    seedStage9();
    $this->actingAs($admin);

    $client = Client::query()->create(['name' => 'Cliente Dash', 'status' => 'active']);
    $supplier = Supplier::query()->create(['name' => 'Prov Dash', 'status' => 'active']);
    $ssd = app(ProductService::class)->create(['sku' => 'SSD-D9', 'name' => 'SSD', 'type' => 'physical']);
    app(PurchaseService::class)->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [['product_id' => $ssd->id, 'description' => 'SSD', 'quantity' => '10', 'unit_price' => '60']],
    ]);

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
    app(SaleService::class)->confirm($sale, ['payment_mode' => 'credit']);

    expect($ssd->fresh()->qty_on_hand)->toBe('8.0000');
    expect($sale->fresh()->total_cost_usd)->toBe('120.00');
    expect($sale->fresh()->gross_margin)->toBe('60.00');

    app(DashboardService::class)->clearCache();
    $dash = app(DashboardService::class)->snapshot('all');
    expect($dash['stock']['value_usd'])->not->toBe('0.00');
    expect((float) $dash['sales']['total_usd'])->toBeGreaterThanOrEqual(180);

    $reports = app(ReportService::class);
    $stock = $reports->stockCurrent();
    $ssdRow = collect($stock['rows'])->firstWhere('sku', 'SSD-D9');
    expect($ssdRow['qty_on_hand'])->toBe('8.0000');

    $sales = $reports->salesReport([]);
    expect(collect($sales['rows'])->contains(fn ($r) => $r['total'] === '180.00'))->toBeTrue();

    $profit = $reports->profitability([]);
    $marginSum = collect($profit['rows'])->where('ref', $sale->fresh()->number)->sum(fn ($r) => (float) $r['margin']);
    expect($marginSum)->toBe(60.0);
});

test('reportes finanzas clientes proveedores stock ventas rentabilidad', function () {
    $admin = makeAdmin();
    seedStage9();
    $this->actingAs($admin);

    $r = app(ReportService::class);
    expect($r->financeMovements([])['rows'])->toBeArray();
    expect($r->financeBalances()['rows'])->not->toBeEmpty();
    expect($r->financeIncomeExpense([])['rows'])->toBeArray();
    expect($r->clientsReceivables()['rows'])->toBeArray();
    expect($r->clientsMovements([])['rows'])->toBeArray();
    expect($r->suppliersPayables()['rows'])->toBeArray();
    expect($r->suppliersMovements([])['rows'])->toBeArray();
    expect($r->stockCurrent()['rows'])->toBeArray();
    expect($r->stockLots()['rows'])->toBeArray();
    expect($r->stockLow()['rows'])->toBeArray();
    expect($r->salesReport([])['rows'])->toBeArray();
    expect($r->profitability([])['rows'])->toBeArray();
    expect($r->chartAccountsSummary()['rows'])->toBeArray();
});

test('importación clientes preview confirm rollback y duplicados', function () {
    $admin = makeAdmin();
    seedStage9();
    $this->actingAs($admin);

    Client::query()->create(['name' => 'Existente', 'cuit' => '20111111111', 'status' => 'active']);

    $csv = "name,cuit,dni,email,status\nNuevo Cliente,20999999999,,a@test.com,active\nDup,20111111111,,,active\n,bad,,,active\n";
    $file = csvUpload('clients.csv', $csv);
    $svc = app(ImportService::class);
    $batch = $svc->parseAndPreview('clients', $file, $admin->id);

    expect($batch->status)->toBe('preview');
    expect($batch->rows_valid)->toBeGreaterThanOrEqual(1);
    expect($batch->rows_duplicate)->toBeGreaterThanOrEqual(1);
    expect($batch->rows_invalid)->toBeGreaterThanOrEqual(1);
    expect(AuditLog::where('action', 'import_previewed')->exists())->toBeTrue();

    $svc->confirm($batch->fresh());
    expect(Client::where('name', 'Nuevo Cliente')->where('import_batch_id', $batch->id)->exists())->toBeTrue();
    expect($batch->fresh()->status)->toBe('confirmed');

    $svc->rollback($batch->fresh(), 'Test rollback');
    expect(Client::where('name', 'Nuevo Cliente')->exists())->toBeFalse();
    expect($batch->fresh()->status)->toBe('rolled_back');
});

test('importación productos y movimientos con external_id', function () {
    $admin = makeAdmin();
    seedStage9();
    $this->actingAs($admin);

    $prodCsv = "sku,name,type,stock_min,status\nIMP-1,Producto Imp,physical,1,active\nIMP-1,Dup SKU,physical,0,active\n";
    $batchProd = app(ImportService::class)->parseAndPreview('products', csvUpload('p.csv', $prodCsv), $admin->id);
    expect($batchProd->rows_duplicate)->toBeGreaterThanOrEqual(1);
    app(ImportService::class)->confirm($batchProd->fresh());
    expect(Product::where('sku', 'IMP-1')->count())->toBe(1);

    $account = FinancialAccount::where('name', 'Caja ARS')->first()
        ?? FinancialAccount::query()->firstOrFail();

    $movCsv = "external_id,type,scope,financial_account_id,amount,movement_date,description\n"
        ."EXT-001,income,professional,{$account->id},100,2026-08-01,Importado\n"
        ."EXT-001,income,professional,{$account->id},50,2026-08-01,Dup\n";
    $batchMov = app(ImportService::class)->parseAndPreview('movements', csvUpload('m.csv', $movCsv), $admin->id);
    expect($batchMov->rows_duplicate + $batchMov->rows_valid)->toBeGreaterThan(0);
    // second row duplicate of first within file or after confirm
    app(ImportService::class)->confirm($batchMov->fresh());
    expect(Movement::where('external_id', 'EXT-001')->count())->toBe(1);
    expect(Movement::where('import_batch_id', $batchMov->id)->exists())->toBeTrue();
});

test('exportaciones csv xlsx pdf y permisos', function () {
    $admin = makeAdmin();
    seedStage9();
    $this->actingAs($admin);

    $export = app(ExportService::class);
    $csv = $export->toCsv('t.csv', ['A', 'B'], [['1', '2']]);
    expect($csv)->toBeInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class);

    $xlsx = $export->toXlsx('t.xlsx', ['A'], [['x']]);
    expect($xlsx)->toBeInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class);

    $pdf = $export->toPdf('Test', ['A'], [['y']]);
    expect($pdf->headers->get('content-type'))->toContain('pdf');

    expect(AuditLog::where('action', 'export_generated')->exists())->toBeTrue();

    $viewer = makeUserWithPermissions(['reports.view']);
    $this->actingAs($viewer)->get(route('imports.create'))->assertForbidden();
    $this->actingAs($viewer)
        ->get(route('reports.show', ['type' => 'finance-balances', 'export' => 'csv']))
        ->assertForbidden();
});

test('búsqueda global encuentra cliente y producto', function () {
    $admin = makeAdmin();
    seedStage9();
    $this->actingAs($admin);

    Client::query()->create(['name' => 'Buscable SA', 'status' => 'active']);
    app(ProductService::class)->create(['sku' => 'FIND-ME', 'name' => 'Widget Buscable', 'type' => 'physical']);

    $results = app(GlobalSearchService::class)->search('Buscable');
    expect($results['clients'])->not->toBeEmpty();
    expect($results['products'])->not->toBeEmpty();
});

test('valorización FIFO stock usa lotes', function () {
    $admin = makeAdmin();
    seedStage9();
    $this->actingAs($admin);

    $supplier = Supplier::query()->create(['name' => 'Prov FIFO R', 'status' => 'active']);
    $ssd = app(ProductService::class)->create(['sku' => 'SSD-VAL', 'name' => 'SSD', 'type' => 'physical']);
    app(PurchaseService::class)->create([
        'supplier_id' => $supplier->id,
        'currency_code' => 'USD',
        'payment_mode' => 'credit',
        'items' => [['product_id' => $ssd->id, 'description' => 'SSD', 'quantity' => '5', 'unit_price' => '60']],
    ]);

    $lots = app(ReportService::class)->stockLots();
    expect(collect($lots['rows'])->contains(fn ($r) => $r['sku'] === 'SSD-VAL'))->toBeTrue();
    $current = app(ReportService::class)->stockCurrent();
    $row = collect($current['rows'])->firstWhere('sku', 'SSD-VAL');
    expect($row['value_usd'])->toBe('300.00');
});
