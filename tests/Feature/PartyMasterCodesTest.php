<?php

use App\Models\Client;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Clients\ClientCodeService;
use App\Services\Suppliers\SupplierCodeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function seedPartyCodes(): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole(Role::findByName('Administrador'));

    return $user;
}

function makeClientNamed(string $name, ?int $code = null): Client
{
    $code ??= app(ClientCodeService::class)->allocateNext();

    return Client::query()->create([
        'code' => $code,
        'name' => $name,
        'status' => 'active',
        'party_type' => 'particular',
        'dni' => (string) random_int(20000000, 39999999),
        'tax_condition' => 'consumidor_final',
    ]);
}

function makeSupplierNamed(string $name, ?int $code = null, string $cuit = '30-11111111-8'): Supplier
{
    $code ??= app(SupplierCodeService::class)->allocateNext();

    return Supplier::query()->create([
        'code' => $code,
        'name' => $name,
        'status' => 'active',
        'party_type' => 'empresa',
        'business_name' => $name.' SA',
        'cuit' => $cuit,
        'tax_condition' => 'responsable_inscripto',
    ]);
}

test('formato visible Cxxx / Pxxx y búsqueda por código', function () {
    $admin = seedPartyCodes();
    $this->actingAs($admin);

    $client = makeClientNamed('Lidercar Test', 1);
    $supplier = makeSupplierNamed('Proveedor Test', 1);

    expect($client->codeFormatted())->toBe('C001');
    expect($supplier->codeFormatted())->toBe('P001');
    expect($client->labelWithCode())->toStartWith('C001');

    $this->get(route('clients.index', ['q' => 'C001']))->assertOk()->assertSee('Lidercar Test');
    $this->get(route('suppliers.index', ['q' => 'P001']))->assertOk()->assertSee('Proveedor Test');
    $this->get(route('clients.index'))->assertOk()->assertDontSee('>Ver</a>');
    $this->get(route('suppliers.index'))->assertOk()->assertDontSee('>Ver</a>');
});

test('secuencia siguiente no reutiliza huecos ni max existente', function () {
    seedPartyCodes();
    makeClientNamed('A', 1);
    makeClientNamed('B', 17);
    Setting::setValue('clients.next_code', 2, 'int');
    expect(app(ClientCodeService::class)->allocateNext())->toBe(18);

    makeSupplierNamed('S1', 1);
    Setting::setValue('suppliers.next_code', 1, 'int');
    expect(app(SupplierCodeService::class)->allocateNext())->toBe(2);
});

test('código inmutable sin permiso edit_code', function () {
    seedPartyCodes();
    $operador = User::factory()->create(['status' => 'active']);
    $operador->assignRole(Role::findByName('Operador'));

    $client = makeClientNamed('Inmutable', 3);
    $supplier = makeSupplierNamed('Prov Inmutable', 3, '30-22222222-9');

    $this->actingAs($operador)
        ->put(route('clients.update', $client), [
            'name' => 'Inmutable',
            'party_type' => 'particular',
            'dni' => $client->dni,
            'tax_condition' => 'consumidor_final',
            'status' => 'active',
            'code' => 99,
        ]);

    expect($client->fresh()->code)->toBe(3);

    $this->actingAs($operador)
        ->put(route('suppliers.update', $supplier), [
            'name' => 'Prov Inmutable',
            'party_type' => 'empresa',
            'business_name' => $supplier->business_name,
            'cuit' => $supplier->cuit,
            'tax_condition' => 'responsable_inscripto',
            'status' => 'active',
            'code' => 99,
        ]);

    expect($supplier->fresh()->code)->toBe(3);
});

