<?php

use App\Enums\AccountType;
use App\Enums\ChartAccountType;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\FinancialAccount;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Subcategory;
use App\Rules\Luhn;
use App\Services\Finance\MovementService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ExchangeRateSeeder;
use Database\Seeders\FinancialAccountSeeder;

beforeEach(function () {
    $this->seed(CurrencySeeder::class);
});

test('11F-6 plan cuentas elimina con reasignacion y auditoria', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);

    $keep = ChartAccount::query()->create([
        'code' => '9.9',
        'name' => 'Destino',
        'type' => ChartAccountType::Expense,
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $doomed = ChartAccount::query()->create([
        'code' => '9.8',
        'name' => 'A borrar',
        'type' => ChartAccountType::Expense,
        'is_active' => true,
        'sort_order' => 2,
    ]);
    $child = ChartAccount::query()->create([
        'code' => '9.8.1',
        'name' => 'Hija',
        'type' => ChartAccountType::Expense,
        'parent_id' => $doomed->id,
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $cat = Category::query()->create([
        'name' => 'Cat doomed',
        'scope' => 'professional',
        'chart_account_id' => $doomed->id,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $this->delete(route('chart-accounts.destroy', $doomed), [
        'disposition' => 'reassign',
        'reassign_to' => $keep->id,
        'children_action' => 'reparent',
    ])->assertRedirect(route('chart-accounts.index'));

    expect(ChartAccount::query()->find($doomed->id))->toBeNull()
        ->and($cat->fresh()->chart_account_id)->toBe($keep->id)
        ->and($child->fresh()->parent_id)->toBe($keep->id)
        ->and(AuditLog::query()->where('action', 'chart_account_deleted')->exists())->toBeTrue();
});

test('11F-6 inventario sin Stock primario y productos con Precio y unidades', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);

    $nav = $this->get(route('products.index'))->assertOk();
    $nav->assertSee('Ver todas las unidades')
        ->assertSee('Precio')
        ->assertSee('sale_price')
        ->assertDontSee('>Stock / Unidades<', false);

    $product = Product::query()->create([
        'sku' => 'U-1',
        'name' => 'Con unidades',
        'type' => 'physical',
        'status' => 'active',
        'tracks_units' => true,
        'qty_on_hand' => 0,
        'qty_reserved' => 0,
        'reference_cost_usd' => 99.5,
    ]);

    $this->get(route('products.show', $product))
        ->assertOk()
        ->assertSee('tracks_units')
        ->assertSee('Costo referencia')
        ->assertSee('no es precio de venta')
        ->assertDontSee('99,5000</td>', false);

    $this->get(route('stock.units'))->assertOk()->assertSee('Unidades de inventario');
});

test('11F-6 cuentas financieras sin columna Estado y Luhn', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $currencyId = \App\Models\Currency::query()->where('code', 'ARS')->value('id');

    expect(Luhn::passes('4111111111111111'))->toBeTrue()
        ->and(Luhn::passes('4111111111111112'))->toBeFalse()
        ->and(Luhn::last4('4111 1111 1111 1111'))->toBe('1111');

    $this->get(route('accounts.index'))
        ->assertOk()
        ->assertSee('Ver inactivas')
        ->assertDontSee('<th>Estado</th>', false);

    $this->post(route('accounts.store'), [
        'name' => 'Card Luhn',
        'type' => AccountType::CreditCard->value,
        'currency_id' => $currencyId,
        'status' => 'active',
        'card_number' => '4111 1111 1111 1111',
        'card_brand' => 'Visa',
        'card_holder' => 'Test',
        'card_expiry_month' => 12,
        'card_expiry_year' => 2030,
        'cvv' => '123',
    ])->assertStatus(422);

    $this->post(route('accounts.store'), [
        'name' => 'Card Luhn',
        'type' => AccountType::CreditCard->value,
        'currency_id' => $currencyId,
        'status' => 'active',
        'card_number' => '4111 1111 1111 1111',
        'card_brand' => 'Visa',
        'card_holder' => 'Test',
        'card_expiry_month' => 12,
        'card_expiry_year' => 2030,
    ])->assertRedirect();

    $card = FinancialAccount::query()->where('name', 'Card Luhn')->firstOrFail();
    expect($card->card_last4)->toBe('1111')
        ->and($card->getAttributes())->not->toHaveKey('cvv');
});

test('11F-6 apertura CC preview labels y abonos empty CHARGE', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);

    $client = Client::query()->create(['name' => 'Apertura 11F6', 'status' => 'active']);
    $this->get(route('clients.show', $client))
        ->assertOk()
        ->assertSee('Establecer saldo de apertura');

    $this->get(route('clients.ledger.opening.create', $client))
        ->assertOk()
        ->assertSee('A cobrar')
        ->assertSee('A favor')
        ->assertSee('Vista previa');

    $this->get(route('subscriptions.index'))
        ->assertOk()
        ->assertSee('CHARGE')
        ->assertSee('Crear primer abono')
        ->assertSee('no un ingreso');
});

test('11F-6 categorias analitica filtros y paginacion 30', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $this->seed(FinancialAccountSeeder::class);
    $this->seed(ExchangeRateSeeder::class);

    $cat = Category::query()->create([
        'name' => 'Analitica 11F6',
        'scope' => 'professional',
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $sub = Subcategory::query()->create([
        'category_id' => $cat->id,
        'name' => 'Sub 11F6',
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $account = FinancialAccount::query()->firstOrFail();

    for ($i = 0; $i < 12; $i++) {
        app(MovementService::class)->createSimple([
            'type' => 'expense',
            'scope' => 'professional',
            'financial_account_id' => $account->id,
            'category_id' => $cat->id,
            'subcategory_id' => $sub->id,
            'amount' => '10',
            'description' => "mov filtro {$i}",
            'movement_date' => now()->toDateString(),
        ]);
    }

    $this->get(route('categories.show', [
        'category' => $cat,
        'q' => 'mov filtro',
        'scope' => 'professional',
        'financial_account_id' => $account->id,
    ]))
        ->assertOk()
        ->assertSee('Cuenta financiera')
        ->assertSee('Buscar')
        ->assertSee('mov filtro 0');

    expect(Movement::query()->where('category_id', $cat->id)->count())->toBe(12);
});
