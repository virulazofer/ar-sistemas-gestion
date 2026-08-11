<?php

use App\Enums\ClientLedgerType;
use App\Enums\CommercialChargeType;
use App\Enums\DocumentalStatus;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Models\Client;
use App\Models\ClientLedgerEntry;
use App\Models\CommercialCharge;
use App\Models\ImportBatch;
use App\Models\Movement;
use App\Models\Receipt;
use App\Models\ReceiptApplication;
use App\Services\Clients\ClientCcTimelineService;
use App\Services\Clients\ClientCodeService;
use App\Services\Clients\ClientLedgerService;
use App\Services\Commercial\CommercialChargeService;
use App\Services\Commercial\ReceiptService;
use App\Services\Imports\Historical\DaasaPost11F3ReconciliationService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ExchangeRateSeeder;
use Database\Seeders\FinancialAccountSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function seedDaasaHotfix(): void
{
    test()->seed(CurrencySeeder::class);
    test()->seed(FinancialAccountSeeder::class);
    test()->seed(ExchangeRateSeeder::class);
}

function makeDaasaClient(): Client
{
    $client = Client::query()->create([
        'code' => 3,
        'name' => 'DAASA',
        'status' => 'active',
        'party_type' => 'empresa',
        'business_name' => 'Drogueria Atlantida',
        'cuit' => '30-70851687-9',
        'tax_condition' => 'responsable_inscripto',
    ]);

    // En staging id=12 permanente; en tests aceptamos el id asignado pero code=3.
    return $client;
}

function seed11eBatch(): ImportBatch
{
    $admin = \App\Models\User::query()->orderBy('id')->first();

    return ImportBatch::query()->create([
        'uuid' => DaasaPost11F3ReconciliationService::IMPORT_BATCH_UUID,
        'entity_type' => 'movements',
        'importer_kind' => 'historical_movements',
        'status' => 'confirmed',
        'file_hash' => 'ba745fe67851842c57fd52d5e213d4463b392cfedb88b40b9205076b927f3072',
        'original_filename' => 'GASTOS MENSUALES 2026.xlsx',
        'confirmed_at' => now(),
        'user_id' => $admin?->id ?? 1,
    ]);
}

test('hotfix formula classification counts before repair', function () {
    $svc = app(DaasaPost11F3ReconciliationService::class);
    $c = $svc->classificationCounts();
    expect($c['resolved_unequivocal_cells'])->toBe(18)
        ->and($c['unresolvable_cells'])->toBe(0)
        ->and($c['candidate_rows'])->toBe(27);
});

test('hotfix repair idempotent and no duplicate movement for hugo', function () {
    $admin = makeAdmin();
    seedDaasaHotfix();
    $this->actingAs($admin);
    $client = makeDaasaClient();
    seed11eBatch();

    $account = \App\Models\FinancialAccount::query()->where('name', 'like', '%Patagonia%')->first()
        ?? \App\Models\FinancialAccount::query()->firstOrFail();

    // Existing 11E cobro 466 (income + ledger payment) without charge 404
    $mov = Movement::query()->create([
        'movement_date' => '2026-05-01',
        'movement_time' => '10:00:00',
        'user_id' => $admin->id,
        'scope' => 'professional',
        'type' => MovementType::Income,
        'financial_account_id' => $account->id,
        'currency_id' => $account->currency_id,
        'amount' => '1308450.00',
        'amount_ars' => '1308450.00',
        'description' => 'Hugo Ferreyra',
        'status' => MovementStatus::Posted,
        'client_id' => $client->id,
        'source_sheet' => 'Movimientos',
        'source_row' => 466,
        'external_id' => 'hist:test:Movimientos:466:income',
        'import_batch_id' => ImportBatch::query()->first()->id,
    ]);

    $pay = app(ClientLedgerService::class)->createEntry(
        $client,
        ClientLedgerType::Payment,
        [
            'currency_code' => 'ARS',
            'amount' => '1308450.00',
            'entry_date' => '2026-05-01',
            'description' => 'Hugo Ferreyra',
            'financial_movement_id' => $mov->id,
        ],
        sign: 1,
        requiresFinance: false,
    );

    // Opening
    app(CommercialChargeService::class)->create([
        'client_id' => $client->id,
        'charge_type' => CommercialChargeType::Other->value,
        'concept' => 'DAASA CC Inicial',
        'amount' => '50000.00',
        'currency_code' => 'ARS',
        'charged_on' => '2026-01-01',
        'documental_status' => DocumentalStatus::NotRequired->value,
    ]);

    // Cable charge+payment already present for backfill path optional
    $svc = app(DaasaPost11F3ReconciliationService::class);
    $r1 = $svc->run(false);
    $r2 = $svc->run(false);

    expect($r1['after_balance_ars'])->toBe($r2['after_balance_ars']);

    $charges404 = CommercialCharge::query()
        ->where('client_id', $client->id)
        ->where('notes', 'like', '%row:404:cc_in%')
        ->count();
    expect($charges404)->toBe(1);

    $incomes466 = Movement::query()->where('client_id', $client->id)->where('source_row', 466)->where('status', 'posted')->count();
    expect($incomes466)->toBe(1); // no second Patagonia income

    $apps = ReceiptApplication::query()
        ->where('status', 'posted')
        ->whereHas('charge', fn ($q) => $q->where('notes', 'like', '%row:404:cc_in%'))
        ->count();
    expect($apps)->toBe(1);

    expect($pay->fresh()->receipt_id)->not->toBeNull();
});

