<?php

use App\Models\Client;
use App\Services\Search\GlobalSearchService;
use App\Support\Appearance;
use App\Support\UiLabels;
use App\Support\UiSemantics;
use Database\Seeders\CurrencySeeder;
use Illuminate\Support\Facades\DB;

test('busqueda desde 1 caracter incluye navegacion y entidades', function () {
    $admin = makeAdmin();
    test()->seed(CurrencySeeder::class);
    Client::query()->create(['name' => 'Alpha Uno', 'status' => 'active']);

    $this->actingAs($admin)
        ->getJson(route('search', ['q' => 'A', 'limit' => 5]))
        ->assertOk()
        ->assertJsonPath('q', 'A')
        ->assertJsonStructure([
            'groups' => [
                'navigation',
                'actions',
                'clients',
                'products',
            ],
            'meta' => ['has_more', 'totals', 'total'],
        ]);

    $result = app(GlobalSearchService::class)->search('A', 5, $admin);
    expect($result['navigation'])->not->toBeEmpty();
    expect(collect($result['clients'])->pluck('label')->all())->toContain('Alpha Uno');
});

test('admin con A o Ad encuentra navegacion admin y respeta permisos', function () {
    $admin = makeAdmin();
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $admin = $admin->fresh();
    $admin->load('roles', 'permissions');

    expect($admin->can('audit.view'))->toBeTrue();
    expect($admin->can('users.view'))->toBeTrue();

    $adminA = app(GlobalSearchService::class)->search('A', 25, $admin);
    $adminRoutes = collect($adminA['navigation'])->pluck('route')->all();
    expect($adminRoutes)->toContain('audit.index');
    expect($adminRoutes)->toContain('users.index');

    $adminAd = app(GlobalSearchService::class)->search('Ad', 25, $admin);
    $adRoutes = collect($adminAd['navigation'])->pluck('route')->all();
    expect($adRoutes)->toContain('audit.index');
    // keyword "admin" en Usuarios/Permisos
    expect(array_intersect($adRoutes, ['users.index', 'permissions.index']))->not->toBeEmpty();

    $limited = makeUserWithPermissions(['dashboard.view', 'products.view']);
    $limitedA = app(GlobalSearchService::class)->search('A', 25, $limited);
    $limitedRoutes = collect($limitedA['navigation'])->pluck('route')->all();
    expect($limitedRoutes)->not->toContain('audit.index');
    expect($limitedRoutes)->not->toContain('users.index');
    expect($limitedA['clients'])->toBe([]);
});

test('acciones aparecen filtradas por permiso', function () {
    $admin = makeAdmin();
    $viewer = makeUserWithPermissions(['dashboard.view', 'clients.view']);

    $adminResult = app(GlobalSearchService::class)->search('cliente', 10, $admin);
    expect(collect($adminResult['actions'])->pluck('label')->all())->toContain('Nuevo cliente');

    $viewerResult = app(GlobalSearchService::class)->search('cliente', 10, $viewer);
    expect(collect($viewerResult['actions'])->pluck('label')->all())->not->toContain('Nuevo cliente');
    expect(collect($viewerResult['navigation'])->pluck('label')->all())->toContain('Clientes');
});

test('ranking prioriza exact start word start y luego entidades', function () {
    $service = app(GlobalSearchService::class);

    expect($service->matchRank('Clientes', 'cli'))->toBe(GlobalSearchService::MATCH_EXACT_START);
    expect($service->matchRank('ABM Clientes', 'cli'))->toBe(GlobalSearchService::MATCH_WORD_START);
    expect($service->matchRank('ciclista', 'cli'))->toBe(GlobalSearchService::MATCH_PARTIAL);

    $admin = makeAdmin();
    test()->seed(CurrencySeeder::class);
    Client::query()->create(['name' => 'Cliente Ranking', 'status' => 'active']);

    $result = $service->search('cli', 5, $admin);
    $navFirst = $result['navigation'][0]['match'] ?? 99;
    expect($navFirst)->toBeLessThanOrEqual(GlobalSearchService::MATCH_WORD_START);
    expect($result['clients'])->not->toBeEmpty();
});

test('consulta vacia no busca entidades; un caracter si', function () {
    $admin = makeAdmin();
    test()->seed(CurrencySeeder::class);
    Client::query()->create(['name' => 'Solo Una Letra', 'status' => 'active']);

    DB::enableQueryLog();
    $empty = app(GlobalSearchService::class)->search(' ', 5, $admin);
    expect($empty['clients'])->toBe([]);
    expect(count(DB::getQueryLog()))->toBe(0);

    DB::flushQueryLog();
    $one = app(GlobalSearchService::class)->search('S', 5, $admin);
    expect(collect($one['clients'])->pluck('label')->all())->toContain('Solo Una Letra');
    expect(count(DB::getQueryLog()))->toBeGreaterThan(0);
});

