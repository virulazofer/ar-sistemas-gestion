<?php

namespace App\Services\Reports;

use App\Enums\InventoryLotStatus;
use App\Enums\MovementType;
use App\Enums\SaleStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Client;
use App\Models\ClientLedgerEntry;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\InventoryLot;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\WorkOrder;
use App\Services\Finance\BalanceService;
use App\Services\Inventory\StockBalanceService;
use App\Support\Money;

class ReportService
{
    public function __construct(
        private readonly BalanceService $balances,
        private readonly StockBalanceService $stockBalances,
    ) {}

    /**
     * @param  array{
     *   date_from?: string|null,
     *   date_to?: string|null,
     *   account_id?: int|null,
     *   currency_code?: string|null,
     *   category_id?: int|null,
     *   chart_account_id?: int|string|null,
     *   scope?: string|null,
     *   type?: string|null,
     *   user_id?: int|null,
     * }  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function financeMovements(array $filters = []): array
    {
        $query = Movement::query()
            ->with(['account.currency', 'currency', 'category', 'user', 'chartAccount'])
            ->posted()
            ->orderBy('movement_date')
            ->orderBy('id');

        if (! empty($filters['date_from'])) {
            $query->whereDate('movement_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('movement_date', '<=', $filters['date_to']);
        }
        if (! empty($filters['account_id'])) {
            $query->where('financial_account_id', (int) $filters['account_id']);
        }
        if (! empty($filters['currency_code'])) {
            $code = strtoupper((string) $filters['currency_code']);
            $currencyId = Currency::query()->where('code', $code)->value('id');
            if ($currencyId) {
                $query->where('currency_id', $currencyId);
            }
        }
        if (! empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }
        if (! empty($filters['scope']) && $filters['scope'] !== 'all') {
            $query->where('scope', $filters['scope']);
        }
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }
        if (! empty($filters['chart_account_id'])) {
            if ($filters['chart_account_id'] === 'unassigned') {
                // Cola operativa: sin categoría (no exigir cuenta contable redundante).
                $query->whereNull('category_id')
                    ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value]);
            } else {
                $query->where('chart_account_id', (int) $filters['chart_account_id']);
            }
        }

        $rows = [];
        $totalArs = '0.00';
        $totalUsd = '0.00';
        $incomeArs = '0.00';
        $expenseArs = '0.00';

        foreach ($query->get() as $m) {
            $amountArs = Money::normalize((string) $m->amount_ars);
            $amountUsd = Money::normalize((string) $m->amount_usd);

            if ($m->type === MovementType::Income) {
                $incomeArs = Money::add($incomeArs, $amountArs);
            } elseif ($m->type === MovementType::Expense) {
                $expenseArs = Money::add($expenseArs, $amountArs);
            }

            $totalArs = Money::add($totalArs, $amountArs);
            $totalUsd = Money::add($totalUsd, $amountUsd);

            $rows[] = [
                'id' => $m->id,
                'date' => $m->movement_date?->toDateString(),
                'type' => $m->type->value,
                'type_label' => $m->type->label(),
                'scope' => $m->scope->value,
                'scope_label' => $m->scope->label(),
                'account' => $m->account?->name,
                'currency' => $m->currency?->code,
                'amount' => Money::normalize((string) $m->amount),
                'amount_ars' => $amountArs,
                'amount_usd' => $amountUsd,
                'category' => $m->category?->name,
                'chart_account' => $m->chartAccount?->name,
                'description' => $m->description,
                'user' => $m->user?->name,
            ];
        }

        return [
            'rows' => $rows,
            'totals' => [
                'count' => (string) count($rows),
                'amount_ars' => $totalArs,
                'amount_usd' => $totalUsd,
                'income_ars' => $incomeArs,
                'expense_ars' => $expenseArs,
                'result_ars' => Money::sub($incomeArs, $expenseArs),
            ],
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function financeBalances(): array
    {
        $accounts = FinancialAccount::query()
            ->with('currency')
            ->orderBy('name')
            ->get();

        $rows = [];
        $totals = [
            'ARS' => '0.00',
            'USD' => '0.00',
        ];

        foreach ($accounts as $account) {
            $computed = $this->balances->computeAccountBalance($account->id);
            $cached = Money::normalize((string) ($account->cached_balance ?? '0'));
            $code = $account->currency?->code ?? '';

            if (isset($totals[$code]) && $account->isActive()) {
                $totals[$code] = Money::add($totals[$code], $computed);
            }

            $rows[] = [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'type_label' => config('finance.account_types.'.$account->type->value, $account->type->value),
                'currency' => $code,
                'status' => $account->status,
                'cached_balance' => $cached,
                'computed_balance' => $computed,
                'diff' => Money::sub($computed, $cached),
            ];
        }

        return [
            'rows' => $rows,
            'totals' => [
                'ARS' => $totals['ARS'],
                'USD' => $totals['USD'],
                'count' => (string) count($rows),
            ],
        ];
    }

    /**
     * @param  array{
     *   date_from?: string|null,
     *   date_to?: string|null,
     *   scope?: string|null,
     *   currency_code?: string|null,
     * }  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function financeIncomeExpense(array $filters = []): array
    {
        $query = Movement::query()
            ->with(['account.currency', 'currency', 'category'])
            ->posted()
            ->orderBy('movement_date')
            ->orderBy('id');

        if (! empty($filters['date_from'])) {
            $query->whereDate('movement_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('movement_date', '<=', $filters['date_to']);
        }
        if (! empty($filters['scope']) && $filters['scope'] !== 'all') {
            $query->where('scope', $filters['scope']);
        }
        if (! empty($filters['currency_code'])) {
            $code = strtoupper((string) $filters['currency_code']);
            $currencyId = Currency::query()->where('code', $code)->value('id');
            if ($currencyId) {
                $query->where('currency_id', $currencyId);
            }
        }

        $incomeArs = '0.00';
        $expenseArs = '0.00';
        $transferOutArs = '0.00';
        $transferInArs = '0.00';
        $incomeUsd = '0.00';
        $expenseUsd = '0.00';
        $rows = [];

        foreach ($query->get() as $m) {
            $ars = Money::normalize((string) $m->amount_ars);
            $usd = Money::normalize((string) $m->amount_usd);
            $group = match ($m->type) {
                MovementType::Income => 'income',
                MovementType::Expense => 'expense',
                MovementType::TransferOut => 'transfer_out',
                MovementType::TransferIn => 'transfer_in',
            };

            if ($m->type === MovementType::Income) {
                $incomeArs = Money::add($incomeArs, $ars);
                $incomeUsd = Money::add($incomeUsd, $usd);
            } elseif ($m->type === MovementType::Expense) {
                $expenseArs = Money::add($expenseArs, $ars);
                $expenseUsd = Money::add($expenseUsd, $usd);
            } elseif ($m->type === MovementType::TransferOut) {
                $transferOutArs = Money::add($transferOutArs, $ars);
            } else {
                $transferInArs = Money::add($transferInArs, $ars);
            }

            $rows[] = [
                'id' => $m->id,
                'date' => $m->movement_date?->toDateString(),
                'group' => $group,
                'type' => $m->type->value,
                'type_label' => $m->type->label(),
                'scope' => $m->scope->value,
                'account' => $m->account?->name,
                'currency' => $m->currency?->code,
                'amount' => Money::normalize((string) $m->amount),
                'amount_ars' => $ars,
                'amount_usd' => $usd,
                'category' => $m->category?->name,
                'description' => $m->description,
            ];
        }

        return [
            'rows' => $rows,
            'totals' => [
                'income_ars' => $incomeArs,
                'expense_ars' => $expenseArs,
                'result_ars' => Money::sub($incomeArs, $expenseArs),
                'income_usd' => $incomeUsd,
                'expense_usd' => $expenseUsd,
                'result_usd' => Money::sub($incomeUsd, $expenseUsd),
                'transfer_out_ars' => $transferOutArs,
                'transfer_in_ars' => $transferInArs,
                'count' => (string) count($rows),
            ],
        ];
    }

    /**
     * Saldos por cliente. Negativo = deuda del cliente.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function clientsReceivables(): array
    {
        $clients = Client::query()->orderBy('name')->get();
        $rows = [];
        $debtArs = '0.00';
        $debtUsd = '0.00';
        $creditArs = '0.00';
        $creditUsd = '0.00';

        foreach ($clients as $client) {
            $ars = Money::normalize((string) ClientLedgerEntry::query()
                ->posted()
                ->where('client_id', $client->id)
                ->whereHas('currency', fn ($q) => $q->where('code', 'ARS'))
                ->sum('signed_amount'));
            $usd = Money::normalize((string) ClientLedgerEntry::query()
                ->posted()
                ->where('client_id', $client->id)
                ->whereHas('currency', fn ($q) => $q->where('code', 'USD'))
                ->sum('signed_amount'));

            if (Money::compare($ars, '0') === 0 && Money::compare($usd, '0') === 0) {
                continue;
            }

            if (Money::compare($ars, '0') < 0) {
                $debtArs = Money::add($debtArs, Money::mul($ars, '-1'));
            } else {
                $creditArs = Money::add($creditArs, $ars);
            }
            if (Money::compare($usd, '0') < 0) {
                $debtUsd = Money::add($debtUsd, Money::mul($usd, '-1'));
            } else {
                $creditUsd = Money::add($creditUsd, $usd);
            }

            $rows[] = [
                'client_id' => $client->id,
                'name' => $client->name,
                'cuit' => $client->cuit,
                'status' => $client->status,
                'balance_ars' => $ars,
                'balance_usd' => $usd,
                'debt_ars' => Money::compare($ars, '0') < 0 ? Money::mul($ars, '-1') : '0.00',
                'debt_usd' => Money::compare($usd, '0') < 0 ? Money::mul($usd, '-1') : '0.00',
            ];
        }

        return [
            'rows' => $rows,
            'totals' => [
                'debt_ars' => $debtArs,
                'debt_usd' => $debtUsd,
                'credit_ars' => $creditArs,
                'credit_usd' => $creditUsd,
                'count' => (string) count($rows),
            ],
        ];
    }

    /**
     * @param  array{
     *   client_id?: int|null,
     *   date_from?: string|null,
     *   date_to?: string|null,
     *   currency_code?: string|null,
     * }  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function clientsMovements(array $filters = []): array
    {
        $query = ClientLedgerEntry::query()
            ->with(['client', 'currency', 'user'])
            ->posted()
            ->orderBy('entry_date')
            ->orderBy('id');

        if (! empty($filters['client_id'])) {
            $query->where('client_id', (int) $filters['client_id']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('entry_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('entry_date', '<=', $filters['date_to']);
        }
        if (! empty($filters['currency_code'])) {
            $code = strtoupper((string) $filters['currency_code']);
            $currencyId = Currency::query()->where('code', $code)->value('id');
            if ($currencyId) {
                $query->where('currency_id', $currencyId);
            }
        }

        $rows = [];
        $sumArs = '0.00';
        $sumUsd = '0.00';

        foreach ($query->get() as $entry) {
            $signed = Money::normalize((string) $entry->signed_amount);
            $code = $entry->currency?->code ?? '';
            if ($code === 'ARS') {
                $sumArs = Money::add($sumArs, $signed);
            } elseif ($code === 'USD') {
                $sumUsd = Money::add($sumUsd, $signed);
            }

            $rows[] = [
                'id' => $entry->id,
                'date' => $entry->entry_date?->toDateString(),
                'client_id' => $entry->client_id,
                'client' => $entry->client?->name,
                'type' => $entry->type->value,
                'currency' => $code,
                'amount' => Money::normalize((string) $entry->amount),
                'signed_amount' => $signed,
                'amount_ars' => Money::normalize((string) $entry->amount_ars),
                'amount_usd' => Money::normalize((string) $entry->amount_usd),
                'description' => $entry->description,
                'user' => $entry->user?->name,
            ];
        }

        return [
            'rows' => $rows,
            'totals' => [
                'signed_ars' => $sumArs,
                'signed_usd' => $sumUsd,
                'count' => (string) count($rows),
            ],
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function suppliersPayables(): array
    {
        $suppliers = Supplier::query()->orderBy('name')->get();
        $rows = [];
        $debtArs = '0.00';
        $debtUsd = '0.00';

        foreach ($suppliers as $supplier) {
            $ars = Money::normalize((string) SupplierLedgerEntry::query()
                ->posted()
                ->where('supplier_id', $supplier->id)
                ->whereHas('currency', fn ($q) => $q->where('code', 'ARS'))
                ->sum('signed_amount'));
            $usd = Money::normalize((string) SupplierLedgerEntry::query()
                ->posted()
                ->where('supplier_id', $supplier->id)
                ->whereHas('currency', fn ($q) => $q->where('code', 'USD'))
                ->sum('signed_amount'));

            if (Money::compare($ars, '0') === 0 && Money::compare($usd, '0') === 0) {
                continue;
            }

            if (Money::compare($ars, '0') < 0) {
                $debtArs = Money::add($debtArs, Money::mul($ars, '-1'));
            }
            if (Money::compare($usd, '0') < 0) {
                $debtUsd = Money::add($debtUsd, Money::mul($usd, '-1'));
            }

            $rows[] = [
                'supplier_id' => $supplier->id,
                'name' => $supplier->name,
                'cuit' => $supplier->cuit,
                'status' => $supplier->status,
                'balance_ars' => $ars,
                'balance_usd' => $usd,
                'payable_ars' => Money::compare($ars, '0') < 0 ? Money::mul($ars, '-1') : '0.00',
                'payable_usd' => Money::compare($usd, '0') < 0 ? Money::mul($usd, '-1') : '0.00',
            ];
        }

        return [
            'rows' => $rows,
            'totals' => [
                'payable_ars' => $debtArs,
                'payable_usd' => $debtUsd,
                'count' => (string) count($rows),
            ],
        ];
    }

    /**
     * @param  array{
     *   supplier_id?: int|null,
     *   date_from?: string|null,
     *   date_to?: string|null,
     *   currency_code?: string|null,
     * }  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function suppliersMovements(array $filters = []): array
    {
        $query = SupplierLedgerEntry::query()
            ->with(['supplier', 'currency', 'user'])
            ->posted()
            ->orderBy('entry_date')
            ->orderBy('id');

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('entry_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('entry_date', '<=', $filters['date_to']);
        }
        if (! empty($filters['currency_code'])) {
            $code = strtoupper((string) $filters['currency_code']);
            $currencyId = Currency::query()->where('code', $code)->value('id');
            if ($currencyId) {
                $query->where('currency_id', $currencyId);
            }
        }

        $rows = [];
        $sumArs = '0.00';
        $sumUsd = '0.00';

        foreach ($query->get() as $entry) {
            $signed = Money::normalize((string) $entry->signed_amount);
            $code = $entry->currency?->code ?? '';
            if ($code === 'ARS') {
                $sumArs = Money::add($sumArs, $signed);
            } elseif ($code === 'USD') {
                $sumUsd = Money::add($sumUsd, $signed);
            }

            $rows[] = [
                'id' => $entry->id,
                'date' => $entry->entry_date?->toDateString(),
                'supplier_id' => $entry->supplier_id,
                'supplier' => $entry->supplier?->name,
                'type' => $entry->type->value,
                'currency' => $code,
                'amount' => Money::normalize((string) $entry->amount),
                'signed_amount' => $signed,
                'amount_ars' => Money::normalize((string) $entry->amount_ars),
                'amount_usd' => Money::normalize((string) $entry->amount_usd),
                'description' => $entry->description,
                'user' => $entry->user?->name,
            ];
        }

        return [
            'rows' => $rows,
            'totals' => [
                'signed_ars' => $sumArs,
                'signed_usd' => $sumUsd,
                'count' => (string) count($rows),
            ],
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function stockCurrent(): array
    {
        $products = Product::query()
            ->where('type', 'physical')
            ->orderBy('name')
            ->get();

        $rows = [];
        $qtyTotal = '0';
        $valueArs = '0.00';
        $valueUsd = '0.00';

        foreach ($products as $product) {
            $val = $this->stockBalances->inventoryValue($product->id);
            $qty = Money::normalize((string) $product->qty_on_hand, 4);
            $qtyTotal = Money::add($qtyTotal, $qty, 4);
            $valueArs = Money::add($valueArs, $val['value_ars']);
            $valueUsd = Money::add($valueUsd, $val['value_usd']);

            $rows[] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'status' => $product->status,
                'qty_on_hand' => $qty,
                'qty_reserved' => Money::normalize((string) $product->qty_reserved, 4),
                'qty_available' => $product->qtyAvailable(),
                'stock_min' => Money::normalize((string) $product->stock_min, 4),
                'value_ars' => $val['value_ars'],
                'value_usd' => $val['value_usd'],
                'lots' => $val['lots'],
            ];
        }

        return [
            'rows' => $rows,
            'totals' => [
                'qty' => $qtyTotal,
                'value_ars' => $valueArs,
                'value_usd' => $valueUsd,
                'count' => (string) count($rows),
            ],
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function stockLots(): array
    {
        $lots = InventoryLot::query()
            ->with(['product', 'currency', 'location', 'supplier'])
            ->where('status', InventoryLotStatus::Open->value)
            ->where('qty_remaining', '>', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();

        $rows = [];
        $qty = '0';
        $valueArs = '0.00';
        $valueUsd = '0.00';

        foreach ($lots as $lot) {
            $remaining = Money::normalize((string) $lot->qty_remaining, 4);
            $lineArs = Money::normalize(bcmul($remaining, (string) $lot->unit_cost_ars, 10), 2);
            $lineUsd = Money::normalize(bcmul($remaining, (string) $lot->unit_cost_usd, 10), 2);
            $qty = Money::add($qty, $remaining, 4);
            $valueArs = Money::add($valueArs, $lineArs);
            $valueUsd = Money::add($valueUsd, $lineUsd);

            $rows[] = [
                'lot_id' => $lot->id,
                'product_id' => $lot->product_id,
                'sku' => $lot->product?->sku,
                'product' => $lot->product?->name,
                'received_at' => optional($lot->received_at)?->toDateTimeString(),
                'qty_received' => Money::normalize((string) $lot->qty_received, 4),
                'qty_remaining' => $remaining,
                'unit_cost' => Money::normalize((string) $lot->unit_cost, 6),
                'currency' => $lot->currency?->code,
                'unit_cost_ars' => Money::normalize((string) $lot->unit_cost_ars, 6),
                'unit_cost_usd' => Money::normalize((string) $lot->unit_cost_usd, 6),
                'value_ars' => $lineArs,
                'value_usd' => $lineUsd,
                'location' => $lot->location?->name,
                'supplier' => $lot->supplier?->name,
            ];
        }

        return [
            'rows' => $rows,
            'totals' => [
                'qty' => $qty,
                'value_ars' => $valueArs,
                'value_usd' => $valueUsd,
                'count' => (string) count($rows),
            ],
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function stockLow(): array
    {
        $products = Product::query()
            ->where('type', 'physical')
            ->where('status', Product::STATUS_ACTIVE)
            ->where('stock_min', '>', 0)
            ->whereColumn('qty_on_hand', '<', 'stock_min')
            ->orderBy('name')
            ->get();

        $rows = [];
        foreach ($products as $product) {
            $onHand = Money::normalize((string) $product->qty_on_hand, 4);
            $min = Money::normalize((string) $product->stock_min, 4);
            $rows[] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'qty_on_hand' => $onHand,
                'stock_min' => $min,
                'deficit' => Money::sub($min, $onHand, 4),
                'out_of_stock' => Money::compare($onHand, '0', 4) <= 0,
            ];
        }

        return [
            'rows' => $rows,
            'totals' => [
                'count' => (string) count($rows),
                'out_of_stock' => (string) collect($rows)->where('out_of_stock', true)->count(),
            ],
        ];
    }

    /**
     * @param  array{
     *   date_from?: string|null,
     *   date_to?: string|null,
     *   client_id?: int|null,
     *   currency_code?: string|null,
     * }  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function salesReport(array $filters = []): array
    {
        $query = Sale::query()
            ->with(['client', 'salesperson'])
            ->where('status', SaleStatus::Confirmed->value)
            ->orderBy('sold_on')
            ->orderBy('id');

        if (! empty($filters['date_from'])) {
            $query->whereDate('sold_on', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('sold_on', '<=', $filters['date_to']);
        }
        if (! empty($filters['client_id'])) {
            $query->where('client_id', (int) $filters['client_id']);
        }
        if (! empty($filters['currency_code'])) {
            $query->where('currency_code', strtoupper((string) $filters['currency_code']));
        }

        $rows = [];
        $totalArs = '0.00';
        $totalUsd = '0.00';
        $costArs = '0.00';
        $costUsd = '0.00';
        $marginArs = '0.00';
        $marginUsd = '0.00';

        foreach ($query->get() as $sale) {
            $totalArs = Money::add($totalArs, Money::normalize((string) $sale->total_ars));
            $totalUsd = Money::add($totalUsd, Money::normalize((string) $sale->total_usd));
            $costArs = Money::add($costArs, Money::normalize((string) $sale->total_cost_ars));
            $costUsd = Money::add($costUsd, Money::normalize((string) $sale->total_cost_usd));

            $margin = Money::normalize((string) $sale->gross_margin);
            if ($sale->currency_code === 'ARS') {
                $marginArs = Money::add($marginArs, $margin);
            } else {
                $marginUsd = Money::add($marginUsd, $margin);
            }

            $rows[] = [
                'id' => $sale->id,
                'number' => $sale->number,
                'sold_on' => $sale->sold_on?->toDateString(),
                'client' => $sale->client?->name,
                'currency' => $sale->currency_code,
                'payment_mode' => $sale->payment_mode,
                'total' => Money::normalize((string) $sale->total),
                'total_ars' => Money::normalize((string) $sale->total_ars),
                'total_usd' => Money::normalize((string) $sale->total_usd),
                'total_cost' => Money::normalize((string) $sale->total_cost),
                'total_cost_ars' => Money::normalize((string) $sale->total_cost_ars),
                'total_cost_usd' => Money::normalize((string) $sale->total_cost_usd),
                'gross_margin' => $margin,
                'salesperson' => $sale->salesperson?->name,
            ];
        }

        return [
            'rows' => $rows,
            'totals' => [
                'count' => (string) count($rows),
                'total_ars' => $totalArs,
                'total_usd' => $totalUsd,
                'cost_ars' => $costArs,
                'cost_usd' => $costUsd,
                'margin_ars' => $marginArs,
                'margin_usd' => $marginUsd,
            ],
        ];
    }

    /**
     * Líneas de venta confirmadas + OT cerradas (precio - costo).
     *
     * @param  array{
     *   date_from?: string|null,
     *   date_to?: string|null,
     * }  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function profitability(array $filters = []): array
    {
        $rows = [];
        $revenueArs = '0.00';
        $costArs = '0.00';
        $marginArs = '0.00';
        $revenueUsd = '0.00';
        $costUsd = '0.00';
        $marginUsd = '0.00';

        $saleItems = SaleItem::query()
            ->with(['sale.client', 'product'])
            ->whereHas('sale', function ($q) use ($filters) {
                $q->where('status', SaleStatus::Confirmed->value);
                if (! empty($filters['date_from'])) {
                    $q->whereDate('sold_on', '>=', $filters['date_from']);
                }
                if (! empty($filters['date_to'])) {
                    $q->whereDate('sold_on', '<=', $filters['date_to']);
                }
            })
            ->orderBy('sale_id')
            ->orderBy('line_number')
            ->get();

        foreach ($saleItems as $item) {
            $priceArs = Money::normalize((string) $item->line_total_ars);
            $costLineArs = Money::normalize((string) $item->line_cost_ars);
            $priceUsd = Money::normalize((string) $item->line_total_usd);
            $costLineUsd = Money::normalize((string) $item->line_cost_usd);
            $margin = Money::normalize((string) $item->line_margin);

            $revenueArs = Money::add($revenueArs, $priceArs);
            $costArs = Money::add($costArs, $costLineArs);
            $revenueUsd = Money::add($revenueUsd, $priceUsd);
            $costUsd = Money::add($costUsd, $costLineUsd);

            if (($item->sale?->currency_code ?? 'ARS') === 'ARS') {
                $marginArs = Money::add($marginArs, $margin);
            } else {
                $marginUsd = Money::add($marginUsd, $margin);
            }

            $rows[] = [
                'source' => 'sale',
                'source_label' => 'Venta',
                'ref' => $item->sale?->number,
                'date' => $item->sale?->sold_on?->toDateString(),
                'client' => $item->sale?->client?->name,
                'description' => $item->description,
                'product' => $item->product?->name,
                'quantity' => Money::normalize((string) $item->quantity, 4),
                'price_ars' => $priceArs,
                'cost_ars' => $costLineArs,
                'margin' => $margin,
                'currency' => $item->sale?->currency_code,
            ];
        }

        $workOrders = WorkOrder::query()
            ->with('client')
            ->where('status', WorkOrderStatus::Closed->value)
            ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('closed_at', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('closed_at', '<=', $filters['date_to']))
            ->orderBy('closed_at')
            ->orderBy('id')
            ->get();

        foreach ($workOrders as $wo) {
            $priceArs = Money::normalize((string) $wo->total_price_ars);
            $costLineArs = Money::normalize((string) $wo->total_cost_ars);
            $priceUsd = Money::normalize((string) $wo->total_price_usd);
            $costLineUsd = Money::normalize((string) $wo->total_cost_usd);
            $marginArsLine = Money::sub($priceArs, $costLineArs);
            $marginUsdLine = Money::sub($priceUsd, $costLineUsd);

            $revenueArs = Money::add($revenueArs, $priceArs);
            $costArs = Money::add($costArs, $costLineArs);
            $marginArs = Money::add($marginArs, $marginArsLine);
            $revenueUsd = Money::add($revenueUsd, $priceUsd);
            $costUsd = Money::add($costUsd, $costLineUsd);
            $marginUsd = Money::add($marginUsd, $marginUsdLine);

            $rows[] = [
                'source' => 'work_order',
                'source_label' => 'Orden de trabajo',
                'ref' => $wo->number,
                'date' => $wo->closed_at?->toDateString(),
                'client' => $wo->client?->name,
                'description' => $wo->title,
                'product' => null,
                'quantity' => '1.0000',
                'price_ars' => $priceArs,
                'cost_ars' => $costLineArs,
                'margin' => $marginArsLine,
                'currency' => $wo->currency_code,
            ];
        }

        return [
            'rows' => $rows,
            'totals' => [
                'count' => (string) count($rows),
                'revenue_ars' => $revenueArs,
                'cost_ars' => $costArs,
                'margin_ars' => $marginArs,
                'revenue_usd' => $revenueUsd,
                'cost_usd' => $costUsd,
                'margin_usd' => $marginUsd,
            ],
        ];
    }

    /**
     * Agrupa movimientos posted por tipo de cuenta del plan (income/expense/asset/liability/equity).
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function chartAccountsSummary(): array
    {
        $movements = Movement::query()
            ->posted()
            ->with('chartAccount')
            ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value])
            ->get();

        $groups = [
            'income' => ['label' => 'Ingresos', 'amount_ars' => '0.00', 'amount_usd' => '0.00', 'count' => 0],
            'expense' => ['label' => 'Gastos', 'amount_ars' => '0.00', 'amount_usd' => '0.00', 'count' => 0],
            'asset' => ['label' => 'Activos', 'amount_ars' => '0.00', 'amount_usd' => '0.00', 'count' => 0],
            'liability' => ['label' => 'Pasivos', 'amount_ars' => '0.00', 'amount_usd' => '0.00', 'count' => 0],
            'equity' => ['label' => 'Patrimonio', 'amount_ars' => '0.00', 'amount_usd' => '0.00', 'count' => 0],
            'result' => ['label' => 'Resultados', 'amount_ars' => '0.00', 'amount_usd' => '0.00', 'count' => 0],
            // Sin categoría operativa (no “falta mapeo redundante al plan”).
            'unassigned' => ['label' => 'Sin clasificar (sin categoría)', 'amount_ars' => '0.00', 'amount_usd' => '0.00', 'count' => 0],
        ];

        foreach ($movements as $m) {
            if ($m->category_id === null) {
                $type = 'unassigned';
            } else {
                $rawType = $m->chartAccount?->type;
                $type = $rawType instanceof \App\Enums\ChartAccountType
                    ? $rawType->value
                    : ($rawType ?? null);
                // Cat/sub OK sin cuenta contable: agrupar por naturaleza del movimiento, no como incompleto.
                if ($type === null || ! isset($groups[$type])) {
                    $type = ($m->type instanceof MovementType ? $m->type->value : (string) $m->getRawOriginal('type')) === MovementType::Income->value
                        ? 'income'
                        : 'expense';
                }
            }
            $groups[$type]['amount_ars'] = Money::add($groups[$type]['amount_ars'], Money::normalize((string) $m->amount_ars));
            $groups[$type]['amount_usd'] = Money::add($groups[$type]['amount_usd'], Money::normalize((string) $m->amount_usd));
            $groups[$type]['count']++;
        }

        $rows = [];
        foreach ($groups as $key => $group) {
            $rows[] = [
                'type' => $key,
                'label' => $group['label'],
                'count' => $group['count'],
                'amount_ars' => $group['amount_ars'],
                'amount_usd' => $group['amount_usd'],
            ];
        }

        $income = $groups['income']['amount_ars'];
        $expense = $groups['expense']['amount_ars'];

        return [
            'rows' => $rows,
            'totals' => [
                'income_ars' => $income,
                'expense_ars' => $expense,
                'result_ars' => Money::sub($income, $expense),
                'movements' => (string) $movements->count(),
            ],
        ];
    }

    /**
     * Reporte operativo: NATURALEZA → CATEGORÍA → SUBCATEGORÍA × ÁMBITO.
     *
     * @param  array{date_from?: string|null, date_to?: string|null, scope?: string|null, type?: string|null}  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function operationalClassification(array $filters = []): array
    {
        $query = Movement::query()
            ->posted()
            ->with(['category', 'subcategory'])
            ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value]);

        if (! empty($filters['date_from'])) {
            $query->whereDate('movement_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('movement_date', '<=', $filters['date_to']);
        }
        if (! empty($filters['scope'])) {
            $query->where('scope', $filters['scope']);
        }
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }
        if (! empty($filters['subcategory_id'])) {
            $query->where('subcategory_id', (int) $filters['subcategory_id']);
        }

        $movements = $query->get();
        $buckets = [];
        $totalArs = '0.00';
        $totalUsd = '0.00';

        foreach ($movements as $m) {
            $naturaleza = ($m->type instanceof MovementType ? $m->type->value : (string) $m->getRawOriginal('type')) === MovementType::Income->value
                ? 'INGRESO'
                : 'EGRESO';
            $cat = $m->category?->name ?? '(sin categoría)';
            $sub = $m->subcategory?->name ?? '(sin subcategoría)';
            $ambito = $m->scope instanceof \BackedEnum ? $m->scope->value : (string) $m->scope;
            $key = $naturaleza.'|'.$cat.'|'.$sub.'|'.$ambito;
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'naturaleza' => $naturaleza,
                    'categoria' => $cat,
                    'subcategoria' => $sub,
                    'ambito' => $ambito,
                    'count' => 0,
                    'amount_ars' => '0.00',
                    'amount_usd' => '0.00',
                    'category_id' => $m->category_id,
                    'subcategory_id' => $m->subcategory_id,
                ];
            }
            $buckets[$key]['count']++;
            $buckets[$key]['amount_ars'] = Money::add($buckets[$key]['amount_ars'], Money::normalize((string) $m->amount_ars));
            $buckets[$key]['amount_usd'] = Money::add($buckets[$key]['amount_usd'], Money::normalize((string) $m->amount_usd));
            $totalArs = Money::add($totalArs, Money::normalize((string) $m->amount_ars));
            $totalUsd = Money::add($totalUsd, Money::normalize((string) $m->amount_usd));
        }

        usort($buckets, function ($a, $b) {
            return [$a['naturaleza'], $a['categoria'], $a['subcategoria'], $a['ambito']]
                <=> [$b['naturaleza'], $b['categoria'], $b['subcategoria'], $b['ambito']];
        });

        return [
            'rows' => array_values($buckets),
            'totals' => [
                'Movimientos' => (string) $movements->count(),
                'Importe ARS' => $totalArs,
                'Importe USD' => $totalUsd,
            ],
        ];
    }

    /**
     * Catálogo de reportes disponibles (etiquetas en español).
     *
     * @return array<string, array{label: string, method: string}>
     */
    public function catalog(): array
    {
        return [
            'finance_movements' => ['label' => 'Movimientos financieros', 'method' => 'financeMovements'],
            'finance_balances' => ['label' => 'Saldos de cuentas', 'method' => 'financeBalances'],
            'finance_income_expense' => ['label' => 'Ingresos y gastos', 'method' => 'financeIncomeExpense'],
            'clients_receivables' => ['label' => 'Cuentas a cobrar (clientes)', 'method' => 'clientsReceivables'],
            'clients_movements' => ['label' => 'Movimientos de clientes', 'method' => 'clientsMovements'],
            'suppliers_payables' => ['label' => 'Cuentas a pagar (proveedores)', 'method' => 'suppliersPayables'],
            'suppliers_movements' => ['label' => 'Movimientos de proveedores', 'method' => 'suppliersMovements'],
            'stock_current' => ['label' => 'Stock actual', 'method' => 'stockCurrent'],
            'stock_lots' => ['label' => 'Lotes abiertos', 'method' => 'stockLots'],
            'stock_low' => ['label' => 'Stock bajo mínimo', 'method' => 'stockLow'],
            'sales' => ['label' => 'Ventas', 'method' => 'salesReport'],
            'profitability' => ['label' => 'Rentabilidad', 'method' => 'profitability'],
            'chart_accounts' => ['label' => 'Resumen plan de cuentas', 'method' => 'chartAccountsSummary'],
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function run(string $type, array $filters = []): array
    {
        $catalog = $this->catalog();
        if (! isset($catalog[$type])) {
            throw new \InvalidArgumentException('Tipo de reporte no válido.');
        }

        $method = $catalog[$type]['method'];

        return in_array($method, [
            'financeBalances', 'clientsReceivables', 'suppliersPayables',
            'stockCurrent', 'stockLots', 'stockLow', 'chartAccountsSummary',
        ], true)
            ? $this->{$method}()
            : $this->{$method}($filters);
    }
}
