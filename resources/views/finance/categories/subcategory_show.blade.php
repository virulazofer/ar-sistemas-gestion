<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <h1 class="text-xl font-semibold">{{ $subcategory->name }}</h1>
                <x-page-help topic="categories" />
            </div>
            <a href="{{ route('categories.show', $subcategory->category) }}" class="ar-btn ar-btn-secondary">Volver a categoría</a>
        </div>
    </x-slot>

    <div class="ar-card mb-4 space-y-2 p-4 text-sm">
        <p><strong>Categoría:</strong> {{ $subcategory->category?->name }}</p>
        <p><strong>Plan de cuentas:</strong> {{ $subcategory->chartAccount?->code }} {{ $subcategory->chartAccount?->name ?: '— (hereda / default tipo)' }}</p>
    </div>

    <form method="GET" class="ar-card mb-4 flex flex-wrap items-end gap-3 p-4">
        <div>
            <label class="ar-label">Desde</label>
            <input type="date" name="from" class="ar-input" value="{{ $from->format('Y-m-d') }}">
        </div>
        <div>
            <label class="ar-label">Hasta</label>
            <input type="date" name="to" class="ar-input" value="{{ $to->format('Y-m-d') }}">
        </div>
        <button class="ar-btn ar-btn-secondary">Filtrar período</button>
    </form>

    <div class="mb-4 grid gap-4 sm:grid-cols-3">
        <div class="ar-card p-4">
            <p class="ar-muted text-xs">Total ARS período</p>
            <p class="text-xl font-bold">{{ number_format($sumArs, 2, ',', '.') }}</p>
        </div>
        <div class="ar-card p-4">
            <p class="ar-muted text-xs">Promedio mensual ARS</p>
            <p class="text-xl font-bold">{{ number_format($avgArs, 2, ',', '.') }}</p>
        </div>
        <div class="ar-card p-4">
            <p class="ar-muted text-xs">Por tipo</p>
            <ul class="mt-1 text-sm">
                @forelse ($totals as $type => $row)
                    <li>{{ $type }}: {{ number_format((float) $row->total_ars, 2, ',', '.') }} ARS ({{ $row->cnt }})</li>
                @empty
                    <li class="ar-muted">Sin movimientos</li>
                @endforelse
            </ul>
        </div>
    </div>

    @if ($monthly->isNotEmpty())
        <div class="ar-card mb-4 overflow-x-auto">
            <h2 class="border-b px-4 py-3 font-semibold" style="border-color: var(--ar-border);">Mensual</h2>
            <table class="ar-table">
                <thead><tr><th>Mes</th><th class="text-right">ARS</th><th class="text-right">Movs</th></tr></thead>
                <tbody>
                    @foreach ($monthly as $row)
                        <tr>
                            <td>{{ $row->ym }}</td>
                            <td class="text-right">{{ number_format((float) $row->total_ars, 2, ',', '.') }}</td>
                            <td class="text-right">{{ $row->cnt }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="ar-card overflow-x-auto">
        <h2 class="border-b px-4 py-3 font-semibold" style="border-color: var(--ar-border);">Movimientos del período</h2>
        <table class="ar-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Tipo</th>
                    <th>Cuenta</th>
                    <th>Descripción</th>
                    <th class="text-right">Importe</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($movements as $m)
                    <tr>
                        <td><a href="{{ route('movements.show', $m) }}" style="color: var(--ar-brand);">{{ $m->movement_date?->format('d/m/Y') }}</a></td>
                        <td>{{ $m->type->label() }}</td>
                        <td>{{ $m->account?->name }}</td>
                        <td>{{ $m->description ?: '—' }}</td>
                        <td class="text-right">{{ number_format((float) $m->amount, 2, ',', '.') }} {{ $m->account?->currency?->code }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="ar-muted py-6 text-center">Sin movimientos en el período.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $movements->links() }}</div>
</x-app-layout>
