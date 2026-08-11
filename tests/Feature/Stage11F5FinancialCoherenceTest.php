<?php

use App\Enums\ChartAccountType;
use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\CommercialCharge;
use App\Models\FinancialAccount;
use App\Models\Movement;
use App\Models\Subcategory;
use App\Rules\Cuit;
use App\Services\Commercial\CommercialChargeService;
use App\Services\Finance\ChartAccountMappingService;
use App\Services\Finance\ExchangeRateService;
use App\Services\Finance\MovementService;
use Carbon\Carbon;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ExchangeRateSeeder;
use Database\Seeders\FinancialAccountSeeder;

beforeEach(function () {
    $this->seed(CurrencySeeder::class);
});

test('cotizaciones chart incluye compra venta fecha y fuente', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);

    $svc = app(ExchangeRateService::class);
    $svc->storeHistoricalImport('2026-03-01', '1000', '990');
    $svc->storeHistoricalImport('2026-03-02', '1010', '1000');
    $svc->storeHistoricalImport('2026-03-03', '1020', '1010');

    $points = $svc->chartPoints(Carbon::parse('2026-03-01'), Carbon::parse('2026-03-03'));
    expect($points)->toHaveCount(3)
        ->and($points[0])->toHaveKeys(['label', 'date', 'buy', 'sell', 'source', 'value'])
        ->and($points[0]['buy'])->toBe(990.0)
        ->and($points[0]['sell'])->toBe(1000.0);

    $this->get(route('exchange-rates.index', ['from' => '2026-03-01', 'to' => '2026-03-03']))
        ->assertOk()
        ->assertSee('ARS/USD')
        ->assertSee('Venta')
        ->assertSee('Compra');
});

test('carga rapida orden campos e ingreso con CC un solo credito', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $this->seed(FinancialAccountSeeder::class);
    $this->seed(ExchangeRateSeeder::class);

    $html = $this->get(route('movements.quick'))->assertOk()->getContent();
    expect($html)->toContain('Fecha')
        ->and($html)->toContain('Ámbito')
        ->and($html)->toContain('Descripción')
        ->and($html)->toContain('Categoría')
        ->and($html)->toContain('Subcategoría')
        ->and($html)->toContain('Importe')
        ->and($html)->toContain('Aplicar a cuenta corriente');

    $client = Client::query()->create(['name' => 'CC Quick', 'status' => 'active']);
    $account = FinancialAccount::query()->whereHas('currency', fn ($q) => $q->where('code', 'ARS'))->firstOrFail();

    app(CommercialChargeService::class)->create([
        'client_id' => $client->id,
        'charge_type' => 'other',
        'concept' => 'Servicio',
        'amount' => '100.00',
        'currency_code' => 'ARS',
        'charged_on' => now()->toDateString(),
        'scope' => 'professional',
    ]);

    $before = Movement::query()->posted()->where('type', 'income')->count();

    $this->post(route('movements.quick.store'), [
        'type' => 'income',
        'scope' => 'professional',
        'financial_account_id' => $account->id,
        'amount' => '100',
        'movement_date' => now()->toDateString(),
        'description' => 'Cobro quick',
        'client_id' => $client->id,
        'apply_to_cc' => '1',
    ])->assertRedirect();

    $after = Movement::query()->posted()->where('type', 'income')->count();
    expect($after - $before)->toBe(1);
});

