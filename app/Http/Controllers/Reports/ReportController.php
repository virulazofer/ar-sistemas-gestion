<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Exports\ExportService;
use App\Services\Reports\ReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly ExportService $exports,
    ) {}

    public function index(): View
    {
        return view('reports.index');
    }

    public function show(Request $request, string $type): View|Response
    {
        [$title, $permission, $columns, $result] = $this->resolve($type, $request->all());
        abort_unless(auth()->user()?->can($permission) || auth()->user()?->can('reports.view'), 403);

        $headers = array_values($columns);
        $keys = array_keys($columns);
        $exportRows = [];
        foreach ($result['rows'] as $row) {
            $exportRows[] = array_map(fn ($k) => $row[$k] ?? '', $keys);
        }

        $format = $request->query('export');
        if ($format) {
            abort_unless(auth()->user()?->can('exports.execute') || auth()->user()?->can('reports.export'), 403);

            return match ($format) {
                'csv' => $this->exports->toCsv($type.'.csv', $headers, $exportRows),
                'xlsx' => $this->exports->toXlsx($type.'.xlsx', $headers, $exportRows),
                'pdf' => in_array($type, ['finance-balances', 'clients-receivables', 'profitability', 'stock-current'], true)
                    ? $this->exports->toPdf($title, $headers, $exportRows)
                    : abort(400, 'PDF no disponible para este reporte.'),
                default => abort(400),
            };
        }

        return view('reports.show', [
            'type' => $type,
            'title' => $title,
            'columns' => $columns,
            'rows' => $result['rows'],
            'totals' => $result['totals'] ?? [],
            'filters' => $request->all(),
            'note' => $result['note'] ?? null,
        ]);
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, string>, 3: array}
     */
    private function resolve(string $type, array $filters): array
    {
        return match ($type) {
            'finance-movements' => [
                'Movimientos financieros',
                'reports.finance',
                [
                    'date' => 'Fecha', 'type_label' => 'Tipo', 'scope_label' => 'Ámbito', 'account' => 'Cuenta',
                    'currency' => 'Moneda', 'amount' => 'Importe', 'category' => 'Categoría', 'user' => 'Usuario', 'description' => 'Descripción',
                ],
                $this->reports->financeMovements($filters),
            ],
            'finance-balances' => [
                'Saldos por cuenta',
                'reports.finance',
                ['name' => 'Cuenta', 'type_label' => 'Tipo', 'currency' => 'Moneda', 'computed_balance' => 'Saldo'],
                $this->reports->financeBalances(),
            ],
            'finance-income-expense' => [
                'Ingresos / Egresos / Transferencias',
                'reports.finance',
                ['type_label' => 'Concepto', 'scope' => 'Ámbito', 'currency' => 'Moneda', 'amount' => 'Importe'],
                $this->reports->financeIncomeExpense($filters),
            ],
            'clients-receivables' => [
                'Cuentas por cobrar',
                'reports.clients',
                ['name' => 'Cliente', 'balance_ars' => 'ARS', 'balance_usd' => 'USD'],
                $this->normalizeBalances($this->reports->clientsReceivables()),
            ],
            'clients-movements' => [
                'Movimientos de clientes',
                'reports.clients',
                [
                    'date' => 'Fecha', 'client' => 'Cliente', 'type_label' => 'Tipo',
                    'currency' => 'Moneda', 'amount' => 'Importe', 'description' => 'Descripción',
                ],
                $this->reports->clientsMovements($filters),
            ],
            'suppliers-payables' => [
                'Cuentas por pagar',
                'reports.suppliers',
                ['name' => 'Proveedor', 'balance_ars' => 'ARS', 'balance_usd' => 'USD'],
                $this->normalizeBalances($this->reports->suppliersPayables()),
            ],
            'suppliers-movements' => [
                'Movimientos de proveedores',
                'reports.suppliers',
                [
                    'date' => 'Fecha', 'supplier' => 'Proveedor', 'type_label' => 'Tipo',
                    'currency' => 'Moneda', 'amount' => 'Importe', 'description' => 'Descripción',
                ],
                $this->reports->suppliersMovements($filters),
            ],
            'stock-current' => [
                'Stock actual (valorización FIFO)',
                'reports.stock',
                [
                    'sku' => 'SKU', 'name' => 'Producto', 'qty_on_hand' => 'Cantidad',
                    'value_ars' => 'Valor ARS', 'value_usd' => 'Valor USD',
                ],
                $this->reports->stockCurrent(),
            ],
            'stock-lots' => [
                'Lotes FIFO',
                'reports.stock',
                [
                    'sku' => 'SKU', 'product' => 'Producto', 'lot_id' => 'Lote', 'qty_remaining' => 'Restante',
                    'unit_cost_usd' => 'Costo u. USD', 'received_at' => 'Recibido',
                ],
                $this->reports->stockLots(),
            ],
            'stock-low' => [
                'Productos bajo mínimo',
                'reports.stock',
                ['sku' => 'SKU', 'name' => 'Producto', 'qty_on_hand' => 'Stock', 'stock_min' => 'Mínimo'],
                $this->reports->stockLow(),
            ],
            'sales' => [
                'Ventas confirmadas',
                'reports.sales',
                [
                    'number' => 'Número', 'sold_on' => 'Fecha', 'client' => 'Cliente', 'currency' => 'Moneda',
                    'total' => 'Total', 'total_cost' => 'Costo', 'gross_margin' => 'Margen', 'payment_mode' => 'Modo',
                ],
                $this->normalizeSales($this->reports->salesReport($filters)),
            ],
            'profitability' => [
                'Rentabilidad (margen bruto)',
                'reports.profitability',
                [
                    'source_label' => 'Origen', 'ref' => 'Referencia', 'client' => 'Cliente',
                    'currency' => 'Moneda', 'description' => 'Detalle', 'margin' => 'Margen',
                ],
                $this->reports->profitability($filters),
            ],
            'chart-accounts' => [
                'Plan de cuentas',
                'reports.finance',
                ['type' => 'Tipo', 'code' => 'Código', 'name' => 'Cuenta', 'amount_ars' => 'Importe ARS'],
                $this->normalizeChart($this->reports->chartAccountsSummary()),
            ],
            default => abort(404),
        };
    }

    private function normalizeIncomeExpense(array $result): array
    {
        $rows = [];
        foreach ($result['rows'] ?? $result as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rows[] = [
                'label' => $row['label'] ?? $row['concept'] ?? $row['type_label'] ?? '',
                'scope' => $row['scope'] ?? $row['scope_label'] ?? '',
                'currency' => $row['currency'] ?? 'ARS',
                'amount' => $row['amount'] ?? $row['amount_ars'] ?? '',
            ];
        }

        return ['rows' => $rows, 'totals' => $result['totals'] ?? [], 'note' => $result['note'] ?? null];
    }

    private function normalizeBalances(array $result): array
    {
        $rows = [];
        foreach ($result['rows'] ?? [] as $row) {
            $rows[] = [
                'name' => $row['name'] ?? $row['client'] ?? $row['supplier'] ?? '',
                'balance_ars' => $row['balance_ars'] ?? $row['ars'] ?? $row['receivable_ars'] ?? '0.00',
                'balance_usd' => $row['balance_usd'] ?? $row['usd'] ?? $row['receivable_usd'] ?? '0.00',
            ];
        }

        return ['rows' => $rows, 'totals' => $result['totals'] ?? []];
    }

    private function normalizeSales(array $result): array
    {
        $rows = [];
        foreach ($result['rows'] ?? [] as $row) {
            $rows[] = [
                'number' => $row['number'] ?? '',
                'sold_on' => $row['sold_on'] ?? $row['date'] ?? '',
                'client' => $row['client'] ?? '',
                'currency' => $row['currency'] ?? $row['currency_code'] ?? '',
                'total' => $row['total'] ?? '',
                'total_cost' => $row['total_cost'] ?? $row['cost'] ?? '',
                'gross_margin' => $row['gross_margin'] ?? $row['margin'] ?? '',
                'payment_mode' => $row['payment_mode'] ?? '',
            ];
        }

        return ['rows' => $rows, 'totals' => $result['totals'] ?? [], 'note' => $result['note'] ?? null];
    }

    private function normalizeChart(array $result): array
    {
        $rows = [];
        foreach ($result['rows'] ?? [] as $row) {
            $rows[] = [
                'type' => $row['type'] ?? $row['type_label'] ?? '',
                'code' => $row['code'] ?? '',
                'name' => $row['name'] ?? '',
                'amount_ars' => $row['amount_ars'] ?? $row['total_ars'] ?? '',
            ];
        }

        return ['rows' => $rows, 'totals' => $result['totals'] ?? []];
    }
}
