<?php

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
use App\Services\Finance\CategoryReclassificationService;
use App\Services\Finance\CategorySemanticsAnalyzer;
use App\Services\Finance\ChartAccountMappingService;
use App\Services\Finance\ImputationRuleService;
use App\Services\Finance\UnclassifiedMovementsService;
use App\Support\MailDiagnosis;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\FinancialAccountSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(CurrencySeeder::class);
    $this->seed(FinancialAccountSeeder::class);
});

function makeChartLeaf(string $code = '5.1.9', string $name = 'Prueba gasto'): ChartAccount
{
    $root = ChartAccount::query()->firstOrCreate(
        ['code' => '5'],
        ['name' => 'Gastos', 'type' => ChartAccountType::Expense, 'is_active' => true, 'sort_order' => 50]
    );
    $parent = ChartAccount::query()->firstOrCreate(
        ['code' => '5.1'],
        ['name' => 'Gastos personales', 'type' => ChartAccountType::Expense, 'parent_id' => $root->id, 'is_active' => true, 'sort_order' => 1]
    );

    return ChartAccount::query()->firstOrCreate(
        ['code' => $code],
        ['name' => $name, 'type' => ChartAccountType::Expense, 'parent_id' => $parent->id, 'is_active' => true, 'sort_order' => 1]
    );
}

function makeUnclassifiedMovement(array $attrs = []): Movement
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
        'amount' => '100.00',
        'amount_ars' => '100.00',
        'amount_usd' => '0.10',
        'description' => 'Spotify Premium',
        'status' => MovementStatus::Posted,
        'chart_account_id' => null,
    ], $attrs));
}

test('1 alerta pendientes enlaza a clasificar movimientos reales', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    makeUnclassifiedMovement(['description' => 'Telecentro']);
    makeUnclassifiedMovement(['description' => 'YouTube']);

    $count = app(ChartAccountMappingService::class)->countMovementsWithoutAccount();
    expect($count)->toBeGreaterThanOrEqual(2);

    $this->get(route('chart-accounts.index'))
        ->assertOk()
        ->assertSee((string) $count)
        ->assertSee(route('chart-accounts.classify', absolute: false), false);

    $this->get(route('chart-accounts.classify'))
        ->assertOk()
        ->assertSee('Clasificar movimientos')
        ->assertSee('Telecentro')
        ->assertSee('YouTube');
});

test('2 y 3 listado paginado muestra X de Y y no limita a 10 en silencio', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    foreach (range(1, 15) as $i) {
        makeUnclassifiedMovement(['description' => "Item {$i}"]);
    }

    $html = $this->get(route('chart-accounts.unclassified', ['per_page' => 10]))
        ->assertOk()
        ->assertSee('Mostrando')
        ->getContent();

    expect($html)->toContain('de')
        ->and($html)->toContain('15');

    $this->get(route('chart-accounts.unclassified', ['per_page' => 25, 'q' => 'Item']))
        ->assertOk()
        ->assertSee('Item 1')
        ->assertSee('Item 15');
});

test('4 asignacion individual', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $leaf = makeChartLeaf();
    $cat = Category::query()->create(['name' => 'Streaming', 'scope' => 'personal', 'is_active' => true, 'sort_order' => 1]);
    $m = makeUnclassifiedMovement(['description' => 'Spotify solo']);

    $this->post(route('chart-accounts.unclassified.classify', $m), [
        'category_id' => $cat->id,
        'chart_account_id' => $leaf->id,
    ])->assertRedirect();

    expect($m->fresh()->chart_account_id)->toBe($leaf->id)
        ->and($m->fresh()->category_id)->toBe($cat->id);
});

test('4b cat/sub sin cuenta contable no cuenta como pendiente', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $cat = Category::query()->create(['name' => 'Alimentación', 'scope' => 'personal', 'is_active' => true, 'sort_order' => 1]);
    $m = makeUnclassifiedMovement([
        'description' => 'Compra con cat',
        'category_id' => $cat->id,
        'chart_account_id' => null,
    ]);

    expect(app(ChartAccountMappingService::class)->countMovementsWithoutAccount())->toBe(0)
        ->and(app(UnclassifiedMovementsService::class)->progress()['missing_chart_optional'])->toBeGreaterThanOrEqual(1)
        ->and($m->fresh()->category_id)->toBe($cat->id);
});

