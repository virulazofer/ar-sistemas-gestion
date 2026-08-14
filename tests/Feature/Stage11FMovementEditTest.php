<?php

use App\Enums\AccountType;
use App\Models\AuditLog;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\Movement;
use App\Models\MovementEditAudit;
use App\Models\User;
use App\Services\Finance\ChartStructuralMigrationService;
use App\Services\Finance\ExchangeRateService;
use App\Services\Finance\MovementService;
use App\Support\Money;
use Database\Seeders\CategorySeeder;
use Database\Seeders\ChartAccountSeeder;
use Database\Seeders\CurrencySeeder;
use Illuminate\Support\Facades\DB;

function seed11fMovements(): void
{
    test()->seed(CurrencySeeder::class);
    test()->seed(ChartAccountSeeder::class);
    test()->seed(CategorySeeder::class);
}

function makeFa11f(string $name, string $code = 'ARS'): FinancialAccount
{
    return FinancialAccount::query()->create([
        'name' => $name,
        'type' => AccountType::Cash->value,
        'currency_id' => Currency::where('code', $code)->firstOrFail()->id,
        'status' => 'active',
        'cached_balance' => 0,
    ]);
}

function leafChart(string $type = 'expense'): ChartAccount
{
    return ChartAccount::query()
        ->where('type', $type)
        ->whereNotNull('parent_id')
        ->where('is_active', true)
        ->orderBy('id')
        ->firstOrFail();
}

test('codigo MOV inmutable se genera y no se reutiliza ni edita', function () {
    $admin = makeAdmin();
    seed11fMovements();
    $this->actingAs($admin);
    app(ExchangeRateService::class)->storeManual('1500', 't');

    $fa = makeFa11f('Caja MOV');
    $svc = app(MovementService::class);

    $a = $svc->createSimple([
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '10',
        'chart_account_id' => leafChart()->id,
        'movement_date' => '2026-08-13',
        'description' => 'A',
    ]);
    $b = $svc->createSimple([
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '11',
        'chart_account_id' => leafChart()->id,
        'movement_date' => '2026-08-13',
        'description' => 'B',
    ]);

    expect($a->code)->toMatch('/^MOV-2026-\d{6}$/')
        ->and($b->code)->toMatch('/^MOV-2026-\d{6}$/')
        ->and($a->code)->not->toBe($b->code);

    $codeBefore = $a->code;
    $svc->update($a, [
        'movement_date' => '2026-08-13',
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '10',
        'description' => 'A editado',
        'chart_account_id' => leafChart()->id,
    ]);
    expect($a->fresh()->code)->toBe($codeBefore);

    $svc->void($a->fresh(), 'anular para probar no reuso');
    $c = $svc->createSimple([
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '12',
        'chart_account_id' => leafChart()->id,
        'movement_date' => '2026-08-13',
    ]);
    expect($c->code)->not->toBe($codeBefore)
        ->and($c->code)->not->toBe($b->code);
});

test('admin puede editar campos y operador sin permiso no', function () {
    $admin = makeAdmin();
    seed11fMovements();
    $this->actingAs($admin);
    app(ExchangeRateService::class)->storeManual('1500', 't');
    $fa = makeFa11f('Caja edit');
    $leaf = leafChart();

    $m = app(MovementService::class)->createSimple([
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '50',
        'chart_account_id' => $leaf->id,
        'description' => 'Nafta',
        'movement_date' => now()->toDateString(),
    ]);

    $this->get(route('movements.edit', $m))->assertOk()->assertSee('Editar movimiento')->assertSee($m->code);
    $this->put(route('movements.update', $m), [
        'movement_date' => now()->toDateString(),
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '50',
        'chart_account_id' => $leaf->id,
        'description' => 'Nafta Shell',
        'observations' => 'ticket',
    ])->assertRedirect(route('movements.show', $m));

    expect($m->fresh()->description)->toBe('Nafta Shell')
        ->and($m->fresh()->observations)->toBe('ticket');

    $viewer = makeUserWithPermissions(['movements.view']);
    $this->actingAs($viewer)
        ->get(route('movements.edit', $m))
        ->assertForbidden();
    $this->actingAs($viewer)
        ->put(route('movements.update', $m), [
            'movement_date' => now()->toDateString(),
            'type' => 'expense',
            'scope' => 'personal',
            'financial_account_id' => $fa->id,
            'amount' => '99',
            'description' => 'hack',
        ])
        ->assertForbidden();
});

