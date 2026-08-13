<?php

use App\Enums\ChartAccountType;
use App\Enums\PartyType;
use App\Enums\TaxCondition;
use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\User;
use App\Services\Finance\ChartAccountMappingService;
use App\Services\Finance\ExchangeRateService;
use Carbon\Carbon;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ExchangeRateSeeder;
use Database\Seeders\FinancialAccountSeeder;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->seed(CurrencySeeder::class);
});

function makeOperador(array $attributes = []): User
{
    seedPermissions();
    $user = User::factory()->create($attributes);
    $user->assignRole('Operador');

    return $user;
}

test('mapeo 500 root cause: Setting cache no guarda Eloquent (incomplete class)', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);

    Setting::setValue('chart_mapping.type_defaults', ['income' => 1, 'expense' => 2], 'json');

    // Simula cache legacy: modelo Eloquent / incomplete class → HTTP 500 en typeDefaults().
    $broken = new class
    {
        public string $class = 'App\\Models\\Setting';
    };
    // Force object into cache (same class of bug as __PHP_Incomplete_Class).
    Cache::put('setting.chart_mapping.type_defaults', $broken, 300);

    $raw = Setting::getValue('chart_mapping.type_defaults', []);
    expect($raw)->toBeArray()
        ->and($raw['income'] ?? null)->toBe(1);

    // Segunda lectura usa payload serializable.
    $cached = Cache::get('setting.chart_mapping.type_defaults');
    expect($cached)->toBeArray()
        ->and($cached)->toHaveKey('type')
        ->and($cached['type'])->toBe('json');

    $this->get(route('chart-accounts.mapping'))->assertOk()->assertSee('Asignación al plan de cuentas');
});

test('regularizar CC: Operador 403, Administrador OK', function () {
    $this->seed(FinancialAccountSeeder::class);
    $this->seed(ExchangeRateSeeder::class);

    $client = Client::query()->create(['name' => 'CC Reg', 'status' => 'active']);
    $payload = [
        'currency_code' => 'ARS',
        'amount' => '10',
        'sign' => '-1',
        'reason' => 'Corrección de prueba',
        'regularization_kind' => 'other',
        'entry_date' => now()->toDateString(),
    ];

    $operador = makeOperador();
    expect($operador->can('clients.regularize'))->toBeFalse();
    $this->actingAs($operador)
        ->post(route('clients.regularize', $client), $payload)
        ->assertForbidden();

    // Defensa: aunque alguien tenga el permiso sin rol Admin → 403.
    $fake = makeUserWithPermissions(['clients.view', 'clients.regularize']);
    $this->actingAs($fake)
        ->post(route('clients.regularize', $client), $payload)
        ->assertForbidden();

    $admin = makeAdmin();
    $this->actingAs($admin)
        ->post(route('clients.regularize', $client), $payload)
        ->assertRedirect(route('clients.show', $client));
});

test('cotizaciones chart From/To exacto sin truncar en abril', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $svc = app(ExchangeRateService::class);

    // ~220 días diarios ene–ago: el viejo limit(120) cortaba ~abril.
    $cursor = Carbon::parse('2026-01-01');
    $end = Carbon::parse('2026-08-10');
    $i = 0;
    while ($cursor->lte($end)) {
        $sell = (string) (1000 + $i);
        $svc->storeHistoricalImport($cursor->toDateString(), $sell, (string) (990 + $i));
        $cursor->addDay();
        $i++;
    }

    $points = $svc->chartPoints(Carbon::parse('2026-01-01'), Carbon::parse('2026-08-10'));
    expect(count($points))->toBeGreaterThan(120);

    $last = end($points);
    expect($last['date'])->toBe('10/08/2026');

    $janOnly = $svc->chartPoints(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));
    expect(count($janOnly))->toBe(31)
        ->and($janOnly[0]['date'])->toBe('01/01/2026')
        ->and($janOnly[30]['date'])->toBe('31/01/2026');

    // Huecos OK: no inventa días.
    ExchangeRate::query()->whereDate('rate_at', '2026-01-15')->delete();
    $withGap = $svc->chartPoints(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));
    expect(count($withGap))->toBe(30);
});

