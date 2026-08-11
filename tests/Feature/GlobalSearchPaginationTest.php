<?php

use App\Models\Client;
use App\Services\Catalog\ProductService;
use App\Services\Search\GlobalSearchService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\InventoryCatalogSeeder;

test('preview incluye totals reales y has_more cuando hay mas de 10', function () {
    $admin = makeAdmin();
    test()->seed(CurrencySeeder::class);
    test()->seed(InventoryCatalogSeeder::class);
    $products = app(ProductService::class);

    for ($i = 1; $i <= 15; $i++) {
        $products->create([
            'sku' => sprintf('SSD-PREV-%02d', $i),
            'name' => sprintf('SSD Preview %02d', $i),
            'type' => 'physical',
        ]);
    }

    $this->actingAs($admin)
        ->getJson(route('search', ['q' => 'SSD Preview', 'limit' => 10]))
        ->assertOk()
        ->assertJsonPath('meta.totals.products', 15)
        ->assertJsonPath('meta.total', 15)
        ->assertJsonPath('meta.has_more.products', true)
        ->assertJsonCount(10, 'groups.products');
});

test('menos de 10 no marca has_more y total coincide', function () {
    $admin = makeAdmin();
    test()->seed(CurrencySeeder::class);
    test()->seed(InventoryCatalogSeeder::class);
    $products = app(ProductService::class);

    for ($i = 1; $i <= 3; $i++) {
        $products->create([
            'sku' => sprintf('SSD-FEW-%02d', $i),
            'name' => sprintf('SSD Few %02d', $i),
            'type' => 'physical',
        ]);
    }

    $json = $this->actingAs($admin)
        ->getJson(route('search', ['q' => 'SSD Few', 'limit' => 10]))
        ->assertOk()
        ->assertJsonPath('meta.totals.products', 3)
        ->assertJsonPath('meta.has_more.products', false)
        ->assertJsonCount(3, 'groups.products')
        ->json();

    expect($json['meta']['total'])->toBe(3);
});

test('exactamente 10 no marca has_more del grupo', function () {
    $admin = makeAdmin();
    test()->seed(CurrencySeeder::class);
    test()->seed(InventoryCatalogSeeder::class);
    $products = app(ProductService::class);

    for ($i = 1; $i <= 10; $i++) {
        $products->create([
            'sku' => sprintf('SSD-TEN-%02d', $i),
            'name' => sprintf('SSD Ten %02d', $i),
            'type' => 'physical',
        ]);
    }

    $this->actingAs($admin)
        ->getJson(route('search', ['q' => 'SSD Ten', 'limit' => 10]))
        ->assertOk()
        ->assertJsonPath('meta.totals.products', 10)
        ->assertJsonPath('meta.has_more.products', false)
        ->assertJsonCount(10, 'groups.products');
});

test('pagina completa muestra total 84 productos SSD con paginacion y filtro', function () {
    $admin = makeAdmin();
    test()->seed(CurrencySeeder::class);
    test()->seed(InventoryCatalogSeeder::class);
    $products = app(ProductService::class);

    for ($i = 1; $i <= 84; $i++) {
        $products->create([
            'sku' => sprintf('SSD-FULL-%03d', $i),
            'name' => sprintf('SSD Completo %03d', $i),
            'type' => 'physical',
        ]);
    }

    $preview = app(GlobalSearchService::class)->search('SSD Completo', 10, $admin);
    expect($preview['meta']['totals']['products'])->toBe(84);
    expect(count($preview['products']))->toBe(10);
    expect($preview['meta']['has_more']['products'])->toBeTrue();

    $page1 = $this->actingAs($admin)
        ->get(route('search', ['q' => 'SSD Completo', 'type' => 'products', 'per_page' => 25]))
        ->assertOk()
        ->assertSee('Resultados de búsqueda', false)
        ->assertSee('84 resultados', false)
        ->assertSee('Productos', false)
        ->assertSee('SSD Completo 001', false);

    expect($page1->status())->toBe(200);

    $this->actingAs($admin)
        ->get(route('search', ['q' => 'SSD Completo', 'type' => 'products', 'per_page' => 25, 'page' => 2]))
        ->assertOk()
        ->assertSee('SSD Completo 026', false)
        ->assertSee('type=products', false)
        ->assertSee('q=SSD', false);

    $page = app(GlobalSearchService::class)->searchPage('SSD Completo', 'products', 2, 25, $admin);
    expect($page['total'])->toBe(84);
    expect($page['paginator']->currentPage())->toBe(2);
    expect($page['paginator']->lastPage())->toBe(4);
    expect(count($page['items']))->toBe(25);
    expect(collect($page['items'])->pluck('label')->all())->toContain('SSD Completo 026');
});

