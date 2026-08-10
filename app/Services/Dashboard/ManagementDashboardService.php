<?php

namespace App\Services\Dashboard;

use App\Enums\AccountType;
use App\Enums\MovementScope;
use App\Enums\MovementType;
use App\Enums\SaleStatus;
use App\Models\Category;
use App\Models\ClientLedgerEntry;
use App\Models\FinancialAccount;
use App\Models\Movement;
use App\Models\Sale;
use App\Support\Money;
use App\Support\UiSemantics;
use Carbon\Carbon;

/**
 * Dashboard de Gestión (11F-1): agregaciones de período sobre datos reales.
 * Financiero ≠ económico. Transferencias (p.ej. pago TC) no son gasto.
 * Posición/CC al cierre = foto al último día del período (no saldos "hoy").
 */
class ManagementDashboardService
{
    private const MONTHS_ES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    /**
     * @param  array{
     *   preset?: string,
     *   ym?: string|null,
     *   from?: string|null,
     *   to?: string|null,
     *   scope?: string,
     *   chart_months?: int|string,
     *   sort_income?: string|null,
     *   sort_expense?: string|null,
     * }  $input
     * @return array<string, mixed>
     */
    public function build(array $input): array
    {
        $scope = $this->normalizeScope($input['scope'] ?? 'all');
        $chartMonths = in_array((int) ($input['chart_months'] ?? 6), [6, 12], true)
            ? (int) ($input['chart_months'] ?? 6)
            : 6;

        $period = $this->resolvePeriod($input);
        $comparison = $this->comparisonPeriod($period);

        $financial = $this->financialKpis($period, $scope);
        $financialPrev = $comparison['has_base']
            ? $this->financialKpis($comparison, $scope)
            : null;

        $economic = $this->economicKpis($period, $scope);
        $economicPrev = $comparison['has_base']
            ? $this->economicKpis($comparison, $scope)
            : null;

        $cc = $this->ccKpis($period, $scope);
        $position = $this->positionAtClose($period['to']);
        $byType = $this->byTypeBreakdown($period, $comparison, $scope, $input);
        $charts = $this->chartSeries($period['to'], $chartMonths, $scope);
        $monthly = $this->monthlySummaryTable($period['to'], $scope, 12);

        return [
            'scope' => $scope,
            'period' => $period,
            'comparison' => $comparison,
            'chart_months' => $chartMonths,
            'financial' => $this->attachComparison($financial, $financialPrev, $comparison['has_base']),
            'economic' => $this->attachComparison($economic, $economicPrev, $comparison['has_base']),
            'cc' => $cc,
            'position' => $position,
            'income_by_type' => $byType['income'],
            'expense_by_type' => $byType['expense'],
            'charts' => $charts,
            'monthly_summary' => $monthly,
            'drilldown' => $this->drilldownUrls($period, $scope),
            'limitations' => $this->limitations($scope),
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *   preset: string,
     *   from: string,
     *   to: string,
     *   from_label: string,
     *   to_label: string,
     *   label: string,
     *   ym: string,
     *   has_base: bool
     * }
     */
    public function resolvePeriod(array $input): array
    {
        $preset = (string) ($input['preset'] ?? 'this_month');
        if (! in_array($preset, ['this_month', 'previous_month', 'year', 'custom', 'month'], true)) {
            $preset = 'this_month';
        }

        $today = Carbon::today();

        if ($preset === 'custom') {
            $from = $this->parseDate($input['from'] ?? null) ?? $today->copy()->startOfMonth();
            $to = $this->parseDate($input['to'] ?? null) ?? $today->copy()->endOfMonth();
            if ($to->lt($from)) {
                [$from, $to] = [$to, $from];
            }
        } elseif ($preset === 'previous_month') {
            $cursor = $today->copy()->subMonthNoOverflow();
            $from = $cursor->copy()->startOfMonth();
            $to = $cursor->copy()->endOfMonth();
            $preset = 'month';
        } elseif ($preset === 'year') {
            $from = $today->copy()->startOfYear();
            $to = $today->copy()->endOfYear();
        } elseif ($preset === 'month' || ! empty($input['ym'])) {
            $ym = (string) ($input['ym'] ?? $today->format('Y-m'));
            try {
                $cursor = Carbon::createFromFormat('Y-m', $ym)->startOfMonth();
            } catch (\Throwable) {
                $cursor = $today->copy()->startOfMonth();
            }
            $from = $cursor->copy()->startOfMonth();
            $to = $cursor->copy()->endOfMonth();
            $preset = 'month';
        } else {
            // this_month
            $from = $today->copy()->startOfMonth();
            $to = $today->copy()->endOfMonth();
            $preset = 'this_month';
        }

        $ym = $from->format('Y-m');
        $label = $preset === 'year'
            ? 'Año '.$from->year
            : (($preset === 'custom')
                ? 'Personalizado'
                : (self::MONTHS_ES[(int) $from->month].' '.$from->year));

        return [
            'preset' => $preset,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'from_label' => $from->format('d/m/Y'),
            'to_label' => $to->format('d/m/Y'),
            'label' => $label,
            'ym' => $ym,
            'has_base' => true,
        ];
    }

    /**
     * Período anterior de igual duración (días calendario).
     *
     * @param  array{from: string, to: string}  $period
     * @return array{from: string, to: string, from_label: string, to_label: string, has_base: bool, label: string}
     */
    public function comparisonPeriod(array $period): array
    {
        $from = Carbon::parse($period['from'])->startOfDay();
        $to = Carbon::parse($period['to'])->startOfDay();
        $days = $from->diffInDays($to) + 1;

        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1);

        $hasData = Movement::query()->posted()
            ->whereDate('movement_date', '>=', $prevFrom->toDateString())
            ->whereDate('movement_date', '<=', $prevTo->toDateString())
            ->exists()
            || Sale::query()
                ->where('status', SaleStatus::Confirmed->value)
                ->whereDate('sold_on', '>=', $prevFrom->toDateString())
                ->whereDate('sold_on', '<=', $prevTo->toDateString())
                ->exists()
            || ClientLedgerEntry::query()->posted()
                ->whereDate('entry_date', '>=', $prevFrom->toDateString())
                ->whereDate('entry_date', '<=', $prevTo->toDateString())
                ->exists();

        return [
            'from' => $prevFrom->toDateString(),
            'to' => $prevTo->toDateString(),
            'from_label' => $prevFrom->format('d/m/Y'),
            'to_label' => $prevTo->format('d/m/Y'),
            'has_base' => $hasData,
            'label' => $prevFrom->format('d/m/Y').' — '.$prevTo->format('d/m/Y'),
        ];
    }