test('5 y 6 asignacion masiva con preview', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $leaf = makeChartLeaf('5.1.8', 'Masivo');
    $ids = [];
    foreach (range(1, 3) as $i) {
        $ids[] = makeUnclassifiedMovement(['description' => "Bulk {$i}"])->id;
    }

    $this->post(route('chart-accounts.unclassified.bulk.preview'), [
        'movement_ids' => $ids,
        'chart_account_id' => $leaf->id,
    ])->assertRedirect(route('chart-accounts.unclassified'))
        ->assertSessionHas('unclassified_bulk_preview');

    $preview = session('unclassified_bulk_preview');
    expect($preview['would_affect'])->toBe(3);

    $this->withSession(['unclassified_bulk_preview' => $preview])
        ->post(route('chart-accounts.unclassified.bulk.apply'), ['confirm' => '1'])
        ->assertRedirect();

    expect(Movement::query()->whereIn('id', $ids)->whereNotNull('chart_account_id')->count())->toBe(3);
});

test('7 regla reutilizable y 8 override manual', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $leaf = makeChartLeaf('5.1.7', 'Regla');
    $svc = app(ImputationRuleService::class);
    $rule = $svc->create([
        'name' => 'Spotify',
        'condition_type' => ImputationRule::TYPE_DESCRIPTION_CONTAINS,
        'condition_value' => 'Spotify',
        'target_chart_account_id' => $leaf->id,
        'priority' => 10,
        'allow_manual_override' => true,
    ]);

    expect($rule->allow_manual_override)->toBeTrue();

    $resolved = app(ChartAccountMappingService::class)->resolve(null, null, 'expense', 'Factura Spotify marzo');
    expect($resolved['source'])->toBe('imputation_rule')
        ->and($resolved['chart_account_id'])->toBe($leaf->id);

    $other = makeChartLeaf('5.1.6', 'Manual');
    $m = makeUnclassifiedMovement(['description' => 'Spotify override']);
    app(UnclassifiedMovementsService::class)->classifyOne($m, null, null, $other->id);
    expect($m->fresh()->chart_account_id)->toBe($other->id);
});

test('9 cuenta financiera distinto de cuenta contable en UI', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    makeUnclassifiedMovement();

    $this->get(route('chart-accounts.unclassified'))
        ->assertOk()
        ->assertSee('Cuenta financiera')
        ->assertSee('Cuenta contable')
        ->assertDontSee('Defaults por tipo de movimiento');
});

test('10 reclasificacion con movimientos asociados', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $cat = Category::query()->create(['name' => 'Vieja', 'scope' => 'personal', 'is_active' => true, 'sort_order' => 1]);
    $m = makeUnclassifiedMovement(['category_id' => $cat->id, 'description' => 'con cat']);
    $svc = app(CategoryReclassificationService::class);
    $preview = $svc->previewRenameCategory($cat, 'Nueva');
    expect($preview['movements'])->toBeGreaterThanOrEqual(1);
    $svc->renameCategory($cat, 'Nueva');
    expect($cat->fresh()->name)->toBe('Nueva')
        ->and($m->fresh()->category_id)->toBe($cat->id);
});

test('11 fusion auditada', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $a = Category::query()->create(['name' => 'OrigenF', 'scope' => 'personal', 'is_active' => true, 'sort_order' => 1]);
    $b = Category::query()->create(['name' => 'DestinoF', 'scope' => 'personal', 'is_active' => true, 'sort_order' => 2]);
    makeUnclassifiedMovement(['category_id' => $a->id, 'description' => 'fusionable']);

    $result = app(CategoryReclassificationService::class)->mergeCategories($a, $b);
    expect($result['moved_movements'])->toBeGreaterThanOrEqual(1)
        ->and(Category::query()->find($a->id))->toBeNull()
        ->and(Movement::query()->where('category_id', $b->id)->count())->toBeGreaterThanOrEqual(1);
});

test('12 Super a Supermercado', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $alimentacion = Category::query()->create(['name' => 'Alimentación', 'scope' => 'personal', 'is_active' => true, 'sort_order' => 1]);
    $superSub = Subcategory::query()->create(['category_id' => $alimentacion->id, 'name' => 'Supermercado', 'is_active' => true, 'sort_order' => 1]);
    $superCat = Category::query()->create(['name' => 'Super', 'scope' => 'personal', 'is_active' => true, 'sort_order' => 2, 'excel_name' => 'Super']);
    makeUnclassifiedMovement(['category_id' => $superCat->id, 'description' => 'Compra Super']);

    $preview = app(CategoryReclassificationService::class)->normalizeSuperToSupermercado(false);
    expect($preview['found'])->toBeTrue()
        ->and($preview['preview']['movements'])->toBeGreaterThanOrEqual(1);

    $applied = app(CategoryReclassificationService::class)->normalizeSuperToSupermercado(true);
    expect($applied['applied']['moved_movements'])->toBeGreaterThanOrEqual(1)
        ->and(Movement::query()->where('subcategory_id', $superSub->id)->count())->toBeGreaterThanOrEqual(1);
});

