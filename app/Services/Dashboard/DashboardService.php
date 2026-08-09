<?php

namespace App\Services\Dashboard;

use App\Enums\AccountType;
use App\Enums\EquipmentStatus;
use App\Enums\MovementScope;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Enums\QuotationStatus;
use App\Enums\SaleStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Client;
use App\Models\ClientLedgerEntry;
use App\Models\Equipment;
use App\Models\FinancialAccount;
use App\Models\InventoryMovement;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\WorkOrder;
use App\Services\Finance\BalanceService;
use App\Services\Finance\ExchangeRateService;
use App\Services\Inventory\StockBalanceService;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;

class DashboardService
{
    public function __construct(
        private readonly BalanceService $balances,
        private readonly ExchangeRateService $rates,
        private readonly StockBalanceService $stockBalances,
    ) {}

    /**
     * Snapshot operativo. Cache corto (30s) — no sacrifica consistencia en reportes/export.
     *
     * @return array<string, mixed>
     */
    public function snapshot(?string $scope = 'all'): array
    {
        $scope = in_array($scope, ['personal', 'professional', 'all'], true) ? $scope : 'all';

        return Cache::remember('dashboard.snapshot.'.$scope, 30, function () use ($scope) {
            return $this->build($scope);
        });
    }

    public function clearCache(): void
    {
        foreach (['personal', 'professional', 'all'] as $scope) {
            Cache::forget('dashboard.snapshot.'.$scope);
        }
    }

