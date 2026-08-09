<?php

use App\Models\Client;
use App\Services\Catalog\ProductService;
use App\Services\Search\GlobalSearchService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\InventoryCatalogSeeder;
use Illuminate\Support\Facades\DB;

test('la topbar incluye busqueda global desktop y movil', function () {
    $user = makeUserWithPermissions(['dashboard.view']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('ar-topbar-search', false)
        ->assertSee('Buscar en AR Sistemas...', false)
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

test('consulta vacia o corta no ejecuta busquedas de entidades', function () {
    $admin = makeAdmin();
    test()->seed(CurrencySeeder::class);

    Client::query()->create(['name' => 'No Debe Aparecer', 'status' => 'active']);

    DB::enableQueryLog();
    $empty = app(GlobalSearchService::class)->search(' ', 5, $admin);
    $queriesAfterEmpty = count(DB::getQueryLog());
    expect($empty['clients'])->toBe([]);
    expect($queriesAfterEmpty)->toBe(0);

    DB::flushQueryLog();
    $short = app(GlobalSearchService::class)->search('a', 5, $admin);
    expect($short['clients'])->toBe([]);
    expect(count(DB::getQueryLog()))->toBe(0);

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
        ->assertSee('Full Page Search')
        ->assertSee(route('clients.show', Client::where('name', 'Full Page Search')->first()), false);
});
