<?php

namespace App\Services\Clients;

use App\Enums\ClientLedgerType;
use App\Enums\MovementStatus;
use App\Models\Client;
use App\Models\ClientLedgerEntry;
use App\Models\Movement;
use App\Models\Receipt;
use App\Support\Money;
use App\Support\UiSemantics;
use Illuminate\Support\Collection;

/**
 * Timeline unificada de CC cliente: ledger + incomes históricos + cobros/cargos,
 * deduplicando movement↔ledger↔receipt.
 */
class ClientCcTimelineService
{
    public const FILTER_ALL = 'todos';

    public const FILTER_CHARGES = 'cargos';

    public const FILTER_PAYMENTS = 'cobros';

    public const FILTER_ABONOS = 'abonos';

    public const FILTER_SALES = 'ventas';

    public const FILTER_ADJUSTMENTS = 'ajustes';

    public const FILTER_OPENINGS = 'aperturas';

    /**
     * @return array{
     *   items: LengthAwarePaginator,
     *   total: int,
     *   filter: string,
     *   filters: array<string, string>
     * }
     */
    public function paginate(Client $client, string $filter = self::FILTER_ALL, int $perPage = 50): array
    {
        $filter = $this->normalizeFilter($filter);
        $all = $this->buildTimeline($client);
        $filtered = $all->filter(fn (array $row) => $this->matchesFilter($row, $filter))->values();

        $page = max(1, (int) request()->integer('page', 1));
        $total = $filtered->count();
        $slice = $filtered->slice(($page - 1) * $perPage, $perPage)->values();

        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => array_merge(request()->query(), ['cc_filter' => $filter]),
            ]
        );

        return [
            'items' => $paginator,
            'total' => $total,
            'filter' => $filter,
            'filters' => [
                self::FILTER_ALL => 'Todos',
                self::FILTER_CHARGES => 'Cargos',
                self::FILTER_PAYMENTS => 'Cobros',
                self::FILTER_ABONOS => 'Abonos',
                self::FILTER_SALES => 'Ventas',
                self::FILTER_ADJUSTMENTS => 'Ajustes',
                self::FILTER_OPENINGS => 'Aperturas',
            ],
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function buildTimeline(Client $client): Collection
    {
        $controlDesde = $client->control_cc_desde?->toDateString();

        $entries = ClientLedgerEntry::query()
            ->with(['currency', 'financialMovement', 'commercialCharge', 'receipt'])
            ->where('client_id', $client->id)
            ->when($controlDesde, fn ($q) => $q->whereDate('entry_date', '>=', $controlDesde))
            ->orderBy('entry_date')
            ->orderBy('entry_time')
            ->orderBy('id')
            ->get();

        $linkedMovementIds = $entries
            ->pluck('financial_movement_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $receiptMovementIds = Receipt::query()
            ->where('client_id', $client->id)
            ->whereNotNull('financial_movement_id')
            ->pluck('financial_movement_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $excludeMovements = array_values(array_unique(array_merge($linkedMovementIds, $receiptMovementIds)));

        $historicalIncomes = Movement::query()
            ->where('client_id', $client->id)
            ->where('type', 'income')
            ->where('status', MovementStatus::Posted->value)
            ->when($excludeMovements !== [], fn ($q) => $q->whereNotIn('id', $excludeMovements))
            ->when($controlDesde, fn ($q) => $q->whereDate('movement_date', '>=', $controlDesde))
            ->orderBy('movement_date')
            ->orderBy('id')
            ->get();

        $rows = collect();
        $running = '0.00';

        foreach ($entries as $entry) {
            $affectsCc = $entry->isPosted();
            if ($affectsCc) {
                $running = Money::add($running, (string) $entry->signed_amount);
            }

            $rows->push([
                'sort_date' => $entry->entry_date?->format('Y-m-d') ?? '9999-99-99',
                'sort_time' => (string) ($entry->entry_time ?? '00:00:00'),
                'sort_id' => $entry->id,
                'kind' => $this->ledgerKind($entry),
                'date' => $entry->entry_date,
                'type_label' => $entry->type?->label() ?? 'CC',
                'origin_label' => $this->ledgerOrigin($entry),
                'description' => $entry->description,
                'currency' => $entry->currency?->code ?? 'ARS',
                'amount' => (string) $entry->amount,
                'signed_amount' => (string) $entry->signed_amount,
                'affects_cc' => $affectsCc,
                'running_balance' => $running,
                'running_display' => UiSemantics::clientCcDisplayBalance($running),
                'status' => $entry->status?->value ?? '',
                'dedupe_key' => 'ledger:'.$entry->id,
                'related' => [
                    'ledger_id' => $entry->id,
                    'charge_id' => $entry->commercial_charge_id,
                    'receipt_id' => $entry->receipt_id,
                    'movement_id' => $entry->financial_movement_id,
                ],
            ]);
        }

        // Incomes históricos sin efecto CC adicional (ya dedupeados vs ledger/receipt):
        // visibles una vez como cobro/abono histórico relacionado.
        foreach ($historicalIncomes as $mov) {
            $rows->push([
                'sort_date' => $mov->movement_date?->format('Y-m-d') ?? '9999-99-99',
                'sort_time' => (string) ($mov->movement_time ?? '00:00:00'),
                'sort_id' => 1_000_000_000 + $mov->id,
                'kind' => $this->incomeKind($mov),
                'date' => $mov->movement_date,
                'type_label' => 'Ingreso histórico',
                'origin_label' => $this->incomeOrigin($mov),
                'description' => $mov->description,
                'currency' => 'ARS',
                'amount' => (string) $mov->amount,
                'signed_amount' => null,
                'affects_cc' => false,
                'running_balance' => null,
                'running_display' => null,
                'status' => $mov->status?->value ?? 'posted',
                'dedupe_key' => 'movement:'.$mov->id,
                'related' => [
                    'ledger_id' => null,
                    'charge_id' => null,
                    'receipt_id' => null,
                    'movement_id' => $mov->id,
                    'source_row' => $mov->source_row,
                ],
            ]);
        }

        return $rows
            ->sortBy([
                ['sort_date', 'asc'],
                ['sort_time', 'asc'],
                ['sort_id', 'asc'],
            ])
            ->values()
            ->pipe(function (Collection $chrono) {
                // Newest first for UI; recompute display running only for CC-affecting in chrono then reverse.
                $running = '0.00';
                $withRun = $chrono->map(function (array $row) use (&$running) {
                    if ($row['affects_cc']) {
                        $running = Money::add($running, (string) $row['signed_amount']);
                        $row['running_balance'] = $running;
                        $row['running_display'] = UiSemantics::clientCcDisplayBalance($running);
                    }

                    return $row;
                });

                return $withRun->reverse()->values();
            });
    }

    private function ledgerKind(ClientLedgerEntry $entry): string
    {
        $desc = mb_strtolower((string) $entry->description);
        if (str_contains($desc, 'cc inicial') || str_contains($desc, 'apertura')) {
            return self::FILTER_OPENINGS;
        }
        if ($entry->regularization_kind) {
            return self::FILTER_ADJUSTMENTS;
        }
        if ($entry->type === ClientLedgerType::Adjustment) {
            return self::FILTER_ADJUSTMENTS;
        }
        if ($entry->type === ClientLedgerType::Charge) {
            if ($entry->subscription_id || str_contains($desc, 'abono')) {
                return self::FILTER_ABONOS;
            }
            if ($entry->sale_id || str_contains($desc, 'venta') || str_contains($desc, 'notebook') || str_contains($desc, 'cable')) {
                return self::FILTER_SALES;
            }

            return self::FILTER_CHARGES;
        }
        if ($entry->type === ClientLedgerType::Payment) {
            return self::FILTER_PAYMENTS;
        }

        return self::FILTER_ALL;
    }

    private function ledgerOrigin(ClientLedgerEntry $entry): string
    {
        if ($entry->receipt_id) {
            $receipt = $entry->receipt;
            if ($receipt && str_contains((string) $receipt->notes, 'GLOBAL_FORMULA_REPAIR_')) {
                return 'Cobro reconciliación';
            }

            return 'Cobro 11F-3';
        }
        if ($entry->commercial_charge_id) {
            $charge = $entry->commercialCharge;
            if ($charge && (
                str_contains((string) $charge->notes, 'DAASA_POST_11F3')
                || str_contains((string) $charge->notes, 'GLOBAL_FORMULA_REPAIR_')
            )) {
                return 'Cargo reconciliación';
            }
            if ($charge && str_contains((string) $charge->notes, 'Backfill 11F-3')) {
                return 'Cargo legacy→11F-3';
            }

            return 'Cargo';
        }
        if ($entry->financial_movement_id) {
            return 'Cobro histórico+caja';
        }
        if ($entry->regularization_kind) {
            return 'Regularización';
        }
        if ($entry->type === ClientLedgerType::Payment) {
            return 'Cobro histórico CC';
        }
        if ($entry->type === ClientLedgerType::Charge) {
            return 'Cargo histórico CC';
        }

        return $entry->originLabel();
    }

    private function incomeKind(Movement $mov): string
    {
        $desc = mb_strtolower((string) $mov->description);
        if (str_contains($desc, 'abono') || str_contains($desc, 'server') || str_contains($desc, 'servidor') || str_contains($desc, 'mantenimiento')) {
            return self::FILTER_ABONOS;
        }

        return self::FILTER_PAYMENTS;
    }

    private function incomeOrigin(Movement $mov): string
    {
        if ($mov->source_row) {
            return 'Abono/ingreso 11E (sin cargo vinculado)';
        }

        return 'Ingreso financiero';
    }

    private function matchesFilter(array $row, string $filter): bool
    {
        if ($filter === self::FILTER_ALL) {
            return true;
        }

        return ($row['kind'] ?? '') === $filter;
    }

    private function normalizeFilter(string $filter): string
    {
        $filter = mb_strtolower(trim($filter));
        $allowed = [
            self::FILTER_ALL,
            self::FILTER_CHARGES,
            self::FILTER_PAYMENTS,
            self::FILTER_ABONOS,
            self::FILTER_SALES,
            self::FILTER_ADJUSTMENTS,
            self::FILTER_OPENINGS,
        ];

        return in_array($filter, $allowed, true) ? $filter : self::FILTER_ALL;
    }
}