    private function build(string $scope): array
    {
        // Solo datos serializables: Cache::remember (driver database) no debe guardar
        // modelos Eloquent — al deserializar producen __PHP_Incomplete_Class (HTTP 500).
        $ratePayload = null;
        $rateLabel = null;
        try {
            $info = $this->rates->latestOfficialSell(false);
            $model = $info['rate'];
            $ratePayload = [
                'rate' => (string) $model->rate,
                'rate_at' => $model->rate_at?->toDateTimeString(),
                'rate_at_label' => $model->rate_at?->format('d/m/Y H:i'),
            ];
            $rateLabel = $info['source_label'];
        } catch (\Throwable) {
        }

        $liquid = $this->liquidByCurrency();
        $clients = $this->clientsSummary();
        $suppliers = $this->suppliersSummary();
        $stock = $this->stockSummary();
        $equipment = $this->equipmentSummary();
        $workOrders = $this->workOrdersSummary();
        $subscriptions = $this->subscriptionsSummary();
        $sales = $this->salesSummary();
        $quotations = $this->quotationsSummary();
        $activity = $this->activitySummary($scope);
        $alerts = $this->alerts($clients, $suppliers, $stock, $workOrders, $subscriptions, $quotations, $equipment);

        return [
            'scope' => $scope,
            'rate' => $ratePayload,
            'rate_label' => $rateLabel,
            'liquid' => $liquid,
            'clients' => $clients,
            'suppliers' => $suppliers,
            'stock' => $stock,
            'equipment' => $equipment,
            'work_orders' => $workOrders,
            'subscriptions' => $subscriptions,
            'sales' => $sales,
            'quotations' => $quotations,
            'activity' => $activity,
            'alerts' => $alerts,
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * @return array{ARS: array{cash: string, bank: string, wallet: string, other: string, total: string}, USD: array{...}}
     */
    public function liquidByCurrency(): array
    {
        $out = [
            'ARS' => ['cash' => '0.00', 'bank' => '0.00', 'wallet' => '0.00', 'other' => '0.00', 'total' => '0.00'],
            'USD' => ['cash' => '0.00', 'bank' => '0.00', 'wallet' => '0.00', 'other' => '0.00', 'total' => '0.00'],
        ];

        $accounts = FinancialAccount::query()->with('currency')->active()->get();
        foreach ($accounts as $account) {
            $code = $account->currency?->code;
            if (! $code || ! isset($out[$code])) {
                continue;
            }
            $bal = $this->balances->computeAccountBalance($account->id);
            $key = match ($account->type) {
                AccountType::Cash => 'cash',
                AccountType::Bank => 'bank',
                AccountType::Wallet => 'wallet',
                default => 'other',
            };
            $out[$code][$key] = Money::add($out[$code][$key], $bal);
            $out[$code]['total'] = Money::add($out[$code]['total'], $bal);
        }

        return $out;
    }

    private function clientsSummary(): array
    {
        $ars = '0.00';
        $usd = '0.00';
        $debtors = [];

        $rows = ClientLedgerEntry::query()
            ->posted()
            ->selectRaw('client_id, currency_id, SUM(signed_amount) as bal')
            ->groupBy('client_id', 'currency_id')
            ->havingRaw('SUM(signed_amount) < 0')
            ->with(['client', 'currency'])
            ->get();

        $debtorIds = [];
        foreach ($rows as $row) {
            $bal = Money::normalize((string) $row->bal);
            $code = $row->currency?->code;
            if (! $code) {
                continue;
            }
            if ($code === 'ARS') {
                $ars = Money::add($ars, Money::mul($bal, '-1'));
            } elseif ($code === 'USD') {
                $usd = Money::add($usd, Money::mul($bal, '-1'));
            }
            $debtorIds[$row->client_id] = true;
            $debtors[] = [
                'client_id' => $row->client_id,
                'name' => $row->client?->name,
                'currency' => $code,
                'debt' => Money::mul($bal, '-1'),
            ];
        }

        usort($debtors, fn ($a, $b) => Money::compare($b['debt'], $a['debt']));

        return [
            'receivable_ars' => $ars,
            'receivable_usd' => $usd,
            'debtors_count' => count($debtorIds),
            'top_debtors' => array_slice($debtors, 0, 5),
        ];
    }

    private function suppliersSummary(): array
    {
        $ars = '0.00';
        $usd = '0.00';
        $pending = [];

        $rows = SupplierLedgerEntry::query()
            ->posted()
            ->selectRaw('supplier_id, currency_id, SUM(signed_amount) as bal')
            ->groupBy('supplier_id', 'currency_id')
            ->havingRaw('SUM(signed_amount) < 0')
            ->with(['supplier', 'currency'])
            ->get();

        foreach ($rows as $row) {
            $bal = Money::normalize((string) $row->bal);
            $debt = Money::mul($bal, '-1');
            $code = $row->currency?->code;
            if (! $code) {
                continue;
            }
            if ($code === 'ARS') {
                $ars = Money::add($ars, $debt);
            } elseif ($code === 'USD') {
                $usd = Money::add($usd, $debt);
            }
            $pending[] = [
                'supplier_id' => $row->supplier_id,
                'name' => $row->supplier?->name,
                'currency' => $code,
                'debt' => $debt,
            ];
        }

        return [
            'payable_ars' => $ars,
            'payable_usd' => $usd,
            'pending_count' => count($pending),
            'pending' => array_slice($pending, 0, 5),
        ];
    }

    private function stockSummary(): array
    {
        $products = Product::query()->where('type', 'physical')->where('status', 'active');
        $count = (clone $products)->count();
        $outOfStock = (clone $products)->where('qty_on_hand', '<=', 0)->count();
        $low = (clone $products)->whereColumn('qty_on_hand', '<', 'stock_min')
            ->where('stock_min', '>', 0)
            ->where('qty_on_hand', '>', 0)
            ->count();
        $value = $this->stockBalances->inventoryValue();

        $mapMovement = static function (InventoryMovement $m): array {
            return [
                'id' => $m->id,
                'product_id' => $m->product_id,
                'product_name' => $m->product?->name,
                'type' => $m->type instanceof \BackedEnum ? $m->type->value : (string) $m->type,
                'quantity' => (string) $m->quantity,
            ];
        };

        $lastIn = InventoryMovement::query()->posted()
            ->whereIn('type', ['receipt', 'adjustment_in', 'transfer_in'])
            ->latest('id')->limit(5)->with('product')->get()
            ->map($mapMovement)->all();
        $lastOut = InventoryMovement::query()->posted()
            ->whereIn('type', ['consume', 'issue', 'adjustment_out', 'transfer_out'])
            ->latest('id')->limit(5)->with('product')->get()
            ->map($mapMovement)->all();

        return [
            'products_count' => $count,
            'out_of_stock' => $outOfStock,
            'below_min' => $low,
            'qty_total' => $value['qty'],
            'value_ars' => $value['value_ars'],
            'value_usd' => $value['value_usd'],
            'lots' => $value['lots'],
            'last_in' => $lastIn,
            'last_out' => $lastOut,
        ];
    }

    private function equipmentSummary(): array
    {
        $counts = [];
        foreach (EquipmentStatus::cases() as $st) {
            $counts[$st->value] = 0;
        }
        foreach (Equipment::query()->selectRaw('status, COUNT(*) as c')->groupBy('status')->get() as $row) {
            $counts[$row->status->value] = (int) $row->c;
        }

        return $counts;
    }

    private function workOrdersSummary(): array
    {
        $counts = [];
        foreach (WorkOrderStatus::cases() as $st) {
            $counts[$st->value] = 0;
        }
        foreach (WorkOrder::query()->selectRaw('status, COUNT(*) as c')->groupBy('status')->get() as $row) {
            $counts[$row->status->value] = (int) $row->c;
        }
        $overdue = WorkOrder::query()
            ->whereNotIn('status', [WorkOrderStatus::Closed->value, WorkOrderStatus::Cancelled->value])
            ->whereDate('opened_at', '<', now()->subDays(7)->toDateString())
            ->count();

        return array_merge($counts, ['overdue' => $overdue]);
    }

    private function subscriptionsSummary(): array
    {
        $active = Subscription::query()->where('status', SubscriptionStatus::Active->value)->count();
        $paused = Subscription::query()->where('status', SubscriptionStatus::Paused->value)->count();
        $cancelled = Subscription::query()->whereIn('status', [
            SubscriptionStatus::Cancelled->value, SubscriptionStatus::Ended->value,
        ])->count();
        $dueSoon = Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->whereDate('next_generation_on', '<=', now()->addDays(7)->toDateString())
            ->count();
        $recentCharges = ClientLedgerEntry::query()
            ->posted()
            ->whereNotNull('subscription_id')
            ->where('entry_date', '>=', now()->subDays(30)->toDateString())
            ->count();

        return compact('active', 'paused', 'cancelled', 'dueSoon', 'recentCharges');
    }

    private function salesSummary(): array
    {
        $from = now()->startOfMonth()->toDateString();
        $sales = Sale::query()
            ->where('status', SaleStatus::Confirmed->value)
            ->whereDate('sold_on', '>=', $from)
            ->get();

        $ars = '0.00';
        $usd = '0.00';
        $marginArs = '0.00';
        $marginUsd = '0.00';
        foreach ($sales as $sale) {
            if ($sale->currency_code === 'ARS') {
                $ars = Money::add($ars, (string) $sale->total);
                $marginArs = Money::add($marginArs, (string) $sale->gross_margin);
            } else {
                $usd = Money::add($usd, (string) $sale->total);
                $marginUsd = Money::add($marginUsd, (string) $sale->gross_margin);
            }
        }

        // Ventas confirmadas a crédito (cobro pendiente en CC)
        $pending = Sale::query()
            ->where('status', SaleStatus::Confirmed->value)
            ->where('payment_mode', 'credit')
            ->count();

        return [
            'count' => $sales->count(),
            'total_ars' => $ars,
            'total_usd' => $usd,
            'margin_ars' => $marginArs,
            'margin_usd' => $marginUsd,
            'pending_collection' => $pending,
            'note' => 'Totales del mes corriente; monedas separadas (no se suman ARS+USD).',
        ];
    }

    private function quotationsSummary(): array
    {
        $counts = [];
        foreach (QuotationStatus::cases() as $st) {
            $counts[$st->value] = 0;
        }
        foreach (Quotation::query()->selectRaw('status, COUNT(*) as c')->groupBy('status')->get() as $row) {
            $counts[$row->status->value] = (int) $row->c;
        }

        return $counts;
    }

    private function activitySummary(string $scope): array
    {
        $month = $this->balances->monthlyActivity();
        $query = Movement::query()
            ->posted()
            ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value])
            ->whereMonth('movement_date', now()->month)
            ->whereYear('movement_date', now()->year);

        if ($scope === 'personal') {
            $query->where('scope', MovementScope::Personal->value);
        } elseif ($scope === 'professional') {
            $query->where('scope', MovementScope::Professional->value);
        }

        $income = '0.00';
        $expense = '0.00';
        foreach ($query->get(['type', 'amount_ars']) as $row) {
            if ($row->type === MovementType::Income) {
                $income = Money::add($income, (string) $row->amount_ars);
            } else {
                $expense = Money::add($expense, (string) $row->amount_ars);
            }
        }

        return [
            'filter' => $scope,
            'income_ars' => $income,
            'expense_ars' => $expense,
            'result_ars' => Money::sub($income, $expense),
            'month_all' => $month,
            'note' => 'Resultado del mes en equivalente ARS congelado del movimiento. Cuentas líquidas son compartidas.',
        ];
    }