test('13-16 ingresos profesionales Abonos Reparaciones Instalaciones Remotos', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $income = ChartAccount::query()->firstOrCreate(['code' => '4'], ['name' => 'Ingresos', 'type' => ChartAccountType::Income, 'is_active' => true, 'sort_order' => 40]);
    $prof = ChartAccount::query()->firstOrCreate(['code' => '4.2'], ['name' => 'Ingresos profesionales', 'type' => ChartAccountType::Income, 'parent_id' => $income->id, 'is_active' => true, 'sort_order' => 2]);

    foreach (['Abonos', 'Reparaciones', 'Instalaciones', 'Remotos'] as $name) {
        Category::query()->create(['name' => $name, 'scope' => 'professional', 'is_active' => true, 'sort_order' => 1, 'chart_account_id' => null]);
    }

    $report = app(CategoryReclassificationService::class)->ensureProfessionalIncomeMappings(true);
    $actions = collect($report)->pluck('action');
    expect($actions->intersect(['created_category', 'folded_legacy_category', 'created_sub', 'mapped', 'ok', 'ok_sub'])->isNotEmpty())->toBeTrue();

    $servicios = Category::query()->whereRaw('LOWER(name) = ?', ['servicios profesionales'])->first();
    expect($servicios)->not->toBeNull()
        ->and($servicios->chart_account_id)->toBe($prof->id);

    foreach (['Abonos', 'Reparaciones', 'Instalaciones', 'Remotos'] as $name) {
        $sub = Subcategory::query()
            ->where('category_id', $servicios->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
        expect($sub)->not->toBeNull()
            ->and($sub->chart_account_id)->toBe($prof->id);
    }
});

test('17 Ventas preserva circuito comercial (solo clasificacion economica)', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $income = ChartAccount::query()->firstOrCreate(['code' => '4'], ['name' => 'Ingresos', 'type' => ChartAccountType::Income, 'is_active' => true, 'sort_order' => 40]);
    $prof = ChartAccount::query()->firstOrCreate(['code' => '4.2'], ['name' => 'Ingresos profesionales', 'type' => ChartAccountType::Income, 'parent_id' => $income->id, 'is_active' => true, 'sort_order' => 2]);
    Category::query()->create(['name' => 'Ventas', 'scope' => 'professional', 'is_active' => true, 'sort_order' => 1]);

    $report = app(CategoryReclassificationService::class)->ensureProfessionalIncomeMappings(true);
    $ventas = collect($report)->firstWhere('name', 'Ventas');
    expect($ventas['note'])->toContain('circuito comercial')
        ->and(Category::query()->where('name', 'Ventas')->value('chart_account_id'))->toBe($prof->id);
});

test('18 permisos administrativos', function () {
    seedPermissions();
    $user = User::factory()->create();
    $user->givePermissionTo(['categories.view']);
    $this->actingAs($user);
    $this->get(route('chart-accounts.unclassified'))->assertOk();
    $this->post(route('chart-accounts.unclassified.bulk.preview'), [
        'movement_ids' => [1],
        'chart_account_id' => 1,
    ])->assertForbidden();
});

test('19 configuracion sin labels ingleses conocidos', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $this->seed(\Database\Seeders\SettingsSeeder::class);
    $html = $this->get(route('settings.edit'))->assertOk()->getContent();
    expect(mb_strtolower($html))->not->toContain('>settings<')
        ->and($html)->toContain('Configuración')
        ->and($html)->toContain('Tema predeterminado');
});

test('20 reportes sin encabezados ingleses conocidos', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $html = $this->get(route('reports.show', 'finance-movements'))->assertOk()->getContent();
    expect($html)->toContain('Fecha')
        ->and($html)->toContain('Importe')
        ->and($html)->toContain('Descripción')
        ->and($html)->not->toContain('<th>amount</th>')
        ->and($html)->not->toContain('<th>income</th>');
});

