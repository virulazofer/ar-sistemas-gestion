<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\ClientLedgerEntry;
use App\Support\Money;
use App\Support\UiSemantics;

/**
 * Ranking / listado de cuentas corrientes de clientes (solo consulta; no muta ledger).
 *
 * Saldo de presentación: + = nos deben, − = a favor del cliente (invertido vs signed_amount DB).
 * Antigüedad no se calcula: no hay aging fiable por falta de aplicación FIFO de cobros.
 */
class ClientCurrentAccountRankingService
{
    public const FILTER_ALL = 'all';

    public const FILTER_OWING = 'owing';

    public const FILTER_CREDIT = 'credit';

    public const FILTER_SETTLED = 'settled';

    /**
     * @param  array{filter?: string, q?: string}  $input
     * @return array<string, mixed>
     */
    public function build(array $input = []): array
    {
        $filter = $this->normalizeFilter($input['filter'] ?? self::FILTER_OWING);
        $q = trim((string) ($input['q'] ?? ''));

        $rows = $this->allBalanceRows();
        $summary = $this->summary($rows);
        $list = $this->applyFilters($rows, $filter, $q);
        $this->sortDefault($list, $filter);

        return [
            'filter' => $filter,
            'q' => $q,
            'summary' => $summary,
            'rows' => $list,
            'filters' => [
                self::FILTER_ALL => 'Todos',
                self::FILTER_OWING => 'Nos deben',
                self::FILTER_CREDIT => 'Saldo a favor',
                self::FILTER_SETTLED => 'Saldados',
            ],
            'omit_aging' => true,
            'aging_note' => 'Antigüedad omitida: no hay aging fiable (cobros no se aplican FIFO a cargos).',
            'balance_convention' => 'Saldo CC de presentación: positivo = nos deben · negativo = a favor del cliente · cero = saldado. DB signed_amount se mantiene (perspectiva cliente).',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allBalanceRows(): array
    {
        $balances = ClientLedgerEntry::query()
            ->posted()
            ->selectRaw('client_id, currency_id, SUM(signed_amount) as bal')
            ->groupBy('client_id', 'currency_id')
            ->with(['client', 'currency'])
            ->get();

        $lastDates = ClientLedgerEntry::query()
            ->posted()
            ->selectRaw('client_id, currency_id, MAX(entry_date) as last_entry_date')
            ->groupBy('client_id', 'currency_id')
            ->get()
            ->keyBy(fn ($r) => $r->client_id.'|'.$r->currency_id);

        $out = [];
        foreach ($balances as $row) {
            $raw = Money::normalize((string) $row->bal);
            $display = UiSemantics::clientCcDisplayBalance($raw);
            $code = $row->currency?->code;
            if (! in_array($code, ['ARS', 'USD'], true)) {
                continue;
            }

            $key = $row->client_id.'|'.$row->currency_id;
            $last = $lastDates->get($key)?->last_entry_date;

            $out[] = [
                'client_id' => (int) $row->client_id,
                'name' => $row->client?->name ?? ('#'.$row->client_id),
                'currency' => $code,
                'balance' => $display,
                'raw_signed' => $raw,
                'last_movement_at' => $last ? (string) $last : null,
                'last_movement_label' => $last
                    ? \Carbon\Carbon::parse($last)->format('d/m/Y')
                    : '—',
                'url' => $row->client_id ? route('clients.show', $row->client_id) : null,
                'has_activity' => true,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(array $rows): array
    {
        $toCollect = ['ARS' => '0.00', 'USD' => '0.00'];
        $inFavor = ['ARS' => '0.00', 'USD' => '0.00'];

        $owingClients = [];
        $creditClients = [];

        foreach ($rows as $row) {
            $code = $row['currency'];
            $bal = $row['balance'];
            if (Money::isPositive($bal)) {
                $owingClients[$row['client_id']] = true;
                $toCollect[$code] = Money::add($toCollect[$code], $bal);
            } elseif (Money::isNegative($bal)) {
                $creditClients[$row['client_id']] = true;
                $inFavor[$code] = Money::add($inFavor[$code], Money::mul($bal, '-1'));
            }
        }

        $settledClients = $this->settledClientIds();

        return [
            'owing_clients_count' => count($owingClients),
            'credit_clients_count' => count($creditClients),
            'settled_clients_count' => count($settledClients),
            'to_collect' => $toCollect,
            'in_favor' => $inFavor,
        ];
    }

    /**
     * @return array<int, true>
     */
    private function settledClientIds(): array
    {
        $activeIds = ClientLedgerEntry::query()
            ->posted()
            ->distinct()
            ->pluck('client_id')
            ->all();

        if ($activeIds === []) {
            return [];
        }

        $nonZero = ClientLedgerEntry::query()
            ->posted()
            ->selectRaw('client_id, currency_id, SUM(signed_amount) as bal')
            ->groupBy('client_id', 'currency_id')
            ->havingRaw('SUM(signed_amount) <> 0')
            ->pluck('client_id')
            ->unique()
            ->all();

        $settled = [];
        foreach ($activeIds as $id) {
            if (! in_array($id, $nonZero, true)) {
                $settled[(int) $id] = true;
            }
        }

        return $settled;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function applyFilters(array $rows, string $filter, string $q): array
    {
        if ($filter === self::FILTER_SETTLED) {
            $settledIds = array_keys($this->settledClientIds());
            $clients = Client::query()
                ->whereIn('id', $settledIds)
                ->when($q !== '', function ($query) use ($q) {
                    $query->where(function ($inner) use ($q) {
                        $inner->where('name', 'like', "%{$q}%")
                            ->orWhere('business_name', 'like', "%{$q}%")
                            ->orWhere('cuit', 'like', "%{$q}%")
                            ->orWhere('dni', 'like', "%{$q}%");
                    });
                })
                ->orderBy('name')
                ->get();

            $list = [];
            foreach ($clients as $client) {
                $list[] = [
                    'client_id' => $client->id,
                    'name' => $client->name,
                    'currency' => '—',
                    'balance' => '0.00',
                    'raw_signed' => '0.00',
                    'last_movement_at' => null,
                    'last_movement_label' => $this->lastMovementLabelForClient($client->id),
                    'url' => route('clients.show', $client),
                    'has_activity' => true,
                ];
            }

            return $list;
        }

        $list = [];
        foreach ($rows as $row) {
            $bal = $row['balance'];
            $include = match ($filter) {
                self::FILTER_OWING => Money::isPositive($bal),
                self::FILTER_CREDIT => Money::isNegative($bal),
                self::FILTER_ALL => ! Money::isZero($bal),
                default => ! Money::isZero($bal),
            };
            if (! $include) {
                continue;
            }
            if ($q !== '' && ! str_contains(mb_strtolower($row['name']), mb_strtolower($q))) {
                continue;
            }
            $list[] = $row;
        }

        return $list;
    }

    private function lastMovementLabelForClient(int $clientId): string
    {
        $date = ClientLedgerEntry::query()
            ->posted()
            ->where('client_id', $clientId)
            ->max('entry_date');

        return $date ? \Carbon\Carbon::parse($date)->format('d/m/Y') : '—';
    }

    /**
     * @param  list<array<string, mixed>>  $list
     */
    private function sortDefault(array &$list, string $filter): void
    {
        if ($filter === self::FILTER_SETTLED) {
            usort($list, fn ($a, $b) => strcmp($a['name'], $b['name']));

            return;
        }

        if ($filter === self::FILTER_CREDIT) {
            // Más a favor primero (más negativo primero)
            usort($list, function ($a, $b) {
                $cmp = Money::compare($a['balance'], $b['balance']);

                return $cmp === 0 ? strcmp($a['name'], $b['name']) : $cmp;
            });

            return;
        }

        // owing / all: saldo descendente (mayor deuda primero)
        usort($list, function ($a, $b) {
            $cmp = Money::compare($b['balance'], $a['balance']);

            return $cmp === 0 ? strcmp($a['name'], $b['name']) : $cmp;
        });
    }

    private function normalizeFilter(string $filter): string
    {
        return in_array($filter, [
            self::FILTER_ALL,
            self::FILTER_OWING,
            self::FILTER_CREDIT,
            self::FILTER_SETTLED,
        ], true) ? $filter : self::FILTER_OWING;
    }
}
