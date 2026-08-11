<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <h1 class="text-xl font-semibold">Plan de cuentas</h1>
                <x-page-help topic="chart_accounts" />
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('chart-accounts.map') }}" class="ar-btn ar-btn-secondary text-xs">Mapa</a>
                <a href="{{ route('chart-accounts.mapping') }}" class="ar-btn ar-btn-secondary text-xs">Mapeo categorías</a>
                @can('categories.create')
                    <a href="{{ route('chart-accounts.create') }}" class="ar-btn ar-btn-primary">Nueva cuenta</a>
                @endcan
            </div>
        </div>
    </x-slot>

    <p class="ar-muted mb-4 text-sm">
        <strong>Cuenta contable</strong> (este plan) ≠ <strong>categoría operativa</strong> ≠ <strong>cuenta financiera</strong> (caja/banco/tarjeta).
        Precedencia de mapeo: subcategoría → categoría → tipo de movimiento → sin asignar.
    </p>

    @if ($unassignedMovements > 0)
        <div class="mb-4 rounded border p-3 text-sm" style="border-color: var(--ar-danger, #b91c1c); color: var(--ar-danger, #b91c1c);">
            <strong>{{ $unassignedMovements }}</strong> movimiento(s) posted sin cuenta contable.
            <a href="{{ route('chart-accounts.mapping') }}" class="underline">Abrir asistente de mapeo</a>
            · categorías/subs sin mapear: {{ $assistant['total_unmapped'] }}
        </div>
    @endif

    <form method="GET" class="ar-card mb-4 flex flex-wrap items-end gap-3 p-4">
        <div>
            <label class="ar-label">Desde</label>
            <input type="date" name="from" class="ar-input" value="{{ $dateFrom }}">
        </div>
        <div>
            <label class="ar-label">Hasta</label>
            <input type="date" name="to" class="ar-input" value="{{ $dateTo }}">
        </div>
        <button class="ar-btn ar-btn-secondary">Filtrar totales</button>
    </form>

    <div class="mb-6 space-y-2" x-data="{ open: {} }">
        <h2 class="font-semibold">Árbol con totales reales</h2>
        @forelse ($tree as $node)
            @include('finance.chart_accounts._tree_totals', ['node' => $node, 'depth' => 0])
        @empty
            <p class="ar-muted text-sm">Sin cuentas en el plan.</p>
        @endforelse
    </div>

    <div class="space-y-3">
        <h2 class="font-semibold">Gestión</h2>
        @foreach ($roots as $root)
            @include('finance.chart_accounts._node', ['account' => $root, 'depth' => 0])
        @endforeach
    </div>
</x-app-layout>
