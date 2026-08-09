<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Movimientos de inventario</h1></x-slot>
    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead><tr><th>ID</th><th>Fecha</th><th>Producto</th><th>Tipo</th><th class="text-right">Cant.</th><th class="text-right">Costo</th><th>Estado</th></tr></thead>
            <tbody>
                @forelse ($movements as $m)
                    <tr>
                        <td>{{ $m->id }}</td>
                        <td>{{ $m->movement_date?->format('d/m/Y') }}</td>
                        <td>{{ $m->product->sku }}</td>
                        <td>{{ $m->type->label() }}</td>
                        <td class="text-right">{{ number_format((float) $m->quantity, 4, ',', '.') }}</td>
                        <td class="text-right">{{ $m->total_cost_usd !== null ? 'USD '.number_format((float) $m->total_cost_usd, 2, ',', '.') : '—' }}</td>
                        <td>{{ $m->status->value }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="ar-muted py-6 text-center">Sin movimientos.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $movements->links() }}</div>
</x-app-layout>