test('command palette UI tiene teclado y ctrl k', function () {
    $user = makeUserWithPermissions(['dashboard.view']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('commandPalette', false)
        ->assertSee('Ctrl+K', false)
        ->assertSee('keydown.down.prevent', false)
        ->assertSee('NAVEGACIÓN', false)
        ->assertSee('Abrir búsqueda', false);
});

test('modo y paleta se persisten por usuario', function () {
    $user = makeUserWithPermissions(['dashboard.view']);

    $this->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('theme.update'), [
            'theme' => Appearance::MODE_DARK,
            'palette' => Appearance::PALETTE_AZUL,
        ])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->theme)->toBe(Appearance::MODE_DARK);
    expect($user->palette)->toBe(Appearance::PALETTE_AZUL);
    expect($user->prefersDarkTheme())->toBeTrue();
    expect($user->appearancePalette())->toBe(Appearance::PALETTE_AZUL);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-palette="azul"', false)
        ->assertSee('class="dark"', false)
        ->assertSee('Apariencia', false)
        ->assertSee('name="theme"', false)
        ->assertSee('name="palette"', false);
});

test('cada paleta es aceptada y no rompe tema light legacy', function () {
    $user = makeUserWithPermissions(['dashboard.view']);
    expect($user->theme)->toBe('light');

    foreach (Appearance::palettes() as $palette) {
        $this->actingAs($user)
            ->post(route('theme.update'), [
                'theme' => Appearance::MODE_LIGHT,
                'palette' => $palette,
            ])
            ->assertRedirect();

        expect($user->fresh()->palette)->toBe($palette);
        expect($user->fresh()->theme)->toBe('light');
    }
});

test('semantica CC y resultado independiente de skin', function () {
    expect(UiSemantics::tone('100.00', UiSemantics::MODE_CLIENT_CC))->toBe(UiSemantics::TONE_ATTENTION);
    expect(UiSemantics::tone('-50.00', UiSemantics::MODE_CLIENT_CC))->toBe(UiSemantics::TONE_FAVORABLE);
    expect(UiSemantics::kpiClass('100.00', UiSemantics::MODE_CLIENT_CC))->toBe('ar-kpi-negative');
    expect(UiSemantics::kpiClass('-50.00', UiSemantics::MODE_CLIENT_CC))->toBe('ar-kpi-positive');
    expect(UiSemantics::kpiClass('10.00', UiSemantics::MODE_RESULT))->toBe('ar-kpi-positive');
    expect(UiSemantics::kpiClass('-10.00', UiSemantics::MODE_RESULT))->toBe('ar-kpi-negative');

    $css = file_get_contents(resource_path('css/app.css'));
    expect($css)->toContain('--ar-kpi-negative');
    expect($css)->toContain('html[data-palette="azul"]');
    // Paletas no deben redefinir KPI semánticos
    expect($css)->not->toMatch('/data-palette="azul"[^}]*--ar-kpi-negative/s');
});

test('ayuda contextual en pantallas principales', function () {
    $admin = makeAdmin();

    $pages = [
        route('dashboard.operations') => 'Tablero operativo',
        route('dashboard.management') => 'Tablero de gestión',
        route('movements.index') => 'Movimientos',
        route('clients.index') => 'Clientes',
        route('clients.current-accounts') => 'Cuentas corrientes',
        route('suppliers.index') => 'Proveedores',
        route('products.index') => 'Productos',
        route('purchases.index') => 'Compras',
        route('stock.index') => 'Stock',
        route('equipment.index') => 'Equipos',
        route('work-orders.index') => 'Órdenes de trabajo',
        route('subscriptions.index') => 'Abonos',
        route('quotations.index') => 'Presupuestos',
        route('sales.index') => 'Ventas',
        route('reports.index') => 'Reportes',
        route('imports.index') => 'Importaciones',
        route('users.index') => 'Usuarios',
        route('permissions.index') => 'Matriz de permisos',
        route('audit.index') => 'Auditoría',
    ];

    foreach ($pages as $url => $needle) {
        $this->actingAs($admin)
            ->get($url)
            ->assertOk()
            ->assertSee('? Ayuda', false)
            ->assertSee($needle, false);
    }
});

test('etiquetas centralizadas y navegacion en espanol', function () {
    expect(UiLabels::get('income'))->toBe('Ingresos');
    expect(UiLabels::get('expense'))->toBe('Egresos');
    expect(config('finance.movement_types.income'))->toBe('Ingresos');

    $user = makeUserWithPermissions(['dashboard.view', 'stock.view']);
    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Tablero', false)
        ->assertSee('Tablero de gestión', false)
        ->assertDontSee('>Dashboard</span>', false);
});

test('documentacion 11f3 existe con auditoría y diseño', function () {
    $path = base_path('docs/stage11f3-functional-audit.md');
    expect(file_exists($path))->toBeTrue();
    $content = file_get_contents($path);
    expect($content)->toContain('Registrar cobro');
    expect($content)->toContain('Pago a cuenta');
    expect($content)->toContain('Regularizar CC');
    expect($content)->toContain('commercial_charges');
});

test('usuario legacy light sin palette usa actual', function () {
    expect(Appearance::normalizePalette(null))->toBe(Appearance::PALETTE_ACTUAL);
    expect(Appearance::normalizePalette(''))->toBe(Appearance::PALETTE_ACTUAL);
    expect(Appearance::normalizeMode('light'))->toBe(Appearance::MODE_LIGHT);
    expect(Appearance::normalizeMode('dark'))->toBe(Appearance::MODE_DARK);
});
