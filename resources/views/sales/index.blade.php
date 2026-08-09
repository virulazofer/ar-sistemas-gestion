<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Ventas</h1>
                <p class="ar-muted text-sm">Confirmación atómica · stock + CC + pago.</p>
            </div>
            @can('sales.create')
                <a href="{{ route('sales.create') }}" class="ar-btn ar-btn-primary">Nueva venta</a>
            @endcan
        </div>
    </x-slot>
    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead><tr><th>Número</th><th>Cliente</th><th>Fecha</th><th>Total</th><th>Costo</th><th>Margen</th><th>Estado</th><th></th></tr></thead>
            <tbody>
                @forelse ($sales as $sale)
                    <tr>
                        <td>{{ $sale->number }}</td>
                        <td>{{ $sale->client->name }}</td>
                        <td>{{ $sale->sold_on?->format('d/m/Y') }}</td>
                        <td>{{ $sale->currency_code }} {{ number_format((float) $sale->total, 2, ',', '.') }}</td>
                        <td>{{ number_format((float) $sale->total_cost, 2, ',', '.') }}</td>
                        <td>{{ number_format((float) $sale->gross_margin, 2, ',', '.') }}</td>
                        <td>{{ $sale->status->label() }}</td>
                        <td class="text-right"><a href="{{ route('sales.show', $sale) }}" style="color: var(--ar-brand);">Ver</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="ar-muted py-6 text-center">Sin ventas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $sales->links() }}</div>
</x-app-layout>