test('remap maestro: matches inequívocos, detener ambiguos, impacto monetario 0', function () {
    seedPartyCodes();

    $lidercar = makeClientNamed('Lidercar', 50);
    $cintas = makeClientNamed('Cintas', 51);
    $daasa = makeClientNamed('DAASA', 52);
    $kaishaSrl = makeClientNamed('Kaisha SRL', 53);
    $kaisha = makeClientNamed('Kaisha', 54);
    $marinkovic = makeClientNamed('Marinkovic', 55);
    $ecogo = makeClientNamed('Ecogo', 56);
    $oogway = makeClientNamed('Oogway', 57);
    $extra = makeClientNamed('Andrea Balduzzi', 58);

    $supplier = makeSupplierNamed('Eduardo', 9);

    // Simula impacto monetario previo (solo referencia de integridad, sin tocar montos en remap).
    DB::table('client_ledger_entries')->insert([
        'client_id' => $daasa->id,
        'currency_id' => DB::table('currencies')->insertGetId([
            'code' => 'ARS',
            'name' => 'Peso',
            'symbol' => '$',
            'decimal_places' => 2,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]),
        'type' => 'charge',
        'amount' => 1000,
        'signed_amount' => -1000,
        'amount_ars' => 1000,
        'amount_usd' => 0,
        'entry_date' => now()->toDateString(),
        'entry_time' => '12:00:00',
        'user_id' => User::query()->first()->id,
        'description' => 'test',
        'status' => 'posted',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $beforeLedger = (string) DB::table('client_ledger_entries')->sum('signed_amount');
    $beforeClientIds = Client::query()->orderBy('id')->pluck('id')->all();
    $beforeSupplierIds = Supplier::query()->orderBy('id')->pluck('id')->all();
    $ledgerClientId = (int) DB::table('client_ledger_entries')->value('client_id');

    $this->artisan('parties:remap-master-codes', ['--apply' => true])->assertSuccessful();

    expect($lidercar->fresh()->code)->toBe(1)
        ->and($cintas->fresh()->code)->toBe(2)
        ->and($daasa->fresh()->code)->toBe(3)
        ->and($oogway->fresh()->code)->toBe(16);

    $overflow = collect([
        $kaishaSrl->fresh()->code,
        $kaisha->fresh()->code,
        $marinkovic->fresh()->code,
        $ecogo->fresh()->code,
        $extra->fresh()->code,
    ])->sort()->values();

    expect($overflow->min())->toBeGreaterThanOrEqual(18)
        ->and($overflow->unique()->count())->toBe(5)
        ->and((string) DB::table('client_ledger_entries')->sum('signed_amount'))->toBe($beforeLedger)
        ->and(Client::query()->orderBy('id')->pluck('id')->all())->toBe($beforeClientIds)
        ->and(Supplier::query()->orderBy('id')->pluck('id')->all())->toBe($beforeSupplierIds)
        ->and((int) DB::table('client_ledger_entries')->value('client_id'))->toBe($ledgerClientId)
        ->and($supplier->fresh()->code)->toBe(1)
        ->and($supplier->fresh()->codeFormatted())->toBe('P001');
});

test('integridad: remap Nuts a C006 sin cambiar id de CC', function () {
    seedPartyCodes();
    $client = makeClientNamed('Nuts', 4);

    $currencyId = DB::table('currencies')->insertGetId([
        'code' => 'USD',
        'name' => 'Dólar',
        'symbol' => 'U$S',
        'decimal_places' => 2,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $entryId = DB::table('client_ledger_entries')->insertGetId([
        'client_id' => $client->id,
        'currency_id' => $currencyId,
        'type' => 'charge',
        'amount' => 50,
        'signed_amount' => -50,
        'amount_ars' => 0,
        'amount_usd' => 50,
        'entry_date' => now()->toDateString(),
        'entry_time' => '12:00:00',
        'user_id' => User::query()->first()->id,
        'description' => 'cc',
        'status' => 'posted',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('parties:remap-master-codes', ['--apply' => true])->assertSuccessful();

    expect($client->fresh()->code)->toBe(6)
        ->and((int) DB::table('client_ledger_entries')->where('id', $entryId)->value('client_id'))->toBe($client->id);
});
