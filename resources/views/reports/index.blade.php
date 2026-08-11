<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Reportes</h1>
                <p class="ar-muted text-sm">Exportables CSV / XLSX · PDF en reportes clave.</p>
            </div>
            <x-page-help topic="reports" />
        </div>
    </x-slot>

    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        @can('reports.finance')
            <div class="ar-card space-y-2 p-4">
                <h2 class="font-semibold">Finanzas</h2>
                <a class="block text-sm" style="color: var(--ar-brand);" href="{{ route('reports.show', 'finance-movements') }}">Movimientos</a>
                <a class="block text-sm" style="color: var(--ar-brand);" href="{{ route('reports.show', 'finance-balances') }}">Saldos</a>
                <a class="block text-sm" style="color: var(--ar-brand);" href="{{ route('reports.show', 'finance-income-expense') }}">Ingresos / Egresos</a>
                <a class="block text-sm" style="color: var(--ar-brand);" href="{{ route('reports.show', 'chart-accounts') }}">Plan de cuentas (resumen)</a>
                <a class="block text-sm" style="color: var(--ar-brand);" href="{{ route('chart-accounts.index') }}">Plan de cuentas (árbol + totales)</a>
                <a class="block text-sm" style="color: var(--ar-brand);" href="{{ route('reports.show', ['type' => 'finance-movements', 'chart_account_id' => 'unassigned']) }}">Movimientos sin cuenta contable</a>
            </div>
        @endcan
        @can('reports.clients')
            <div class="ar-card space-y-2 p-4">
                <h2 class="font-semibold">Clientes</h2>
                <a class="block text-sm" style="color: var(--ar-brand);" href="{{ route('reports.show', 'clients-receivables') }}">Cuentas por cobrar</a>
                <a class="block text-sm" style="color: var(--ar-brand);" href="{{ route('reports.show', 'clients-movements') }}">Movimientos</a>
            </div>
        @endcan
        @can('reports.suppliers')
            <div class="ar-card space-y-2 p-4">
                <h2 class="font-semibold">Proveedores</h2>
                <a class="block text-sm" style="color: var(--ar-brand);" href="{{ route('reports.show', 'suppliers-payables') }}">Cuentas por pagar</a>
                <a class="block text-sm" style="color: var(--ar-brand);" href="{{ route('reports.show', 'suppliers-movements') }}">Movimientos</a>
            </div>
        @endcan
        @can('reports.stock')
            <div class="ar-card space-y-2 p-4">
                <h2 class="font-semibold">Stock</h2>
                <a class="block text-sm" style="color: var(--ar-brand);" href="{{ route('reports.show', 'stock-current') }}">Stock + FIFO</a>
                <a class="block text-sm" style="color: var(--ar-brand);" href="{{ route('reports.show', 'stock-lots') }}">Lotes</a>
                <a class="block text-sm" style="color: var(--ar-brand);" href="{{ route('reports.show', 'stock-low') }}">Bajo mínimo</a>
            </div>
        @endcan
        @can('reports.sales')
            <div class="ar-card space-y-2 p-4">
                <h2 class="font-semibold">Ventas</h2>
                <a class="block text-sm" style="color: var(--ar-brand);" href="{{ route('reports.show', 'sales') }}">Ventas confirmadas</a>
            </div>
        @endcan
        @can('reports.profitability')
            <div class="ar-card space-y-2 p-4">
                <h2 class="font-semibold">Rentabilidad</h2>
                <a class="block text-sm" style="color: var(--ar-brand);" href="{{ route('reports.show', 'profitability') }}">Margen bruto</a>
            </div>
        @endcan
    </div>
</x-app-layout>