test('categorias create no pide plan; mapeo asigna cuenta', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);

    $leaf = ChartAccount::query()->create([
        'code' => '5.99', 'name' => 'Gastos test', 'type' => ChartAccountType::Expense, 'is_active' => true, 'sort_order' => 1,
    ]);

    $html = $this->get(route('categories.index', ['legacy' => 1]))->assertOk()->getContent();
    expect($html)->not->toContain('name="chart_account_id"')
        ->and($html)->toContain('Asignación al plan');

    $this->post(route('categories.store'), [
        'name' => 'Super',
        'scope' => 'personal',
        'chart_account_id' => $leaf->id, // ignorado / no validado
    ])->assertRedirect();

    $cat = Category::query()->where('name', 'Super')->firstOrFail();
    expect($cat->chart_account_id)->toBeNull();

    app(ChartAccountMappingService::class)->mapCategory($cat, $leaf->id);
    expect($cat->fresh()->chart_account_id)->toBe($leaf->id);
});

test('plan de cuentas: raiz permitida y guarda ciclo', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);

    $root = ChartAccount::query()->create([
        'code' => '5', 'name' => 'EGRESOS', 'type' => ChartAccountType::Expense, 'is_active' => true, 'is_protected' => true, 'sort_order' => 1,
    ]);
    $child = ChartAccount::query()->create([
        'code' => '5.1', 'name' => 'Alimentación', 'type' => ChartAccountType::Expense, 'parent_id' => $root->id, 'is_active' => true, 'sort_order' => 1,
    ]);
    $grand = ChartAccount::query()->create([
        'code' => '5.1.1', 'name' => 'Comidas', 'type' => ChartAccountType::Expense, 'parent_id' => $child->id, 'is_active' => true, 'sort_order' => 1,
    ]);

    // 11F: no se pueden promover cuentas a raíz (solo 5 raíces estructurales).
    $this->put(route('chart-accounts.update', $child), [
        'code' => '5.1',
        'name' => 'Alimentación',
        'type' => ChartAccountType::Expense->value,
        'parent_id' => '',
        'sort_order' => 1,
        'is_active' => 1,
    ])->assertSessionHasErrors('parent_id');
    expect($child->fresh()->parent_id)->toBe($root->id);

    // Ciclo: padre = nieto.
    $this->put(route('chart-accounts.update', $child), [
        'code' => '5.1',
        'name' => 'Alimentación',
        'type' => ChartAccountType::Expense->value,
        'parent_id' => $grand->id,
        'sort_order' => 1,
        'is_active' => 1,
    ])->assertSessionHasErrors('parent_id');
});

test('proveedores: CUIT obligatorio, sin DNI', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);

    $valid = '30712345671';

    $this->post(route('suppliers.store'), [
        'name' => 'Prov CUIT',
        'party_type' => PartyType::Particular->value,
        'cuit' => $valid,
        'dni' => '12345678',
        'tax_condition' => TaxCondition::ConsumidorFinal->value,
        'status' => 'active',
    ])->assertRedirect();

    $supplier = \App\Models\Supplier::query()->where('name', 'Prov CUIT')->firstOrFail();
    expect($supplier->cuit)->not->toBeNull()
        ->and($supplier->dni)->toBeNull();

    $this->post(route('suppliers.store'), [
        'name' => 'Sin CUIT',
        'party_type' => PartyType::Particular->value,
        'tax_condition' => TaxCondition::ConsumidorFinal->value,
        'status' => 'active',
    ])->assertSessionHasErrors('cuit');
});

test('legacy pago cliente redirige a cobro formal', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $client = Client::query()->create(['name' => 'Cobro Formal', 'status' => 'active']);

    $this->get(route('clients.ledger.payment.create', $client))
        ->assertRedirect(route('receipts.create', ['client_id' => $client->id]));
});

test('cliente show: Nueva operación y Regularizar solo Admin', function () {
    $client = Client::query()->create(['name' => 'UX Client', 'status' => 'active']);

    $admin = makeAdmin();
    $this->actingAs($admin)
        ->get(route('clients.show', $client))
        ->assertOk()
        ->assertSee('Nueva operación')
        ->assertSee('Regularizar CC');

    $operador = makeOperador();
    $this->actingAs($operador)
        ->get(route('clients.show', $client))
        ->assertOk()
        ->assertDontSee('Regularizar CC (solo Administradores');
});
