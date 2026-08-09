<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Presupuestos</h1>
                <p class="ar-muted text-sm">Sin efecto en stock, CC ni finanzas.</p>
            </div>
            @can('quotations.create')
                <a href="{{ route('quotations.create') }}" class="ar-btn ar-btn-primary">Nuevo</a>
            @endcan
        </div>
    </x-slot>
    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead><tr><th>Número</th><th>Cliente</th><th>Fecha</th><th>Vence</th><th>Total</th><th>Estado</th><th></th></tr></thead>
            <tbody>
                @forelse ($quotations as $q)
                    <tr>
                        <td>{{ $q->number }}</td>
                        <td>{{ $q->client->name }}</td>
                        <td>{{ $q->quoted_on?->format('d/m/Y') }}</td>
                        <td>{{ $q->valid_until?->format('d/m/Y') }}</td>
                        <td>{{ $q->currency_code }} {{ number_format((float) $q->total, 2, ',', '.') }}</td>
                        <td>{{ $q->status->label() }}</td>
                        <td class="text-right"><a href="{{ route('quotations.show', $q) }}" style="color: var(--ar-brand);">Ver</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="ar-muted py-6 text-center">Sin presupuestos.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $quotations->links() }}</div>
</x-app-layout>