test('motivo obligatorio solo en campos sensibles; fecha se audita sin motivo', function () {
    $admin = makeAdmin();
    seed11fMovements();
    $this->actingAs($admin);
    app(ExchangeRateService::class)->storeManual('1500', 't');
    $fa = makeFa11f('Caja sens');
    $svc = app(MovementService::class);
    $m = $svc->createSimple([
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '20',
        'chart_account_id' => leafChart()->id,
        'movement_date' => '2026-08-10',
        'description' => 'x',
    ]);

    expect(fn () => $svc->update($m, [
        'movement_date' => '2026-08-10',
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '25',
        'chart_account_id' => leafChart()->id,
    ]))->toThrow(InvalidArgumentException::class, 'motivo');

    $svc->update($m->fresh(), [
        'movement_date' => '2026-08-10',
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '25',
        'chart_account_id' => leafChart()->id,
        'edit_reason' => 'Corrección de importe',
    ]);
    expect((string) $m->fresh()->amount)->toBe('25.00');

    $svc->update($m->fresh(), [
        'movement_date' => '2026-08-11',
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '25',
        'chart_account_id' => leafChart()->id,
        'fx_mode' => 'keep',
        'description' => 'sin motivo ok',
    ]);

    $dateAudits = MovementEditAudit::query()
        ->where('movement_id', $m->id)
        ->where('field', 'movement_date')
        ->get();
    expect($dateAudits)->not->toBeEmpty();
});

test('auditoria delta ligera por campo sin bucle', function () {
    $admin = makeAdmin();
    seed11fMovements();
    $this->actingAs($admin);
    app(ExchangeRateService::class)->storeManual('1500', 't');
    $fa = makeFa11f('Caja aud');
    $svc = app(MovementService::class);
    $m = $svc->createSimple([
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '15',
        'chart_account_id' => leafChart()->id,
        'description' => 'antes',
        'movement_date' => now()->toDateString(),
    ]);

    $beforeLogs = AuditLog::query()->count();
    $svc->update($m, [
        'movement_date' => now()->toDateString(),
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '15',
        'chart_account_id' => leafChart()->id,
        'description' => 'despues',
        'observations' => 'obs',
    ]);

    $deltas = MovementEditAudit::query()->where('movement_id', $m->id)->get();
    expect($deltas->pluck('field')->all())->toContain('description', 'observations')
        ->and($deltas->every(fn ($d) => $d->movement_code === $m->code))->toBeTrue()
        ->and($deltas->every(fn ($d) => $d->entity_type === 'movement'))->toBeTrue();

    $afterLogs = AuditLog::query()->count();
    expect($afterLogs - $beforeLogs)->toBe(1);
    expect(AuditLog::query()->where('action', 'movement_updated')->exists())->toBeTrue();
});