test('hotfix unified timeline shows legacy charge cobro and abono income without UI dup', function () {
    $admin = makeAdmin();
    seedDaasaHotfix();
    $this->actingAs($admin);
    $client = makeDaasaClient();

    $account = \App\Models\FinancialAccount::query()->firstOrFail();

    $charge = app(CommercialChargeService::class)->create([
        'client_id' => $client->id,
        'charge_type' => CommercialChargeType::Sale->value,
        'concept' => 'DAASA Cable de red',
        'amount' => '464000.00',
        'currency_code' => 'ARS',
        'charged_on' => '2026-05-19',
        'notes' => 'Backfill 11F-3 desde ledger #34',
    ]);

    $mov = Movement::query()->create([
        'movement_date' => '2026-01-09',
        'movement_time' => '10:00:00',
        'user_id' => $admin->id,
        'scope' => 'professional',
        'type' => MovementType::Income,
        'financial_account_id' => $account->id,
        'currency_id' => $account->currency_id,
        'amount' => '690677.00',
        'amount_ars' => '690677.00',
        'description' => 'DAASA - Abono mantenimiento.',
        'status' => MovementStatus::Posted,
        'client_id' => $client->id,
        'source_row' => 43,
    ]);

    $timeline = app(ClientCcTimelineService::class)->buildTimeline($client);
    $keys = $timeline->pluck('dedupe_key');
    expect($keys->unique()->count())->toBe($keys->count());

    expect($timeline->contains(fn ($r) => str_contains((string) $r['description'], 'Cable de red')))->toBeTrue();
    expect($timeline->contains(fn ($r) => str_contains((string) $r['description'], 'Abono mantenimiento')))->toBeTrue();

    // Abono income does not double-reduce CC
    $abono = $timeline->first(fn ($r) => ($r['related']['movement_id'] ?? null) === $mov->id);
    expect($abono['affects_cc'])->toBeFalse();

    $page = $this->get(route('clients.show', $client));
    $page->assertOk()
        ->assertSee('movimientos')
        ->assertSee('Abono mantenimiento')
        ->assertSee('Cable de red');

    $this->get(route('clients.show', ['client' => $client, 'cc_filter' => 'abonos']))
        ->assertOk()
        ->assertSee('Abono mantenimiento');
});

test('hotfix attachHistorical does not create duplicate finance movement', function () {
    $admin = makeAdmin();
    seedDaasaHotfix();
    $this->actingAs($admin);
    $client = makeClient11F3('Hist');
    $account = arsAccount();

    $charge = app(CommercialChargeService::class)->create([
        'client_id' => $client->id,
        'charge_type' => CommercialChargeType::Sale->value,
        'concept' => 'Hist charge',
        'amount' => '1000.00',
        'currency_code' => 'ARS',
    ]);

    $before = Movement::query()->count();
    $ledger = app(ClientLedgerService::class)->createEntry(
        $client,
        ClientLedgerType::Payment,
        ['currency_code' => 'ARS', 'amount' => '1000.00', 'entry_date' => now()->toDateString(), 'description' => 'Hist pay'],
        sign: 1,
        requiresFinance: false,
    );

    app(ReceiptService::class)->attachHistorical([
        'client_id' => $client->id,
        'amount' => '1000.00',
        'received_on' => now()->toDateString(),
        'concept' => 'Hist pay',
        'financial_account_id' => null,
        'client_ledger_entry_id' => $ledger->id,
        'create_ledger_payment' => false,
        'applications' => [['commercial_charge_id' => $charge->id, 'amount' => '1000.00']],
    ]);

    expect(Movement::query()->count())->toBe($before);
    expect($charge->fresh()->status->value)->toBe('collected');
});

test('hotfix pagination shows total count label', function () {
    $admin = makeAdmin();
    seedDaasaHotfix();
    $this->actingAs($admin);
    $client = makeClient11F3('Pager');

    for ($i = 0; $i < 12; $i++) {
        app(CommercialChargeService::class)->create([
            'client_id' => $client->id,
            'charge_type' => CommercialChargeType::Other->value,
            'concept' => "Cargo {$i}",
            'amount' => '10.00',
            'currency_code' => 'ARS',
            'charged_on' => now()->subDays($i)->toDateString(),
        ]);
    }

    $this->get(route('clients.show', $client))
        ->assertOk()
        ->assertSee('12 movimientos');
});