    private function alerts(array $clients, array $suppliers, array $stock, array $wo, array $subs, array $quotes, array $eq): array
    {
        $items = [];
        if ($stock['out_of_stock'] > 0) {
            $items[] = ['level' => 'danger', 'text' => $stock['out_of_stock'].' productos sin stock', 'url' => route('stock.index')];
        }
        if ($stock['below_min'] > 0) {
            $items[] = ['level' => 'warn', 'text' => $stock['below_min'].' productos bajo mínimo', 'url' => route('stock.index')];
        }
        if ($clients['debtors_count'] > 0) {
            $items[] = ['level' => 'warn', 'text' => $clients['debtors_count'].' clientes con deuda', 'url' => route('clients.index')];
        }
        if ($suppliers['pending_count'] > 0) {
            $items[] = ['level' => 'warn', 'text' => $suppliers['pending_count'].' proveedores con saldo', 'url' => route('suppliers.index')];
        }
        if (($subs['dueSoon'] ?? 0) > 0) {
            $items[] = ['level' => 'info', 'text' => $subs['dueSoon'].' abonos próximos a generar', 'url' => route('subscriptions.index')];
        }
        if (($wo['overdue'] ?? 0) > 0) {
            $items[] = ['level' => 'warn', 'text' => $wo['overdue'].' OT atrasadas (>7 días)', 'url' => route('work-orders.index')];
        }
        if (($quotes['expired'] ?? 0) > 0) {
            $items[] = ['level' => 'info', 'text' => $quotes['expired'].' presupuestos vencidos', 'url' => route('quotations.index')];
        }
        if (($eq['in_repair'] ?? 0) > 0) {
            $items[] = ['level' => 'info', 'text' => $eq['in_repair'].' equipos en reparación', 'url' => route('equipment.index')];
        }

        return $items;
    }
}