test('21 plan de cuentas traducido', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    ChartAccount::query()->firstOrCreate(['code' => '4'], ['name' => 'INGRESOS', 'type' => ChartAccountType::Income, 'is_active' => true, 'is_protected' => true, 'sort_order' => 40]);
    $html = $this->get(route('chart-accounts.index'))->assertOk()->getContent();
    expect($html)->toContain('INGRESOS')
        ->and($html)->toContain('Cuenta contable')
        ->and($html)->not->toContain('Defaults por tipo');
});

test('22 password reset', function () {
    Notification::fake();
    $user = User::factory()->create();
    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();
    Notification::assertSentTo($user, ResetPassword::class);

    $admin = makeAdmin();
    $this->actingAs($admin)
        ->post(route('users.send-reset', $user))
        ->assertRedirect();
    Notification::assertSentTo($user, ResetPassword::class);
});

test('23 email verification preparada', function () {
    Notification::fake();
    $admin = makeAdmin();
    $this->actingAs($admin);
    $this->post(route('users.store'), [
        'name' => 'Nuevo',
        'username' => 'nuevo_user',
        'email' => 'nuevo@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'status' => 'active',
        'theme' => 'light',
        'roles' => ['Administrador'],
    ])->assertRedirect();

    $created = User::query()->where('email', 'nuevo@example.com')->first();
    expect($created)->not->toBeNull()
        ->and($created->email_verified_at)->toBeNull()
        ->and($created)->toBeInstanceOf(\Illuminate\Contracts\Auth\MustVerifyEmail::class);

    Notification::assertSentTo($created, VerifyEmail::class);
    expect(MailDiagnosis::message())->toBeString();
});

test('24 administrador existente preservado verificado', function () {
    $admin = makeAdmin(['email' => 'admin_existente@example.com', 'email_verified_at' => now()]);
    expect($admin->hasVerifiedEmail())->toBeTrue();
    // Migración de verificación no debe dejar admins bloqueados: middleware verified no envuelve rutas core.
    $this->actingAs($admin)->get(route('chart-accounts.index'))->assertOk();
});

test('analisis semantico no aplica cambios', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    Category::query()->create(['name' => 'Miranda', 'scope' => 'personal', 'is_active' => true, 'sort_order' => 1]);
    makeUnclassifiedMovement(['description' => 'Miranda cuota', 'category_id' => Category::query()->where('name', 'Miranda')->value('id')]);

    $before = Movement::query()->count();
    $analysis = app(CategorySemanticsAnalyzer::class)->analyzeAmbiguous();
    expect($analysis)->toHaveKeys(['Comida', 'Auto', 'Miranda', 'MYU'])
        ->and($analysis['Miranda']['auto_migrate'])->toBeFalse()
        ->and(Movement::query()->count())->toBe($before);

    $this->get(route('chart-accounts.semantics'))->assertOk()->assertSee('Miranda');
});

test('progreso operativo se actualiza con categoría', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $leaf = makeChartLeaf('5.1.5', 'Prog');
    $cat = Category::query()->create(['name' => 'Servicios', 'scope' => 'personal', 'is_active' => true, 'sort_order' => 1]);
    $m = makeUnclassifiedMovement(['description' => 'progreso']);
    $svc = app(UnclassifiedMovementsService::class);
    $before = $svc->progress();
    $svc->classifyOne($m, $cat->id, null, $leaf->id);
    $after = $svc->progress();
    expect($after['classified'])->toBe($before['classified'] + 1)
        ->and($after['pending'])->toBe($before['pending'] - 1);
});

test('dry-run estructural no aplica masa y exporta ambiguos', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    app(\App\Services\Finance\ApprovedTaxonomyService::class)->ensureCanonical(true);
    $super = Category::query()->create(['name' => 'Super', 'scope' => 'personal', 'is_active' => true, 'sort_order' => 9]);
    makeUnclassifiedMovement(['description' => 'Compra Super', 'category_id' => $super->id]);
    makeUnclassifiedMovement(['description' => 'YPF nafta Auto 24', 'category_id' => Category::query()->create(['name' => 'Auto', 'scope' => 'personal', 'is_active' => true, 'sort_order' => 8])->id]);
    makeUnclassifiedMovement(['description' => 'Auto sin detalle claro']);

    $beforeCats = Movement::query()->pluck('category_id', 'id')->all();
    $report = app(\App\Services\Finance\StructuralReclassificationPlanner::class)->dryRun(false);
    expect($report['applied'])->toBeFalse()
        ->and(collect($report['groups'])->pluck('grupo')->all())->toContain('Super', 'Auto', 'Remotos')
        ->and(Movement::query()->pluck('category_id', 'id')->all())->toBe($beforeCats);

    $paths = app(\App\Services\Finance\StructuralReclassificationPlanner::class)->writeAmbiguousExports($report);
    expect($paths['count'])->toBeGreaterThanOrEqual(1)
        ->and(\Illuminate\Support\Facades\Storage::disk('local')->exists($paths['csv']))->toBeTrue();

    $this->post(route('chart-accounts.dry-run'))
        ->assertRedirect(route('chart-accounts.semantics'))
        ->assertSessionHas('classification_dry_run');
});

