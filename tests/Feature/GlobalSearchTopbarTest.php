<?php

use App\Models\Client;
use App\Services\Catalog\ProductService;
use App\Services\Search\GlobalSearchService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\InventoryCatalogSeeder;
use Illuminate\Support\Facades\DB;

test('la topbar incluye command palette desktop y movil', function () {
    $user = makeUserWithPermissions(['dashboard.view']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('ar-topbar-search', false)
        ->assertSee('Buscar o ir a', false)
        ->assertSee('ar-topbar-search-desktop', false)
        ->assertSee('Abrir búsqueda', false)
        ->assertSee('/buscar', false);
});

test('busqueda JSON respeta permisos y navega a la entidad', function () {
    $admin = makeAdmin();
    test()->seed(CurrencySeeder::class);
    test()->seed(InventoryCatalogSeeder::class);

    $client = Client::query()->create(['name' => 'Cliente Topbar SA', 'status' => 'active']);
    app(ProductService::class)->create(['sku' => 'TOP-1', 'name' => 'Producto Topbar', 'type' => 'physical']);

    $this->actingAs($admin)
        ->getJson(route('search', ['q' => 'Topbar', 'limit' => 5]))
        ->assertOk()
        ->assertJsonPath('q', 'Topbar')
        ->assertJsonFragment(['label' => 'Cliente Topbar SA'])
        ->assertJsonFragment(['url' => route('clients.show', $client)])
        ->assertJsonFragment(['label' => 'Producto Topbar']);

    $limited = makeUserWithPermissions(['dashboard.view', 'products.view']);
    $this->actingAs($limited)
        ->getJson(route('search', ['q' => 'Topbar']))
        ->assertOk()
        ->assertJsonPath('groups.products.0.label', 'Producto Topbar')
        ->assertJsonPath('groups.clients', []);
});

test('consulta vacia no ejecuta busquedas de entidades; un caracter si', function () {
    $admin = makeAdmin();
    test()->seed(CurrencySeeder::class);

    Client::query()->create(['name' => 'No Debe Aparecer', 'status' => 'active']);

    DB::enableQueryLog();
    $empty = app(GlobalSearchService::class)->search(' ', 5, $admin);
    $queriesAfterEmpty = count(DB::getQueryLog());
    expect($empty['clients'])->toBe([]);
    expect($queriesAfterEmpty)->toBe(0);

    DB::flushQueryLog();
    $one = app(GlobalSearchService::class)->search('N', 5, $admin);
    expect(collect($one['clients'])->pluck('label')->all())->toContain('No Debe Aparecer');
    expect(count(DB::getQueryLog()))->toBeGreaterThan(0);

    $this->actingAs($admin)
        ->getJson(route('search', ['q' => '']))
        ->assertOk()
        ->assertJsonPath('groups.clients', [])
        ->assertJsonPath('groups.products', []);
});

test('pagina completa de busqueda sigue disponible', function () {
    $admin = makeAdmin();
    test()->seed(CurrencySeeder::class);
    Client::query()->create(['name' => 'Full Page Search', 'status' => 'active']);

    $this->actingAs($admin)
        ->get(route('search', ['q' => 'Full Page']))
        ->assertOk()
        ->assertSee('Resultados de búsqueda', false)
        ->assertSee('Full Page Search')
        ->assertSee(route('clients.show', Client::where('name', 'Full Page Search')->first()), false);
});

test('busqueda sin resultados responde grupos vacios y UI muestra mensaje', function () {
    $admin = makeAdmin();
    test()->seed(CurrencySeeder::class);

    $this->actingAs($admin)
        ->getJson(route('search', ['q' => 'zzzsinmatchzzz']))
        ->assertOk()
        ->assertJsonPath('groups.clients', [])
        ->assertJsonPath('groups.products', [])
        ->assertJsonPath('groups.suppliers', [])
        ->assertJsonPath('groups.equipment', [])
        ->assertJsonPath('groups.work_orders', [])
        ->assertJsonPath('groups.quotations', [])
        ->assertJsonPath('groups.sales', [])
        ->assertJsonPath('groups.navigation', [])
        ->assertJsonPath('groups.actions', []);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('No se encontraron resultados', false);
});