    /**
     * @return array{income_ars: string, income_usd: string, expense_ars: string, expense_usd: string, result_ars: string, result_usd: string}
     */
    public function financialKpis(array $period, string $scope): array
    {
        $q = Movement::query()
            ->posted()
            ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value])
            ->whereDate('movement_date', '>=', $period['from'])
            ->whereDate('movement_date', '<=', $period['to']);

        $this->applyScope($q, $scope);

        $incomeArs = '0.00';
        $incomeUsd = '0.00';
        $expenseArs = '0.00';
        $expenseUsd = '0.00';

        $rows = $q->with('currency')->get(['type', 'amount', 'currency_id']);
        foreach ($rows as $row) {
            $code = $row->currency?->code;
            $amount = Money::normalize((string) $row->amount);
            if ($row->type === MovementType::Income) {
                if ($code === 'ARS') {
                    $incomeArs = Money::add($incomeArs, $amount);
                } elseif ($code === 'USD') {
                    $incomeUsd = Money::add($incomeUsd, $amount);
                }
            } else {
                if ($code === 'ARS') {
                    $expenseArs = Money::add($expenseArs, $amount);
                } elseif ($code === 'USD') {
                    $expenseUsd = Money::add($expenseUsd, $amount);
                }
            }
        }

        return [
            'income_ars' => $incomeArs,
            'income_usd' => $incomeUsd,
            'expense_ars' => $expenseArs,
            'expense_usd' => $expenseUsd,
            'result_ars' => Money::sub($incomeArs, $expenseArs),
            'result_usd' => Money::sub($incomeUsd, $expenseUsd),
        ];
    }

    /**
     * Económico = ventas del módulo Sales (confirmadas). Distinto de ingreso financiero.
     *
     * @return array{sales_ars: string, sales_usd: string, cost_ars: string, cost_usd: string, utility_ars: string, utility_usd: string, count: int, note: string|null}
     */
    public function economicKpis(array $period, string $scope): array
    {
        if ($scope === 'personal') {
            return [
                'sales_ars' => '0.00',
                'sales_usd' => '0.00',
                'cost_ars' => '0.00',
                'cost_usd' => '0.00',
                'utility_ars' => '0.00',
                'utility_usd' => '0.00',
                'count' => 0,
                'note' => 'Ámbito Personal: el módulo de ventas es profesional; KPIs económicos en cero.',
            ];
        }

        $sales = Sale::query()
            ->where('status', SaleStatus::Confirmed->value)
            ->whereDate('sold_on', '>=', $period['from'])
            ->whereDate('sold_on', '<=', $period['to'])
            ->get(['currency_code', 'total', 'total_ars', 'total_usd', 'total_cost_ars', 'total_cost_usd', 'gross_margin', 'payment_mode']);

        $salesArs = '0.00';
        $salesUsd = '0.00';
        $costArs = '0.00';
        $costUsd = '0.00';
        $utilityArs = '0.00';
        $utilityUsd = '0.00';

        foreach ($sales as $sale) {
            if ($sale->currency_code === 'ARS') {
                $salesArs = Money::add($salesArs, (string) $sale->total);
                $costArs = Money::add($costArs, (string) ($sale->total_cost_ars ?? $sale->total_cost ?? '0'));
                $utilityArs = Money::add($utilityArs, (string) $sale->gross_margin);
            } else {
                $salesUsd = Money::add($salesUsd, (string) $sale->total);
                $costUsd = Money::add($costUsd, (string) ($sale->total_cost_usd ?? $sale->total_cost ?? '0'));
                $utilityUsd = Money::add($utilityUsd, (string) $sale->gross_margin);
            }
        }

        return [
            'sales_ars' => $salesArs,
            'sales_usd' => $salesUsd,
            'cost_ars' => $costArs,
            'cost_usd' => $costUsd,
            'utility_ars' => $utilityArs,
            'utility_usd' => $utilityUsd,
            'count' => $sales->count(),
            'note' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ccKpis(array $period, string $scope): array
    {
        if ($scope === 'personal') {
            return [
                'applicable' => false,
                'note' => 'Ámbito Personal: CC clientes es profesional.',
                'opening' => $this->emptyMoneyPair(),
                'closing' => $this->emptyMoneyPair(),
                'new_debt' => $this->emptyMoneyPair(),
                'collections' => $this->emptyMoneyPair(),
                'variation' => $this->emptyMoneyPair(),
                'clients' => [],
            ];
        }

        $openingDate = Carbon::parse($period['from'])->subDay()->toDateString();
        $opening = $this->receivableAsOf($openingDate);
        $closing = $this->receivableAsOf($period['to']);
        $activity = $this->ccPeriodActivity($period['from'], $period['to']);
        $clients = $this->topDebtorsAsOf($period['to'], 5);

        $variation = [
            'ARS' => Money::sub($closing['ARS'], $opening['ARS']),
            'USD' => Money::sub($closing['USD'], $opening['USD']),
        ];

        return [
            'applicable' => true,
            'note' => null,
            'opening' => $opening,
            'closing' => $closing,
            'new_debt' => $activity['new_debt'],
            'collections' => $activity['collections'],
            'variation' => $variation,
            'clients' => $clients,
            // KPI bloque: inicial + IN − OUT = final (validación de coherencia)
            'bridge' => [
                'ARS' => [
                    'initial' => $opening['ARS'],
                    'in' => $activity['new_debt']['ARS'],
                    'out' => $activity['collections']['ARS'],
                    'final' => $closing['ARS'],
                    'computed_final' => Money::sub(
                        Money::add($opening['ARS'], $activity['new_debt']['ARS']),
                        $activity['collections']['ARS']
                    ),
                ],
                'USD' => [
                    'initial' => $opening['USD'],
                    'in' => $activity['new_debt']['USD'],
                    'out' => $activity['collections']['USD'],
                    'final' => $closing['USD'],
                    'computed_final' => Money::sub(
                        Money::add($opening['USD'], $activity['new_debt']['USD']),
                        $activity['collections']['USD']
                    ),
                ],
            ],
        ];
    }

    /**
     * Posición financiera al cierre histórico (último día del período).
     *
     * @return array<string, mixed>
     */
    public function positionAtClose(string $asOf): array
    {
        $accounts = FinancialAccount::query()
            ->with(['currency', 'holder'])
            ->active()
            ->orderBy('name')
            ->get();

        $balances = $this->balancesAsOf($accounts->pluck('id')->all(), $asOf);

        $liquid = [
            'ARS' => ['cash' => '0.00', 'bank' => '0.00', 'wallet' => '0.00', 'total' => '0.00'],
            'USD' => ['cash' => '0.00', 'bank' => '0.00', 'wallet' => '0.00', 'total' => '0.00'],
        ];
        $liabilities = [
            'ARS' => '0.00',
            'USD' => '0.00',
        ];
        $byHolder = [];
        $breakdown = ['assets' => [], 'liabilities' => []];

        foreach ($accounts as $account) {
            $code = $account->currency?->code;
            if (! in_array($code, ['ARS', 'USD'], true)) {
                continue;
            }

            $bal = $balances[$account->id] ?? '0.00';
            $isLiability = $account->is_liability || $account->type === AccountType::CreditCard;
            $holderName = $account->holder?->name ?? 'Sin titular';
            $holderCode = $account->holder?->code ?? 'none';

            if (! isset($byHolder[$holderCode])) {
                $byHolder[$holderCode] = [
                    'name' => $holderName,
                    'liquid' => ['ARS' => '0.00', 'USD' => '0.00'],
                    'liabilities' => ['ARS' => '0.00', 'USD' => '0.00'],
                    'net' => ['ARS' => '0.00', 'USD' => '0.00'],
                ];
            }

            if ($isLiability) {
                // Deuda a mostrar (positiva): |saldo| si ≠ 0. Compras en tarjeta suelen dejar saldo negativo
                // (expense en liability); pagos (transfer in) lo acercan a cero.
                $debt = Money::isZero($bal) ? '0.00' : (Money::isNegative($bal) ? Money::mul($bal, '-1') : $bal);
                $liabilities[$code] = Money::add($liabilities[$code], $debt);
                $byHolder[$holderCode]['liabilities'][$code] = Money::add($byHolder[$holderCode]['liabilities'][$code], $debt);
                $breakdown['liabilities'][] = [
                    'id' => $account->id,
                    'name' => $account->name,
                    'currency' => $code,
                    'balance' => $debt,
                    'holder' => $holderName,
                    'type' => $account->type->value,
                ];
            } elseif (in_array($account->type, [AccountType::Cash, AccountType::Bank, AccountType::Wallet], true)) {
                $key = match ($account->type) {
                    AccountType::Cash => 'cash',
                    AccountType::Bank => 'bank',
                    AccountType::Wallet => 'wallet',
                };
                $liquid[$code][$key] = Money::add($liquid[$code][$key], $bal);
                $liquid[$code]['total'] = Money::add($liquid[$code]['total'], $bal);
                $byHolder[$holderCode]['liquid'][$code] = Money::add($byHolder[$holderCode]['liquid'][$code], $bal);
                $breakdown['assets'][] = [
                    'id' => $account->id,
                    'name' => $account->name,
                    'currency' => $code,
                    'balance' => $bal,
                    'holder' => $holderName,
                    'type' => $account->type->value,
                ];
            }
        }

        foreach ($byHolder as $code => $row) {
            $byHolder[$code]['net'] = [
                'ARS' => Money::sub($row['liquid']['ARS'], $row['liabilities']['ARS']),
                'USD' => Money::sub($row['liquid']['USD'], $row['liabilities']['USD']),
            ];
        }

        return [
            'as_of' => $asOf,
            'as_of_label' => Carbon::parse($asOf)->format('d/m/Y'),
            'liquid' => $liquid,
            'liabilities' => $liabilities,
            'net' => [
                'ARS' => Money::sub($liquid['ARS']['total'], $liabilities['ARS']),
                'USD' => Money::sub($liquid['USD']['total'], $liabilities['USD']),
            ],
            'by_holder' => array_values($byHolder),
            'breakdown' => $breakdown,
            'note' => 'Cuentas compartidas entre ámbitos; el desglose por titular (Fernando/Gabi) no es el filtro Personal/Profesional.',
        ];
    }

    /**
     * @param  list<int>  $accountIds
     * @return array<int, string>
     */
    public function balancesAsOf(array $accountIds, string $asOf): array
    {
        $out = [];
        foreach ($accountIds as $id) {
            $out[$id] = '0.00';
        }

        if ($accountIds === []) {
            return $out;
        }

        $rows = Movement::query()
            ->posted()
            ->whereIn('financial_account_id', $accountIds)
            ->whereDate('movement_date', '<=', $asOf)
            ->get(['financial_account_id', 'type', 'amount']);

        foreach ($rows as $row) {
            /** @var MovementType $type */
            $type = $row->type;
            $delta = Money::mul((string) $row->amount, (string) $type->signedMultiplier());
            $id = (int) $row->financial_account_id;
            $out[$id] = Money::add($out[$id] ?? '0.00', $delta);
        }

        return $out;
    }

    private function normalizeScope(string $scope): string
    {
        return in_array($scope, ['personal', 'professional', 'all'], true) ? $scope : 'all';
    }

    private function applyScope($query, string $scope): void
    {
        if ($scope === 'personal') {
            $query->where('scope', MovementScope::Personal->value);
        } elseif ($scope === 'professional') {
            $query->where('scope', MovementScope::Professional->value);
        }
    }

    private function parseDate(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $value)->startOfDay();
            } catch (\Throwable) {
            }
        }
        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{ARS: string, USD: string} */
    private function emptyMoneyPair(): array
    {
        return ['ARS' => '0.00', 'USD' => '0.00'];
    }

    /** @return array{ARS: string, USD: string} */
    public function receivableAsOf(string $asOf): array
    {
        $pair = $this->emptyMoneyPair();

        $rows = ClientLedgerEntry::query()
            ->posted()
            ->whereDate('entry_date', '<=', $asOf)
            ->selectRaw('client_id, currency_id, SUM(signed_amount) as bal')
            ->groupBy('client_id', 'currency_id')
            ->havingRaw('SUM(signed_amount) < 0')
            ->with('currency')
            ->get();

        foreach ($rows as $row) {
            $code = $row->currency?->code;
            if (! isset($pair[$code])) {
                continue;
            }
            $pair[$code] = Money::add($pair[$code], Money::mul(Money::normalize((string) $row->bal), '-1'));
        }

        return $pair;
    }

    /**
     * @return array{new_debt: array{ARS: string, USD: string}, collections: array{ARS: string, USD: string}}
     */
    public function ccPeriodActivity(string $from, string $to): array
    {
        $newDebt = $this->emptyMoneyPair();
        $collections = $this->emptyMoneyPair();

        $rows = ClientLedgerEntry::query()
            ->posted()
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            ->with('currency')
            ->get(['currency_id', 'signed_amount', 'type']);

        foreach ($rows as $row) {
            $code = $row->currency?->code;
            if (! isset($newDebt[$code])) {
                continue;
            }
            $signed = Money::normalize((string) $row->signed_amount);
            if (Money::isNegative($signed)) {
                // CC IN ↑ deuda
                $newDebt[$code] = Money::add($newDebt[$code], Money::mul($signed, '-1'));
            } elseif (Money::isPositive($signed)) {
                // CC OUT ↓ deuda
                $collections[$code] = Money::add($collections[$code], $signed);
            }
        }

        return ['new_debt' => $newDebt, 'collections' => $collections];
    }

    /**
     * Top deudores al cierre (saldo presentación + = nos deben), mayor→menor.
     *
     * @return list<array<string, mixed>>
     */
    public function topDebtorsAsOf(string $asOf, int $limit = 5): array
    {
        $all = $this->clientsWithBalanceAsOf($asOf);
        $debtors = array_values(array_filter(
            $all,
            fn ($row) => Money::isPositive($row['balance'])
        ));

        usort($debtors, fn ($a, $b) => Money::compare($b['balance'], $a['balance']));

        return array_slice($debtors, 0, $limit);
    }

    /**
     * Saldos CC al cierre en convención de presentación (+ = a cobrar / nos deben).
     *
     * @return list<array<string, mixed>>
     */
    public function clientsWithBalanceAsOf(string $asOf): array
    {
        $rows = ClientLedgerEntry::query()
            ->posted()
            ->whereDate('entry_date', '<=', $asOf)
            ->selectRaw('client_id, currency_id, SUM(signed_amount) as bal')
            ->groupBy('client_id', 'currency_id')
            ->havingRaw('SUM(signed_amount) <> 0')
            ->with(['client', 'currency'])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $bal = Money::normalize((string) $row->bal);
            if (Money::isZero($bal)) {
                continue;
            }
            // signed_amount negativo = cliente debe → presentación positiva (a cobrar)
            $receivable = UiSemantics::clientCcDisplayBalance($bal);
            $out[] = [
                'client_id' => $row->client_id,
                'name' => $row->client?->name ?? ('#'.$row->client_id),
                'currency' => $row->currency?->code,
                'balance' => $receivable,
                'raw_signed' => $bal,
                'url' => $row->client_id ? route('clients.show', $row->client_id) : null,
            ];
        }

        usort($out, function ($a, $b) {
            // Mayor deuda (positivo) primero; créditos al final
            $cmp = Money::compare($b['balance'], $a['balance']);

            return $cmp === 0 ? strcmp($a['name'], $b['name']) : $cmp;
        });

        return $out;
    }

    /**
     * @param  array<string, string>  $current
     * @param  array<string, string>|null  $previous
     * @return array<string, mixed>
     */
    private function attachComparison(array $current, ?array $previous, bool $hasBase): array
    {
        $current['comparison_available'] = $hasBase && $previous !== null;
        $current['comparison_label'] = $hasBase ? null : 'Sin base de comparación';
        $current['variation'] = [];

        if (! $current['comparison_available'] || $previous === null) {
            return $current;
        }

        foreach ($current as $key => $value) {
            if (! is_string($value) || ! array_key_exists($key, $previous) || ! is_string($previous[$key])) {
                continue;
            }
            if (! str_contains($key, '_ars') && ! str_contains($key, '_usd') && ! in_array($key, [
                'income_ars', 'expense_ars', 'result_ars', 'sales_ars', 'cost_ars', 'utility_ars',
                'income_usd', 'expense_usd', 'result_usd', 'sales_usd', 'cost_usd', 'utility_usd',
            ], true)) {
                continue;
            }
            $delta = Money::sub($value, $previous[$key]);
            $pct = Money::percentChange($value, $previous[$key]);
            $current['variation'][$key] = [
                'previous' => $previous[$key],
                'delta' => $delta,
                'percent' => $pct,
            ];
        }

        return $current;
    }

    /**
     * @return array{income: list<array<string, mixed>>, expense: list<array<string, mixed>>}
     */
    private function byTypeBreakdown(array $period, array $comparison, string $scope, array $input): array
    {
        $current = $this->aggregateByCategory($period, $scope);
        $prev = $comparison['has_base']
            ? $this->aggregateByCategory($comparison, $scope)
            : ['income' => [], 'expense' => []];

        return [
            'income' => $this->mergeTypeRows($current['income'], $prev['income'], $period, $scope, 'income', $input['sort_income'] ?? null),
            'expense' => $this->mergeTypeRows($current['expense'], $prev['expense'], $period, $scope, 'expense', $input['sort_expense'] ?? null),
        ];
    }

    /**
     * @return array{income: array<int|string, array{name: string, ars: string, usd: string, category_id: int|null}>, expense: array<...>}
     */
    private function aggregateByCategory(array $period, string $scope): array
    {
        $q = Movement::query()
            ->posted()
            ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value])
            ->whereDate('movement_date', '>=', $period['from'])
            ->whereDate('movement_date', '<=', $period['to']);

        $this->applyScope($q, $scope);

        $rows = $q->with('currency')->get(['type', 'category_id', 'amount', 'currency_id']);
        $names = Category::query()->whereIn('id', $rows->pluck('category_id')->filter()->unique())->pluck('name', 'id');

        $out = ['income' => [], 'expense' => []];
        foreach ($rows as $row) {
            $bucket = $row->type === MovementType::Income ? 'income' : 'expense';
            $cid = $row->category_id ?? 0;
            $key = (string) $cid;
            if (! isset($out[$bucket][$key])) {
                $out[$bucket][$key] = [
                    'category_id' => $row->category_id,
                    'name' => $cid ? (string) ($names[$cid] ?? 'Categoría #'.$cid) : 'Sin categoría',
                    'ars' => '0.00',
                    'usd' => '0.00',
                ];
            }
            $amount = Money::normalize((string) $row->amount);
            $code = $row->currency?->code;
            if ($code === 'ARS') {
                $out[$bucket][$key]['ars'] = Money::add($out[$bucket][$key]['ars'], $amount);
            } elseif ($code === 'USD') {
                $out[$bucket][$key]['usd'] = Money::add($out[$bucket][$key]['usd'], $amount);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, array{name: string, ars: string, usd: string, category_id: int|null}>  $current
     * @param  array<string, array{name: string, ars: string, usd: string, category_id: int|null}>  $previous
     * @return list<array<string, mixed>>
     */
    private function mergeTypeRows(array $current, array $previous, array $period, string $scope, string $type, ?string $sort): array
    {
        $totalArs = '0.00';
        foreach ($current as $row) {
            $totalArs = Money::add($totalArs, $row['ars']);
        }

        $list = [];
        $keys = array_unique(array_merge(array_keys($current), array_keys($previous)));
        foreach ($keys as $key) {
            $cur = $current[$key] ?? ['category_id' => is_numeric($key) && (int) $key > 0 ? (int) $key : null, 'name' => $previous[$key]['name'] ?? '—', 'ars' => '0.00', 'usd' => '0.00'];
            $prev = $previous[$key]['ars'] ?? '0.00';
            $pctOfTotal = Money::isZero($totalArs) ? null : Money::mul(Money::div($cur['ars'], $totalArs, 6), '100', 2);
            $varPct = Money::percentChange($cur['ars'], $prev);
            $list[] = [
                'category_id' => $cur['category_id'],
                'name' => $cur['name'],
                'amount_ars' => $cur['ars'],
                'amount_usd' => $cur['usd'],
                'percent' => $pctOfTotal,
                'previous_ars' => $prev,
                'variation_percent' => $varPct,
                'variation_delta' => Money::sub($cur['ars'], $prev),
                'url' => route('movements.index', array_filter([
                    'type' => $type,
                    'scope' => $scope === 'all' ? null : $scope,
                    'category_id' => $cur['category_id'],
                    'date_from' => $period['from'],
                    'date_to' => $period['to'],
                    'status' => 'posted',
                ])),
            ];
        }

        $sort = $sort ?: 'amount_desc';
        usort($list, function ($a, $b) use ($sort) {
            return match ($sort) {
                'name_asc' => strcmp($a['name'], $b['name']),
                'name_desc' => strcmp($b['name'], $a['name']),
                'amount_asc' => Money::compare($a['amount_ars'], $b['amount_ars']),
                'pct_asc' => Money::compare($a['percent'] ?? '0', $b['percent'] ?? '0'),
                'pct_desc' => Money::compare($b['percent'] ?? '0', $a['percent'] ?? '0'),
                'var_asc' => Money::compare($a['variation_delta'], $b['variation_delta']),
                'var_desc' => Money::compare($b['variation_delta'], $a['variation_delta']),
                default => Money::compare($b['amount_ars'], $a['amount_ars']),
            };
        });

        return $list;
    }

    /**
     * @return array{financial: list<array<string, mixed>>, economic: list<array<string, mixed>>}
     */
    private function chartSeries(string $periodTo, int $months, string $scope): array
    {
        $end = Carbon::parse($periodTo)->endOfMonth();
        $start = $end->copy()->subMonthsNoOverflow($months - 1)->startOfMonth();

        $labels = [];
        $keys = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $keys[] = $cursor->format('Y-m');
            $labels[] = self::MONTHS_ES[(int) $cursor->month].' '.$cursor->format('y');
            $cursor->addMonthNoOverflow();
        }

        $fin = [];
        $eco = [];
        foreach ($keys as $ym) {
            $fin[$ym] = ['income' => '0.00', 'expense' => '0.00', 'result' => '0.00'];
            $eco[$ym] = ['sales' => '0.00', 'utility' => '0.00'];
        }

        $movQ = Movement::query()
            ->posted()
            ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value])
            ->whereDate('movement_date', '>=', $start->toDateString())
            ->whereDate('movement_date', '<=', $end->toDateString());
        $this->applyScope($movQ, $scope);

        foreach ($movQ->with('currency')->get(['type', 'movement_date', 'amount', 'currency_id']) as $row) {
            if ($row->currency?->code !== 'ARS') {
                continue; // gráficos financieros en ARS nativo (no sumar USD)
            }
            $ym = Carbon::parse($row->movement_date)->format('Y-m');
            if (! isset($fin[$ym])) {
                continue;
            }
            if ($row->type === MovementType::Income) {
                $fin[$ym]['income'] = Money::add($fin[$ym]['income'], Money::normalize((string) $row->amount));
            } else {
                $fin[$ym]['expense'] = Money::add($fin[$ym]['expense'], Money::normalize((string) $row->amount));
            }
        }
        foreach ($fin as $ym => $row) {
            $fin[$ym]['result'] = Money::sub($row['income'], $row['expense']);
        }

        if ($scope !== 'personal') {
            $sales = Sale::query()
                ->where('status', SaleStatus::Confirmed->value)
                ->whereDate('sold_on', '>=', $start->toDateString())
                ->whereDate('sold_on', '<=', $end->toDateString())
                ->get(['sold_on', 'total_ars', 'gross_margin', 'currency_code', 'total']);

            foreach ($sales as $sale) {
                $ym = Carbon::parse($sale->sold_on)->format('Y-m');
                if (! isset($eco[$ym])) {
                    continue;
                }
                $saleArs = $sale->currency_code === 'ARS'
                    ? (string) $sale->total
                    : (string) ($sale->total_ars ?? '0');
                $eco[$ym]['sales'] = Money::add($eco[$ym]['sales'], $saleArs);
                // Utilidad: margen en moneda de venta; si USD, usamos total_ars-equivalent margin when available
                $eco[$ym]['utility'] = Money::add($eco[$ym]['utility'], (string) $sale->gross_margin);
            }
        }

        return [
            'labels' => $labels,
            'financial' => [
                'income' => array_map(fn ($ym) => $fin[$ym]['income'], $keys),
                'expense' => array_map(fn ($ym) => $fin[$ym]['expense'], $keys),
                'result' => array_map(fn ($ym) => $fin[$ym]['result'], $keys),
            ],
            'economic' => [
                'sales' => array_map(fn ($ym) => $eco[$ym]['sales'], $keys),
                'utility' => array_map(fn ($ym) => $eco[$ym]['utility'], $keys),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function monthlySummaryTable(string $periodTo, string $scope, int $months): array
    {
        $end = Carbon::parse($periodTo)->endOfMonth();
        $rows = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $cursor = $end->copy()->subMonthsNoOverflow($i);
            $period = [
                'from' => $cursor->copy()->startOfMonth()->toDateString(),
                'to' => $cursor->copy()->endOfMonth()->toDateString(),
            ];
            $fin = $this->financialKpis($period, $scope);
            $eco = $this->economicKpis($period, $scope);
            $ym = $cursor->format('Y-m');
            $rows[] = [
                'ym' => $ym,
                'label' => self::MONTHS_ES[(int) $cursor->month].' '.$cursor->year,
                'income_ars' => $fin['income_ars'],
                'expense_ars' => $fin['expense_ars'],
                'result_ars' => $fin['result_ars'],
                'sales_ars' => $eco['sales_ars'],
                'utility_ars' => $eco['utility_ars'],
                'url' => route('dashboard.management', [
                    'preset' => 'month',
                    'ym' => $ym,
                    'scope' => $scope,
                ]),
            ];
        }

        return $rows;
    }

    /** @return array<string, string> */
    private function drilldownUrls(array $period, string $scope): array
    {
        $base = [
            'date_from' => $period['from'],
            'date_to' => $period['to'],
            'status' => 'posted',
        ];
        if ($scope !== 'all') {
            $base['scope'] = $scope;
        }

        return [
            'income' => route('movements.index', $base + ['type' => 'income']),
            'expense' => route('movements.index', $base + ['type' => 'expense']),
            'sales' => route('sales.index', [
                'date_from' => $period['from'],
                'date_to' => $period['to'],
            ]),
            'clients' => route('clients.current-accounts', ['filter' => 'owing']),
            'client_current_accounts' => route('clients.current-accounts', ['filter' => 'owing']),
        ];
    }

    /** @return list<string> */
    private function limitations(string $scope): array
    {
        $items = [
            'KPIs económicos (Ventas/Costo/Utilidad) provienen del módulo Ventas confirmadas; filas históricas 11E de venta/merca/utilidad “análisis only” no inventan documentos de venta.',
            'Posición y disponibilidades son compartidas entre ámbitos (filtro Personal/Profesional no parte las cuentas).',
            'Corte mensual = saldo acumulado de movimientos posted con fecha ≤ último día del período (no snapshot persistido).',
            'Pago de resumen de tarjeta se modela como transferencia: cancela pasivo, no duplica egreso en Resultado financiero.',
        ];
        if ($scope === 'personal') {
            $items[] = 'En ámbito Personal, CC clientes y ventas económicas se muestran en cero (son de naturaleza profesional).';
        }

        return $items;
    }
}