test('apply ALTA incluye Lavado C3 y no toca ambito ni importes', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    app(\App\Services\Finance\ApprovedTaxonomyService::class)->ensureCanonical(true);

    $super = Category::query()->create(['name' => 'Super', 'scope' => 'personal', 'is_active' => true, 'sort_order' => 9]);
    $auto = Category::query()->create(['name' => 'Auto', 'scope' => 'personal', 'is_active' => true, 'sort_order' => 8]);
    $comida = Category::query()->create(['name' => 'Comida', 'scope' => 'personal', 'is_active' => true, 'sort_order' => 7]);

    $mSuper = makeUnclassifiedMovement([
        'description' => 'Compra Super',
        'category_id' => $super->id,
        'scope' => \App\Enums\MovementScope::Personal,
        'amount_ars' => '150.00',
        'chart_account_id' => null,
    ]);
    $mLavado = makeUnclassifiedMovement([
        'description' => 'Lavado C3',
        'category_id' => $auto->id,
        'scope' => \App\Enums\MovementScope::Personal,
        'amount_ars' => '8000.00',
        'chart_account_id' => null,
    ]);
    $mComida = makeUnclassifiedMovement([
        'description' => 'Comida oficina',
        'category_id' => $comida->id,
        'scope' => \App\Enums\MovementScope::Professional,
        'amount_ars' => '99.50',
        'chart_account_id' => null,
    ]);
    $faBefore = $mLavado->financial_account_id;

    $summary = app(\App\Services\Finance\StructuralReclassificationPlanner::class)->applyAlta(true);
    expect($summary['applied'])->toBeTrue()
        ->and($summary['updated_total'])->toBeGreaterThanOrEqual(3)
        ->and($summary['batch_id'])->toStartWith('11f8-alta-');

    $alimentacion = Category::query()->whereRaw('LOWER(name) = ?', ['alimentación'])->orWhereRaw('LOWER(name) = ?', ['alimentacion'])->first();
    $automotor = Category::query()->whereRaw('LOWER(name) = ?', ['automotor'])->first();
    $lavadoSub = Subcategory::query()->where('category_id', $automotor?->id)->where('name', 'Lavado/Limpieza')->first();

    expect($mSuper->fresh()->category_id)->toBe($alimentacion?->id)
        ->and($mComida->fresh()->category_id)->toBe($alimentacion?->id)
        ->and($mLavado->fresh()->category_id)->toBe($automotor?->id)
        ->and($mLavado->fresh()->subcategory_id)->toBe($lavadoSub?->id)
        ->and($mLavado->fresh()->scope->value)->toBe('personal')
        ->and((string) $mLavado->fresh()->amount_ars)->toBe('8000.00')
        ->and($mLavado->fresh()->financial_account_id)->toBe($faBefore)
        ->and($mLavado->fresh()->chart_account_id)->toBeNull()
        ->and($mComida->fresh()->scope->value)->toBe('professional');

    $dry = app(\App\Services\Finance\StructuralReclassificationPlanner::class)->dryRun(false);
    $autoGroup = collect($dry['groups'])->firstWhere('grupo', 'Auto');
    expect((int) ($autoGroup['ambiguos'] ?? 0))->toBe(0);
});

test('reporte clasificacion operativa por naturaleza cat sub ambito', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $cat = Category::query()->create(['name' => 'Alimentación', 'scope' => 'personal', 'is_active' => true, 'sort_order' => 1]);
    $sub = Subcategory::query()->create(['category_id' => $cat->id, 'name' => 'Supermercado', 'is_active' => true, 'sort_order' => 1]);
    makeUnclassifiedMovement([
        'description' => 'Disco',
        'category_id' => $cat->id,
        'subcategory_id' => $sub->id,
        'chart_account_id' => null,
    ]);

    $this->get(route('reports.show', 'operational-classification'))
        ->assertOk()
        ->assertSee('EGRESO')
        ->assertSee('Alimentación')
        ->assertSee('Supermercado');
});
