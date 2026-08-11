<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <h1 class="text-xl font-semibold">Plan de cuentas</h1>
                <x-page-help topic="chart_accounts" />
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('chart-accounts.map') }}" class="ar-btn ar-btn-secondary text-xs">Mapa</a>
                @can('categories.create')
                    <a href="{{ route('chart-accounts.create') }}" class="ar-btn ar-btn-primary">Nueva cuenta</a>
                @endcan
            </div>
        </div>
    </x-slot>

    <p class="ar-muted mb-4 text-sm">Jerarquía contable. Distinto de categorías operativas y de cuentas financieras (caja/banco).</p>

    <div class="space-y-3">
        @foreach ($roots as $root)
            @include('finance.chart_accounts._node', ['account' => $root, 'depth' => 0])
        @endforeach
    </div>
</x-app-layout>