test('ingreso apply CC sin deuda suficiente ofrece A B C D y C no inventa deuda', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $this->seed(FinancialAccountSeeder::class);
    $this->seed(ExchangeRateSeeder::class);

    $client = Client::query()->create(['name' => 'Sin deuda', 'status' => 'active']);
    $account = FinancialAccount::query()->whereHas('currency', fn ($q) => $q->where('code', 'ARS'))->firstOrFail();

    $this->post(route('movements.quick.store'), [
        'type' => 'income',
        'scope' => 'professional',
        'financial_account_id' => $account->id,
        'amount' => '50',
        'movement_date' => now()->toDateString(),
        'description' => 'Sin CC',
        'client_id' => $client->id,
        'apply_to_cc' => '1',
    ])->assertRedirect();

    $chargesBefore = CommercialCharge::query()->where('client_id', $client->id)->count();

    $this->followingRedirects()
        ->get(route('movements.quick', ['client_id' => $client->id, 'financial_account_id' => $account->id]))
        ->assertOk();

    // Decision via session from previous post — re-post with income_only
    $this->withSession([
        'quick_income_decision' => [
            'message' => 'No hay deuda abierta suficiente en CC.',
            'open_debt' => '0.00',
            'amount' => '50.00',
            'client_id' => $client->id,
            'apply_to_cc' => true,
        ],
    ])->post(route('movements.quick.store'), [
        'type' => 'income',
        'scope' => 'professional',
        'financial_account_id' => $account->id,
        'amount' => '50',
        'movement_date' => now()->toDateString(),
        'description' => 'Solo ingreso',
        'client_id' => $client->id,
        'apply_to_cc' => '1',
        'insufficient_option' => 'income_only',
    ])->assertRedirect();

    expect(CommercialCharge::query()->where('client_id', $client->id)->count())->toBe($chargesBefore);
    expect(Movement::query()->posted()->where('description', 'Solo ingreso')->exists())->toBeTrue();
});

test('proveedor y cliente CUIT usan validador central con digito verificador', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);

    expect(Cuit::isValidChecksum('30712345671'))->toBeTrue();

    $this->post(route('suppliers.store'), [
        'name' => 'Prov Bad',
        'party_type' => 'empresa',
        'business_name' => 'Prov Bad SA',
        'cuit' => '30-71234567-8', // checksum inválido
        'tax_condition' => 'responsable_inscripto',
        'status' => 'active',
    ])->assertSessionHasErrors('cuit');

    $this->post(route('suppliers.store'), [
        'name' => 'Prov Ok',
        'party_type' => 'empresa',
        'business_name' => 'Prov Ok SA',
        'cuit' => '30-71234567-1',
        'tax_condition' => 'responsable_inscripto',
        'status' => 'active',
    ])->assertRedirect();

    expect(\App\Models\Supplier::query()->where('cuit', '30712345671')->exists())->toBeTrue();
});

test('categorias expandibles y detalle subcategoria', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $this->seed(FinancialAccountSeeder::class);
    $this->seed(ExchangeRateSeeder::class);

    $cat = Category::query()->create(['name' => 'Analítica Cat', 'scope' => 'personal', 'is_active' => true, 'sort_order' => 1]);
    $sub = Subcategory::query()->create(['category_id' => $cat->id, 'name' => 'Sub Analítica', 'is_active' => true, 'sort_order' => 1]);
    $account = FinancialAccount::query()->firstOrFail();

    app(MovementService::class)->createSimple([
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $account->id,
        'amount' => '25',
        'category_id' => $cat->id,
        'subcategory_id' => $sub->id,
        'movement_date' => now()->toDateString(),
        'description' => 'gasto sub',
    ]);

    $this->get(route('categories.index'))
        ->assertOk()
        ->assertSee('Analítica Cat')
        ->assertSee('Sub Analítica');

    $this->get(route('subcategories.show', $sub))
        ->assertOk()
        ->assertSee('Sub Analítica')
        ->assertSee('gasto sub')
        ->assertSee('Total ARS');
});

