<?php

use App\Enums\AccountType;
use App\Enums\ChartAccountType;
use App\Enums\MovementScope;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\ImputationRule;
use App\Models\Movement;
use App\Models\Subcategory;
use App\Models\User;
use App\Services\Finance\ChartAccountMappingService;
use App\Services\Finance\FinancialAccountChartLinker;
use App\Services\Finance\ImputationRuleService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\FinancialAccountSeeder;

beforeEach(function () {
    $this->seed(CurrencySeeder::class);
    $this->seed(FinancialAccountSeeder::class);
});

function postPhase1Leaf(string $code = '5.2.9', string $name = 'Gasto postfase'): ChartAccount
{
    $root = ChartAccount::query()->firstOrCreate(
        ['code' => '5'],
        ['name' => 'Gastos', 'type' => ChartAccountType::Expense, 'is_active' => true, 'sort_order' => 50]
    );
    $parent = ChartAccount::query()->firstOrCreate(
        ['code' => '5.2'],
        ['name' => 'Gastos varios', 'type' => ChartAccountType::Expense, 'parent_id' => $root->id, 'is_active' => true, 'sort_order' => 1]
    );

    return ChartAccount::query()->firstOrCreate(
        ['code' => $code],
        ['name' => $name, 'type' => ChartAccountType::Expense, 'parent_id' => $parent->id, 'is_active' => true, 'sort_order' => 1]
    );
}

function postPhase1Movement(array $attrs = []): Movement
{
    $account = FinancialAccount::query()->firstOrFail();

    return Movement::query()->create(array_merge([
        'movement_date' => now()->toDateString(),
        'movement_time' => now()->format('H:i:s'),
        'user_id' => User::factory()->create()->id,
        'scope' => MovementScope::Personal,
        'type' => MovementType::Expense,
        'financial_account_id' => $account->id,
        'currency_id' => $account->currency_id,
        'amount' => '50.00',
        'amount_ars' => '50.00',
        'amount_usd' => '0.05',
        'description' => 'Pendiente postfase',
        'status' => MovementStatus::Posted,
        'chart_account_id' => null,
        'category_id' => null,
    ], $attrs));
}

test('§10.1 pendientes empty state y badge solo con N>0', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);

    $this->get(route('chart-accounts.classify'))
        ->assertOk()
        ->assertSee('No hay movimientos pendientes de clasificación.')
        ->assertDontSee('Dry-run 11F-8')
        ->assertDontSee('Mapeo patrimonial');

    $nav = $this->get(route('chart-accounts.index'))->assertOk()->getContent();
    expect($nav)->toContain('Pendientes de clasificación')
        ->and($nav)->toContain('Ver plan')
        ->and($nav)->toContain('Asignación al plan')
        ->and($nav)->toContain('Reglas automáticas')
        ->and($nav)->not->toContain('Dry-run 11F-8');

    // Sin pendientes: no badge numérico al lado del ítem en sidebar (solo el label).
    expect($nav)->not->toMatch('/Pendientes de clasificación\s*\(\d+\)/');

    postPhase1Movement(['description' => 'Nuevo pendiente']);
    $withBadge = $this->get(route('chart-accounts.index'))->assertOk()->getContent();
    expect($withBadge)->toContain('Pendientes de clasificación (1)');
});

test('§10.2 cat con plan null no aparece en pendientes', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $cat = Category::query()->create(['name' => 'OK Cat', 'scope' => 'personal', 'is_active' => true, 'sort_order' => 1]);
    postPhase1Movement([
        'description' => 'Ya categorizado',
        'category_id' => $cat->id,
        'chart_account_id' => null,
    ]);

    expect(app(ChartAccountMappingService::class)->countMovementsWithoutAccount())->toBe(0);

    $this->get(route('chart-accounts.classify'))
        ->assertOk()
        ->assertSee('No hay movimientos pendientes de clasificación.')
        ->assertDontSee('Ya categorizado');
});

test('§10.3 asignación rename y preview no pisa manuales', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);

    $this->get(route('chart-accounts.mapping'))
        ->assertOk()
        ->assertSee('Asignación al plan de cuentas')
        ->assertDontSee('Mapeo patrimonial');

    $leafA = postPhase1Leaf('5.2.1', 'Manual A');
    $leafB = postPhase1Leaf('5.2.2', 'Regla B');
    $cat = Category::query()->create([
        'name' => 'Map Post',
        'scope' => 'personal',
        'chart_account_id' => $leafB->id,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $manual = postPhase1Movement([
        'description' => 'Manual confirmado',
        'category_id' => $cat->id,
        'chart_account_id' => $leafA->id,
    ]);
    $fillable = postPhase1Movement([
        'description' => 'Sin plan aún',
        'category_id' => $cat->id,
        'chart_account_id' => null,
    ]);

    $mapping = app(ChartAccountMappingService::class);
    $preview = $mapping->previewApplyToMovements();
    expect($preview['matched'])->toBeGreaterThanOrEqual(1)
        ->and($preview['manual'])->toBeGreaterThanOrEqual(1)
        ->and($preview['would_change'])->toBeGreaterThanOrEqual(1)
        ->and($preview)->toHaveKeys(['intact', 'overwrite_manual']);

    $result = $mapping->applyToMovements(false);
    expect((int) $manual->fresh()->chart_account_id)->toBe($leafA->id)
        ->and((int) $fillable->fresh()->chart_account_id)->toBe($leafB->id)
        ->and($result['manual_skipped'])->toBeGreaterThanOrEqual(1);
});