test('cambio financiero bloquea si hay cobro vinculado', function () {
    $admin = makeAdmin();
    seed11fMovements();
    $this->actingAs($admin);
    app(ExchangeRateService::class)->storeManual('1500', 't');
    $fa = makeFa11f('Caja link');
    $svc = app(MovementService::class);
    $m = $svc->createSimple([
        'type' => 'income',
        'scope' => 'professional',
        'financial_account_id' => $fa->id,
        'amount' => '100',
        'chart_account_id' => leafChart('income')->id,
        'movement_date' => now()->toDateString(),
        'client_id' => Client::query()->create(['name' => 'Cli', 'status' => 'active'])->id,
    ]);

    DB::table('receipts')->insert([
        'number' => 'RC-TEST1',
        'sequence' => 999001,
        'client_id' => $m->client_id,
        'financial_account_id' => $fa->id,
        'financial_movement_id' => $m->id,
        'amount' => 100,
        'currency_code' => 'ARS',
        'received_on' => now()->toDateString(),
        'concept' => 'test',
        'status' => 'posted',
        'user_id' => $admin->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => $svc->update($m->fresh(), [
        'movement_date' => now()->toDateString(),
        'type' => 'income',
        'scope' => 'professional',
        'financial_account_id' => $fa->id,
        'amount' => '120',
        'chart_account_id' => leafChart('income')->id,
        'edit_reason' => 'intento',
    ]))->toThrow(InvalidArgumentException::class, 'vinculado');
});

test('cambio de fecha exige fx_mode y recalcular usa rateForDate', function () {
    $admin = makeAdmin();
    seed11fMovements();
    $this->actingAs($admin);
    $rates = app(ExchangeRateService::class);
    $rates->storeHistoricalImport('2026-03-01', '1000', '990');
    $rates->storeHistoricalImport('2026-03-05', '1100', '1090');

    $fa = makeFa11f('Caja fx');
    $svc = app(MovementService::class);
    $m = $svc->createSimple([
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '100',
        'chart_account_id' => leafChart()->id,
        'movement_date' => '2026-03-05',
        'description' => 'fx',
    ]);
    $frozen = (string) $m->exchange_rate_value;

    expect(fn () => $svc->update($m->fresh(), [
        'movement_date' => '2026-03-02',
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '100',
        'chart_account_id' => leafChart()->id,
    ]))->toThrow(InvalidArgumentException::class, 'Recalcular');

    $svc->update($m->fresh(), [
        'movement_date' => '2026-03-02',
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '100',
        'chart_account_id' => leafChart()->id,
        'fx_mode' => 'keep',
    ]);
    expect((string) $m->fresh()->exchange_rate_value)->toBe($frozen);

    // Nueva edición desde fecha con cotización distinta para forzar mismatch + recálculo
    $m2 = $svc->createSimple([
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '100',
        'chart_account_id' => leafChart()->id,
        'movement_date' => '2026-03-05',
        'description' => 'fx2',
    ]);
    $svc->update($m2->fresh(), [
        'movement_date' => '2026-03-02',
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '100',
        'chart_account_id' => leafChart()->id,
        'fx_mode' => 'recalculate',
    ]);
    expect(Money::compare((string) $m2->fresh()->exchange_rate_value, '1000', 6))->toBe(0);
});

test('listado columnas codigo primario sin Ver y detalle limpio', function () {
    $admin = makeAdmin();
    seed11fMovements();
    $this->actingAs($admin);
    app(ExchangeRateService::class)->storeManual('1500', 't');
    $fa = makeFa11f('Caja list');
    $m = app(MovementService::class)->createSimple([
        'type' => 'income',
        'scope' => 'professional',
        'financial_account_id' => $fa->id,
        'amount' => '80',
        'chart_account_id' => leafChart('income')->id,
        'description' => 'Abono',
        'movement_date' => now()->toDateString(),
    ]);

    $index = $this->get(route('movements.index'))->assertOk()->getContent();
    expect($index)->toContain('Código')
        ->and($index)->toContain('Fecha')
        ->and($index)->toContain('Descripción')
        ->and($index)->toContain('Cuenta contable')
        ->and($index)->toContain('Ámbito')
        ->and($index)->toContain('Cuenta financiera')
        ->and($index)->toContain('Importe')
        ->and($index)->toContain('Origen:')
        ->and($index)->toContain($m->code)
        ->and($index)->toContain('mov-row')
        ->and($index)->toContain('movementsGrid')
        ->and($index)->not->toContain('>Ver</a>');

    $show = $this->get(route('movements.show', $m))->assertOk()->getContent();
    expect($show)->toContain($m->code)
        ->and($show)->toContain('Editar')
        ->and($show)->toContain('Historial de cambios')
        ->and($show)->toContain('USD 1 = ARS')
        ->and($show)->not->toContain('id interno')
        ->and($show)->not->toContain('import_batch')
        ->and($show)->toContain('Anular movimiento');
});

test('carga rapida usa omnibox unico y exige cuenta contable', function () {
    $admin = makeAdmin();
    seed11fMovements();
    $this->actingAs($admin);
    app(ExchangeRateService::class)->storeManual('1500', 't');
    $fa = makeFa11f('Caja quick');

    $html = $this->get(route('movements.quick'))->assertOk()->getContent();
    expect($html)->toContain('chartAccountOmnibox')
        ->and($html)->toContain('Cuenta contable')
        ->and($html)->toContain('role="combobox"')
        ->and($html)->not->toContain('id="concept_q"');

    $this->post(route('movements.quick.store'), [
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '10',
        'movement_date' => now()->toDateString(),
        'description' => 'sin cuenta',
    ])->assertSessionHasErrors('chart_account_id');

    $leaf = leafChart();
    $this->post(route('movements.quick.store'), [
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'chart_account_id' => $leaf->id,
        'amount' => '10',
        'movement_date' => now()->toDateString(),
        'description' => 'con cuenta',
    ])->assertRedirect();

    expect(Movement::query()->where('description', 'con cuenta')->value('code'))->toMatch('/^MOV-/');
});

test('anular sigue separado y exige motivo', function () {
    $admin = makeAdmin();
    seed11fMovements();
    $this->actingAs($admin);
    app(ExchangeRateService::class)->storeManual('1500', 't');
    $fa = makeFa11f('Caja void');
    $m = app(MovementService::class)->createSimple([
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '5',
        'chart_account_id' => leafChart()->id,
        'movement_date' => now()->toDateString(),
    ]);

    $this->post(route('movements.void', $m), [])->assertSessionHasErrors('void_reason');
    $this->post(route('movements.void', $m), ['void_reason' => 'error de carga'])
        ->assertRedirect(route('movements.show', $m));
    expect($m->fresh()->status->value)->toBe('voided');
    expect(MovementEditAudit::query()->where('movement_id', $m->id)->where('field', 'status')->exists())->toBeTrue();
});

test('integridad PRE/POST sin ediciones impacto monetario cero', function () {
    $admin = makeAdmin();
    seed11fMovements();
    $this->actingAs($admin);
    app(ExchangeRateService::class)->storeManual('1500', 't');
    $fa = makeFa11f('Caja integ');
    app(MovementService::class)->createSimple([
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '33',
        'chart_account_id' => leafChart()->id,
        'movement_date' => now()->toDateString(),
    ]);

    $svc = app(ChartStructuralMigrationService::class);
    $pre = $svc->integritySnapshot();
    $post = $svc->integritySnapshot();
    $diff = $svc->compareIntegrity($pre, $post);
    expect($diff['ok'])->toBeTrue()->and($diff['mismatches'])->toBe([]);
});

test('fx editado en movimiento no muta tabla exchange_rates', function () {
    $admin = makeAdmin();
    seed11fMovements();
    $this->actingAs($admin);
    $rate = app(ExchangeRateService::class)->storeManual('1500', 't');
    $fa = makeFa11f('Caja fx2');
    $svc = app(MovementService::class);
    $m = $svc->createSimple([
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '10',
        'chart_account_id' => leafChart()->id,
        'movement_date' => now()->toDateString(),
        'exchange_rate_id' => $rate->id,
    ]);

    $before = (string) $rate->fresh()->rate;
    $svc->update($m, [
        'movement_date' => now()->toDateString(),
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '10',
        'chart_account_id' => leafChart()->id,
        'exchange_rate_value' => '1600',
        'edit_reason' => 'ajuste FX solo en movimiento',
    ]);

    expect((string) $rate->fresh()->rate)->toBe($before)
        ->and(Money::compare((string) $m->fresh()->exchange_rate_value, '1600', 6))->toBe(0)
        ->and(MovementEditAudit::query()->where('movement_id', $m->id)->where('field', 'exchange_rate_value')->exists())->toBeTrue();
});

test('admin inline edita ambito descripcion fecha cuenta e importe con motivo', function () {
    $admin = makeAdmin();
    seed11fMovements();
    $this->actingAs($admin);
    app(ExchangeRateService::class)->storeManual('1500', 't');
    $fa = makeFa11f('Caja inline');
    $fa2 = makeFa11f('Caja inline 2');
    $leaf = leafChart();
    $leaf2 = ChartAccount::query()
        ->where('type', 'expense')
        ->whereNotNull('parent_id')
        ->where('is_active', true)
        ->where('id', '!=', $leaf->id)
        ->orderBy('id')
        ->first() ?? $leaf;

    $m = app(MovementService::class)->createSimple([
        'type' => 'expense',
        'scope' => 'professional',
        'financial_account_id' => $fa->id,
        'amount' => '2500',
        'chart_account_id' => $leaf->id,
        'description' => 'Tuenti Gabi',
        'movement_date' => '2026-01-04',
    ]);
    $code = $m->code;

    $this->patchJson(route('movements.inline', $m), [
        'field' => 'scope',
        'value' => 'personal',
    ])->assertOk()->assertJsonPath('message', 'Guardado ✓');

    expect($m->fresh()->scope->value)->toBe('personal')
        ->and($m->fresh()->code)->toBe($code)
        ->and((string) $m->fresh()->amount)->toBe('2500.00')
        ->and((int) $m->fresh()->financial_account_id)->toBe($fa->id);

    expect(MovementEditAudit::query()->where('movement_id', $m->id)->where('field', 'scope')->exists())->toBeTrue();

    $this->patchJson(route('movements.inline', $m), [
        'field' => 'description',
        'value' => 'Tuenti Gabi corregido',
    ])->assertOk();

    $this->patchJson(route('movements.inline', $m), [
        'field' => 'chart_account_id',
        'value' => $leaf2->id,
    ])->assertOk();

    $this->patchJson(route('movements.inline', $m), [
        'field' => 'movement_date',
        'value' => '2026-01-05',
        'fx_mode' => 'keep',
    ])->assertOk();

    $this->patchJson(route('movements.inline', $m), [
        'field' => 'amount',
        'value' => '2600',
    ])->assertStatus(422);

    $this->patchJson(route('movements.inline', $m), [
        'field' => 'amount',
        'value' => '2600',
        'edit_reason' => 'ajuste importe smoke',
    ])->assertOk();

    $this->patchJson(route('movements.inline', $m), [
        'field' => 'financial_account_id',
        'value' => $fa2->id,
        'edit_reason' => 'cambio FA smoke',
    ])->assertOk();

    expect($m->fresh()->code)->toBe($code);
});

test('operador y consulta no editan inline ni completo', function () {
    $admin = makeAdmin();
    seed11fMovements();
    $this->actingAs($admin);
    app(ExchangeRateService::class)->storeManual('1500', 't');
    $fa = makeFa11f('Caja roles');
    $m = app(MovementService::class)->createSimple([
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '10',
        'chart_account_id' => leafChart()->id,
        'movement_date' => now()->toDateString(),
    ]);

    $operador = User::factory()->create();
    $operador->assignRole('Operador');
    $this->actingAs($operador)
        ->patchJson(route('movements.inline', $m), ['field' => 'scope', 'value' => 'professional'])
        ->assertForbidden();
    $this->actingAs($operador)->get(route('movements.edit', $m))->assertForbidden();
    $this->actingAs($operador)->get(route('movements.index'))->assertOk()->assertDontSee('canEdit: true');

    $consulta = User::factory()->create();
    $consulta->assignRole('Consulta');
    $this->actingAs($consulta)
        ->patchJson(route('movements.inline', $m), ['field' => 'description', 'value' => 'hack'])
        ->assertForbidden();
    $this->actingAs($consulta)->get(route('movements.edit', $m))->assertForbidden();
    $this->actingAs($consulta)->get(route('movements.show', $m))->assertOk();
});

test('ordenamiento server-side sobre dataset filtrado antes de paginar', function () {
    $admin = makeAdmin();
    seed11fMovements();
    $this->actingAs($admin);
    app(ExchangeRateService::class)->storeManual('1500', 't');
    $fa = makeFa11f('Caja sort');
    $svc = app(MovementService::class);
    $leaf = leafChart();

    foreach ([['A-alpha', '10'], ['C-gamma', '30'], ['B-beta', '20']] as [$desc, $amt]) {
        $svc->createSimple([
            'type' => 'expense',
            'scope' => 'personal',
            'financial_account_id' => $fa->id,
            'amount' => $amt,
            'chart_account_id' => $leaf->id,
            'description' => $desc,
            'movement_date' => '2026-02-01',
        ]);
    }

    // Forzar per_page=1 para verificar orden global antes de paginar
    $page1 = $this->get(route('movements.index', [
        'financial_account_id' => $fa->id,
        'sort' => 'amount',
        'dir' => 'desc',
        'per_page' => 1,
    ]))->assertOk()->getContent();
    expect($page1)->toContain('C-gamma')
        ->and($page1)->not->toContain('B-beta')
        ->and($page1)->toContain('Importe ↓');

    $page2 = $this->get(route('movements.index', [
        'financial_account_id' => $fa->id,
        'sort' => 'amount',
        'dir' => 'desc',
        'per_page' => 1,
        'page' => 2,
    ]))->assertOk()->getContent();
    expect($page2)->toContain('B-beta')
        ->and($page2)->toContain('sort=amount')
        ->and($page2)->toContain('dir=desc');

    $byDesc = $this->get(route('movements.index', [
        'financial_account_id' => $fa->id,
        'sort' => 'description',
        'dir' => 'asc',
        'per_page' => 1,
    ]))->assertOk()->getContent();
    expect($byDesc)->toContain('A-alpha');

    $byDateAsc = $this->get(route('movements.index', [
        'q' => 'gamma',
        'sort' => 'date',
        'dir' => 'asc',
    ]))->assertOk()->getContent();
    expect($byDateAsc)->toContain('C-gamma');
});

test('codigo MOV abre detalle y es buscable', function () {
    $admin = makeAdmin();
    seed11fMovements();
    $this->actingAs($admin);
    app(ExchangeRateService::class)->storeManual('1500', 't');
    $fa = makeFa11f('Caja code');
    $m = app(MovementService::class)->createSimple([
        'type' => 'expense',
        'scope' => 'personal',
        'financial_account_id' => $fa->id,
        'amount' => '7',
        'chart_account_id' => leafChart()->id,
        'description' => 'Buscable',
        'movement_date' => now()->toDateString(),
    ]);

    $html = $this->get(route('movements.index', ['q' => $m->code]))->assertOk()->getContent();
    expect($html)->toContain($m->code)
        ->and($html)->toContain(route('movements.show', $m, false));

    $this->get(route('movements.show', $m))->assertOk()->assertSee($m->code);
});
