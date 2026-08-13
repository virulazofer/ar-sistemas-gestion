<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Supplier;
use App\Services\Clients\ClientCodeService;
use App\Services\Suppliers\SupplierCodeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RemapPartyMasterCodesCommand extends Command
{
    protected $signature = 'parties:remap-master-codes
                            {--apply : Aplicar cambios (sin esto solo dry-run)}
                            {--json : Salida JSON}';

    protected $description = 'Remapea códigos visibles de clientes (Cxxx) según lista maestra y asigna Pxxx a proveedores';

    /**
     * Lista maestra exacta: código => nombre canónico.
     * Alias adicionales solo para matching inequívoco en staging.
     *
     * @var array<int, array{name: string, aliases: list<string>}>
     */
    private const MASTER_CLIENTS = [
        1 => ['name' => 'Lidercar', 'aliases' => ['Lidercar']],
        2 => ['name' => 'Cintas industriales', 'aliases' => ['Cintas industriales', 'Cintas']],
        3 => ['name' => 'DAASA', 'aliases' => ['DAASA']],
        4 => ['name' => 'Serpa', 'aliases' => ['Serpa']],
        5 => ['name' => 'Kaisha', 'aliases' => ['Kaisha']],
        6 => ['name' => 'Nuts', 'aliases' => ['Nuts']],
        7 => ['name' => 'Offramp', 'aliases' => ['Offramp']],
        8 => ['name' => 'Eurolighting', 'aliases' => ['Eurolighting']],
        9 => ['name' => 'Marinkovic - ECOGO', 'aliases' => ['Marinkovic - ECOGO', 'Marinkovic', 'Ecogo', 'ECOGO']],
        10 => ['name' => 'Estudio PQR', 'aliases' => ['Estudio PQR']],
        11 => ['name' => 'Game Ever', 'aliases' => ['Game Ever']],
        12 => ['name' => 'Contartese', 'aliases' => ['Contartese']],
        13 => ['name' => 'Marcon', 'aliases' => ['Marcon']],
        14 => ['name' => 'Grafex', 'aliases' => ['Grafex']],
        15 => ['name' => 'Quantum Zero', 'aliases' => ['Quantum Zero']],
        16 => ['name' => 'OOGWAY - Pignataro', 'aliases' => ['OOGWAY - Pignataro', 'OOGWAY', 'Oogway']],
        17 => ['name' => 'SuperAcción', 'aliases' => ['SuperAcción', 'Superaccion', 'Super Acción']],
    ];

    public function __construct(
        private readonly ClientCodeService $clientCodes,
        private readonly SupplierCodeService $supplierCodes,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $clientPlan = $this->planClients();
        $supplierPlan = $this->planSuppliers();

        $report = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'clients_before' => $clientPlan['before'],
            'clients_to_apply' => $clientPlan['apply'],
            'clients_detener' => $clientPlan['detener'],
            'clients_missing' => $clientPlan['missing'],
            'clients_extra' => $clientPlan['extra'],
            'suppliers' => $supplierPlan,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->printHuman($report);
        }

        if (! $apply) {
            $this->warn('Dry-run: no se modificó la base. Use --apply para aplicar matches inequívocos.');

            return self::SUCCESS;
        }

        $integrityBefore = $this->monetaryFingerprint();

        DB::transaction(function () use ($clientPlan, $supplierPlan) {
            // Fase 1: liberar códigos de clientes que van a cambiar (evitar unique collisions).
            $touchIds = collect($clientPlan['apply'])->pluck('id')
                ->merge(collect($clientPlan['extra'])->pluck('id'))
                ->merge(collect($clientPlan['detener'])->pluck('id'))
                ->unique()
                ->values();

            $temp = 900000;
            foreach ($touchIds as $id) {
                Client::query()->where('id', $id)->update(['code' => $temp++]);
            }

            foreach ($clientPlan['apply'] as $row) {
                Client::query()->where('id', $row['id'])->update(['code' => $row['new_code']]);
            }

            // Extras y DETENER: códigos nuevos desde max(maestra aplicada, códigos ya puestos)+1
            $next = max(
                (int) collect($clientPlan['apply'])->max('new_code'),
                17
            ) + 1;

            $overflow = collect($clientPlan['detener'])
                ->merge($clientPlan['extra'])
                ->sortBy('id')
                ->values();

            foreach ($overflow as $row) {
                Client::query()->where('id', $row['id'])->update(['code' => $next]);
                $next++;
            }

            $this->clientCodes->syncNextFromMax();

            foreach ($supplierPlan as $row) {
                if ((int) $row['current_code'] !== (int) $row['new_code'] || $row['current_code'] === null) {
                    Supplier::query()->where('id', $row['id'])->update(['code' => $row['new_code']]);
                }
            }
            $this->supplierCodes->syncNextFromMax();
        });

        $integrityAfter = $this->monetaryFingerprint();
        if ($integrityBefore !== $integrityAfter) {
            $this->error('INTEGRIDAD MONETARIA ALTERADA — abortar revisión inmediata.');

            return self::FAILURE;
        }

        $after = Client::query()->orderBy('code')->get(['id', 'code', 'name']);
        $this->info('Aplicado. Clientes tras remap:');
        foreach ($after as $c) {
            $this->line(sprintf(
                '  %s | id=%d | %s',
                $this->clientCodes->format((int) $c->code),
                $c->id,
                $c->name
            ));
        }
        $this->info('Próximo cliente: '.$this->clientCodes->format((int) \App\Models\Setting::getValue('clients.next_code', 0)));
        $this->info('Próximo proveedor: '.$this->supplierCodes->format((int) \App\Models\Setting::getValue('suppliers.next_code', 0)));
        $this->info('Integridad monetaria: OK (sin cambios).');

        return self::SUCCESS;
    }

    /**
     * @return array{
     *   before: list<array<string,mixed>>,
     *   apply: list<array<string,mixed>>,
     *   detener: list<array<string,mixed>>,
     *   missing: list<array<string,mixed>>,
     *   extra: list<array<string,mixed>>
     * }
     */
    private function planClients(): array
    {
        $clients = Client::query()->orderBy('id')->get();
        $claimed = [];
        $byMaster = [];

        foreach (self::MASTER_CLIENTS as $code => $meta) {
            $matches = [];
            foreach ($clients as $client) {
                if ($this->clientMatchesMaster($client, $meta['aliases'])) {
                    $matches[] = $client;
                }
            }
            $byMaster[$code] = $matches;
        }

        $apply = [];
        $detener = [];
        $missing = [];
        $matchedIds = [];

        foreach (self::MASTER_CLIENTS as $code => $meta) {
            $matches = $byMaster[$code];
            if (count($matches) === 0) {
                $missing[] = [
                    'new_code' => $code,
                    'new_formatted' => $this->clientCodes->format($code),
                    'master_name' => $meta['name'],
                ];
                continue;
            }
            if (count($matches) > 1) {
                foreach ($matches as $client) {
                    $detener[] = [
                        'id' => $client->id,
                        'name' => $client->name,
                        'current_code' => $client->code,
                        'current_formatted' => $this->clientCodes->format((int) $client->code),
                        'new_code' => $code,
                        'new_formatted' => $this->clientCodes->format($code),
                        'master_name' => $meta['name'],
                        'match' => 'DETENER (ambiguo: '.count($matches).' candidatos)',
                    ];
                    $matchedIds[$client->id] = true;
                }
                continue;
            }

            $client = $matches[0];
            // Si el mismo cliente ya fue claimado por otro código maestro → DETENER
            if (isset($claimed[$client->id])) {
                $detener[] = [
                    'id' => $client->id,
                    'name' => $client->name,
                    'current_code' => $client->code,
                    'current_formatted' => $this->clientCodes->format((int) $client->code),
                    'new_code' => $code,
                    'new_formatted' => $this->clientCodes->format($code),
                    'master_name' => $meta['name'],
                    'match' => 'DETENER (cliente ya asignado a C'.sprintf('%03d', $claimed[$client->id]).')',
                ];
                $matchedIds[$client->id] = true;
                continue;
            }

            $claimed[$client->id] = $code;
            $matchedIds[$client->id] = true;
            $apply[] = [
                'id' => $client->id,
                'name' => $client->name,
                'current_code' => $client->code,
                'current_formatted' => $this->clientCodes->format((int) $client->code),
                'new_code' => $code,
                'new_formatted' => $this->clientCodes->format($code),
                'master_name' => $meta['name'],
                'match' => 'OK',
            ];
        }

        // Clientes que matchearon en DETENER por ambigüedad: quitar de apply si aparecieron
        $detenerIds = collect($detener)->pluck('id')->all();
        $apply = array_values(array_filter($apply, fn ($r) => ! in_array($r['id'], $detenerIds, true)));

        // Si un cliente está en DETENER, no debe quedar en apply
        $extra = [];
        foreach ($clients as $client) {
            if (isset($matchedIds[$client->id])) {
                continue;
            }
            $extra[] = [
                'id' => $client->id,
                'name' => $client->name,
                'current_code' => $client->code,
                'current_formatted' => $this->clientCodes->format((int) $client->code),
                'match' => 'EXTRA (fuera de lista maestra → C018+)',
            ];
        }

        $before = [];
        foreach ($clients as $client) {
            $row = collect($apply)->firstWhere('id', $client->id)
                ?? collect($detener)->firstWhere('id', $client->id);
            $before[] = [
                'id' => $client->id,
                'name' => $client->name,
                'current_formatted' => $this->clientCodes->format((int) $client->code),
                'new_formatted' => $row['new_formatted'] ?? '(C018+ / sin maestro)',
                'match' => $row['match'] ?? 'EXTRA (fuera de lista maestra → C018+)',
            ];
        }

        return compact('before', 'apply', 'detener', 'missing', 'extra');
    }

    /**
     * @param  list<string>  $aliases
     */
    private function clientMatchesMaster(Client $client, array $aliases): bool
    {
        $haystacks = [
            $this->normalize((string) $client->name),
            $this->normalize((string) $client->business_name),
        ];

        foreach ($aliases as $alias) {
            $needle = $this->normalize($alias);
            if ($needle === '') {
                continue;
            }
            foreach ($haystacks as $hay) {
                if ($hay === '') {
                    continue;
                }
                if ($hay === $needle) {
                    return true;
                }
                // Alias corto contenido en nombre (ej. "cintas" ⊂ "cintas industriales")
                // o nombre contenido en alias (ej. "cintas" vs alias "cintas industriales")
                if (str_contains($hay, $needle) || str_contains($needle, $hay)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        $value = Str::lower(Str::ascii(trim($value)));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function planSuppliers(): array
    {
        $rows = [];
        $n = 1;
        foreach (Supplier::query()->orderBy('id')->get() as $supplier) {
            $rows[] = [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'business_name' => $supplier->business_name,
                'current_code' => $supplier->code,
                'current_formatted' => $this->supplierCodes->format($supplier->code !== null ? (int) $supplier->code : null),
                'new_code' => $n,
                'new_formatted' => $this->supplierCodes->format($n),
            ];
            $n++;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printHuman(array $report): void
    {
        $this->info('=== CLIENTES (ANTES DE APLICAR) ===');
        $this->table(
            ['ID interno', 'Nombre actual', 'Código actual', 'Código nuevo', 'Match'],
            collect($report['clients_before'])->map(fn ($r) => [
                $r['id'], $r['name'], $r['current_formatted'], $r['new_formatted'], $r['match'],
            ])->all()
        );

        if ($report['clients_detener'] !== []) {
            $this->error('DETENER (ambiguos — no se asigna el código maestro):');
            foreach ($report['clients_detener'] as $r) {
                $this->line("  id={$r['id']} {$r['name']} → {$r['new_formatted']} ({$r['master_name']}): {$r['match']}");
            }
        }

        if ($report['clients_missing'] !== []) {
            $this->warn('Ausentes en staging (no inventar):');
            foreach ($report['clients_missing'] as $r) {
                $this->line("  {$r['new_formatted']} {$r['master_name']}");
            }
        }

        $this->info('=== PROVEEDORES (PROPUESTA) ===');
        $this->table(
            ['ID', 'Proveedor', 'PXXX'],
            collect($report['suppliers'])->map(fn ($r) => [
                $r['id'],
                trim($r['name'].($r['business_name'] ? ' / '.$r['business_name'] : '')),
                $r['new_formatted'],
            ])->all()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function monetaryFingerprint(): array
    {
        return [
            'client_ledger_sum' => (string) DB::table('client_ledger_entries')->sum('signed_amount'),
            'client_ledger_count' => (int) DB::table('client_ledger_entries')->count(),
            'supplier_ledger_sum' => (string) DB::table('supplier_ledger_entries')->sum('signed_amount'),
            'supplier_ledger_count' => (int) DB::table('supplier_ledger_entries')->count(),
            'movements_sum' => (string) DB::table('movements')->sum('amount'),
            'movements_count' => (int) DB::table('movements')->count(),
            'charges_sum' => Schema::hasTable('commercial_charges') ? (string) DB::table('commercial_charges')->sum('amount') : '0',
            'receipts_sum' => Schema::hasTable('receipts') ? (string) DB::table('receipts')->sum('amount') : '0',
            'client_ids' => DB::table('clients')->orderBy('id')->pluck('id')->all(),
            'supplier_ids' => DB::table('suppliers')->orderBy('id')->pluck('id')->all(),
        ];
    }
}