test('filtro type se preserva en paginador y tabs', function () {
    $admin = makeAdmin();
    test()->seed(CurrencySeeder::class);
    Client::query()->create(['name' => 'Filtro Cliente Alpha', 'status' => 'active']);
    test()->seed(InventoryCatalogSeeder::class);
    app(ProductService::class)->create([
        'sku' => 'FILT-P1',
        'name' => 'Filtro Producto Alpha',
        'type' => 'physical',
    ]);

    $this->actingAs($admin)
        ->get(route('search', ['q' => 'Filtro', 'type' => 'clients']))
        ->assertOk()
        ->assertSee('Filtro Cliente Alpha', false)
        ->assertDontSee('Filtro Producto Alpha', false)
        ->assertSee('type=clients', false);

    $this->actingAs($admin)
        ->get(route('search', ['q' => 'Filtro', 'type' => 'products']))
        ->assertOk()
        ->assertSee('Filtro Producto Alpha', false)
        ->assertDontSee('Filtro Cliente Alpha', false);
});

test('permisos: sin products.view no ve productos ni puede acceder por limite de preview', function () {
    test()->seed(CurrencySeeder::class);
    test()->seed(InventoryCatalogSeeder::class);
    app(ProductService::class)->create([
        'sku' => 'PERM-SSD-1',
        'name' => 'SSD Permiso',
        'type' => 'physical',
    ]);
    Client::query()->create(['name' => 'Cliente Permiso SSD', 'status' => 'active']);

    $limited = makeUserWithPermissions(['dashboard.view', 'clients.view']);

    $this->actingAs($limited)
        ->getJson(route('search', ['q' => 'SSD', 'limit' => 10]))
        ->assertOk()
        ->assertJsonPath('groups.products', [])
        ->assertJsonPath('meta.totals.products', 0)
        ->assertJsonFragment(['label' => 'Cliente Permiso SSD']);

    $this->actingAs($limited)
        ->get(route('search', ['q' => 'SSD', 'type' => 'products']))
        ->assertOk()
        ->assertDontSee('SSD Permiso', false)
        ->assertSee('No se encontraron resultados', false);
});

test('command palette UI muestra enlace con total de resultados', function () {
    $user = makeUserWithPermissions(['dashboard.view']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('groupMoreLabel', false)
        ->assertSee('globalMoreLabel', false)
        ->assertSee('Ver todos los', false)
        ->assertSee('previewLimit: 10', false)
        ->assertSee('meta?.totals', false);
});

test('busqueda desde 1 caracter sigue funcionando con totals', function () {
    $admin = makeAdmin();
    test()->seed(CurrencySeeder::class);
    Client::query()->create(['name' => 'Alpha Totals', 'status' => 'active']);

    $result = app(GlobalSearchService::class)->search('A', 5, $admin);
    expect($result['meta']['totals']['clients'])->toBeGreaterThanOrEqual(1);
    expect($result['meta']['total'])->toBeGreaterThanOrEqual(1);
    expect(collect($result['clients'])->pluck('label')->all())->toContain('Alpha Totals');
});