test('§10.4 reglas empty state y rename', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);

    ImputationRule::query()->delete();

    $this->get(route('imputation-rules.index'))
        ->assertOk()
        ->assertSee('Reglas de clasificación automática')
        ->assertSee('No hay reglas automáticas activas.')
        ->assertSee('El sistema funciona sin ellas');
});

test('§10.5 precedencia sub > cat > regla y manual no se pisa al aplicar', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);

    $subLeaf = postPhase1Leaf('5.2.3', 'Desde sub');
    $catLeaf = postPhase1Leaf('5.2.4', 'Desde cat');
    $ruleLeaf = postPhase1Leaf('5.2.5', 'Desde regla');
    $manualLeaf = postPhase1Leaf('5.2.6', 'Manual');

    $cat = Category::query()->create([
        'name' => 'Prec Cat',
        'scope' => 'personal',
        'chart_account_id' => $catLeaf->id,
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $sub = Subcategory::query()->create([
        'category_id' => $cat->id,
        'name' => 'Prec Sub',
        'chart_account_id' => $subLeaf->id,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $rules = app(ImputationRuleService::class);
    $rules->create([
        'name' => 'Spotify auto',
        'condition_type' => ImputationRule::TYPE_DESCRIPTION_CONTAINS,
        'condition_value' => 'Spotify',
        'target_chart_account_id' => $ruleLeaf->id,
        'priority' => 10,
        'is_active' => true,
    ]);

    $mapping = app(ChartAccountMappingService::class);
    expect($mapping->resolve($cat->id, $sub->id, 'expense', 'Spotify')['source'])->toBe('subcategory')
        ->and($mapping->resolve($cat->id, null, 'expense', 'Spotify')['source'])->toBe('category');

    $cat->update(['chart_account_id' => null]);
    expect($mapping->resolve($cat->id, null, 'expense', 'Factura Spotify')['source'])->toBe('imputation_rule');

    $m = postPhase1Movement([
        'description' => 'Spotify Premium',
        'category_id' => $cat->id,
        'chart_account_id' => $manualLeaf->id,
    ]);
    $preview = $rules->previewApply(ImputationRule::query()->firstOrFail());
    expect($preview['manual'])->toBeGreaterThanOrEqual(1);

    $rules->apply(ImputationRule::query()->firstOrFail(), [$m->id], true, false);
    expect((int) $m->fresh()->chart_account_id)->toBe($manualLeaf->id);
});

test('§10.6 FA create auto-link por tipo sin depender de asignación', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);

    foreach (['1.1.1', '1.1.2', '1.1.3', '2.1'] as $code) {
        ChartAccount::query()->firstOrCreate(
            ['code' => $code],
            [
                'name' => 'Loc '.$code,
                'type' => str_starts_with($code, '2') ? ChartAccountType::Liability : ChartAccountType::Asset,
                'is_active' => true,
                'sort_order' => 1,
            ]
        );
    }

    $currencyId = FinancialAccount::query()->value('currency_id');

    $this->post(route('accounts.store'), [
        'name' => 'Banco Postfase',
        'type' => AccountType::Bank->value,
        'currency_id' => $currencyId,
        'status' => 'active',
    ])->assertRedirect(route('accounts.index'));

    $fa = FinancialAccount::query()->where('name', 'Banco Postfase')->firstOrFail();
    $expected = ChartAccount::query()->where('code', '1.1.2')->value('id');
    expect((int) $fa->chart_account_id)->toBe((int) $expected);

    $other = ChartAccount::query()->where('code', '1.1.1')->firstOrFail();
    $this->put(route('accounts.update', $fa), [
        'name' => 'Banco Postfase',
        'type' => AccountType::Bank->value,
        'status' => 'active',
        'chart_account_id' => $other->id,
    ])->assertRedirect(route('accounts.index'));

    expect((int) $fa->fresh()->chart_account_id)->toBe((int) $other->id);
});

test('§10.7 navegación sin dry-run ni nombres internos', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);

    $html = $this->get(route('dashboard'))->assertOk()->getContent();
    expect($html)->toContain('Pendientes de clasificación')
        ->and($html)->toContain('Asignación al plan')
        ->and($html)->toContain('Reglas automáticas')
        ->and($html)->not->toContain('Dry-run 11F-8')
        ->and($html)->not->toContain('Mapeo patrimonial')
        ->and($html)->not->toContain('Clasificar movimientos')
        ->and($html)->not->toContain('Reglas de imputación');
});