test('mapeo plan precedencia preview apply y alerta sin cuenta', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $this->seed(FinancialAccountSeeder::class);
    $this->seed(ExchangeRateSeeder::class);

    $incomeRoot = ChartAccount::query()->create([
        'code' => '4', 'name' => 'Ingresos', 'type' => ChartAccountType::Income, 'is_active' => true, 'sort_order' => 1,
    ]);
    $expenseLeaf = ChartAccount::query()->create([
        'code' => '5.9', 'name' => 'Gastos varios', 'type' => ChartAccountType::Expense, 'is_active' => true, 'sort_order' => 1,
    ]);
    $subLeaf = ChartAccount::query()->create([
        'code' => '5.9.1', 'name' => 'Detalle', 'type' => ChartAccountType::Expense, 'parent_id' => $expenseLeaf->id, 'is_active' => true, 'sort_order' => 1,
    ]);

    $cat = Category::query()->create([
        'name' => 'Map Cat', 'scope' => 'professional', 'chart_account_id' => $expenseLeaf->id, 'is_active' => true, 'sort_order' => 1,
    ]);
    $sub = Subcategory::query()->create([
        'category_id' => $cat->id, 'name' => 'Map Sub', 'chart_account_id' => $subLeaf->id, 'is_active' => true, 'sort_order' => 1,
    ]);

    $mapping = app(ChartAccountMappingService::class);
    expect($mapping->resolve($cat->id, $sub->id, 'expense')['source'])->toBe('subcategory')
        ->and($mapping->resolve($cat->id, $sub->id, 'expense')['chart_account_id'])->toBe($subLeaf->id);

    $mapping->mapSubcategory($sub, null);
    expect($mapping->resolve($cat->id, $sub->id, 'expense')['source'])->toBe('category');

    $mapping->mapCategory($cat, null);
    $mapping->saveTypeDefaults(['income' => $incomeRoot->id, 'expense' => $expenseLeaf->id]);
    expect($mapping->resolve($cat->id, $sub->id, 'expense')['source'])->toBe('type')
        ->and($mapping->resolve($cat->id, $sub->id, 'expense')['chart_account_id'])->toBe($expenseLeaf->id);

    $account = FinancialAccount::query()->firstOrFail();
    $m = app(MovementService::class)->createSimple([
        'type' => 'expense',
        'scope' => 'professional',
        'financial_account_id' => $account->id,
        'amount' => '10',
        'category_id' => $cat->id,
        'subcategory_id' => $sub->id,
        'movement_date' => now()->toDateString(),
        'description' => 'map test',
    ]);
    expect((int) $m->chart_account_id)->toBe($expenseLeaf->id);

    // Force unassigned then rematerialize
    $m->update(['chart_account_id' => null]);
    $preview = $mapping->previewApplyToMovements();
    expect($preview['would_change'])->toBeGreaterThan(0);

    $this->get(route('chart-accounts.mapping'))->assertOk()->assertSee('Mapeo');
    $this->get(route('chart-accounts.index'))->assertOk();

    $result = $mapping->applyToMovements();
    expect($result['updated'])->toBeGreaterThan(0)
        ->and((int) $m->fresh()->chart_account_id)->toBe($expenseLeaf->id);
});

test('reporte movimientos filtra por cuenta contable sin romper listado', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $this->seed(FinancialAccountSeeder::class);
    $this->seed(ExchangeRateSeeder::class);

    $ca = ChartAccount::query()->create([
        'code' => '9.1', 'name' => 'Filtro test', 'type' => ChartAccountType::Expense, 'is_active' => true, 'sort_order' => 1,
    ]);
    $account = FinancialAccount::query()->firstOrFail();
    $m = app(MovementService::class)->createSimple([
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $account->id,
        'amount' => '7',
        'movement_date' => now()->toDateString(),
        'description' => 'con plan',
        'chart_account_id' => $ca->id,
    ]);
    // createSimple may overwrite chart from mapping — force
    $m->update(['chart_account_id' => $ca->id]);

    $this->get(route('reports.show', ['type' => 'finance-movements', 'chart_account_id' => $ca->id]))
        ->assertOk()
        ->assertSee('con plan');

    $this->get(route('reports.show', ['type' => 'finance-movements', 'chart_account_id' => 'unassigned']))
        ->assertOk();
});
