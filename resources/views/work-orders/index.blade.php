<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Órdenes de trabajo</h1>
                <p class="ar-muted text-sm">Servicios, reparaciones y soporte.</p>
            </div>
            @can('work_orders.create')
                <a href="{{ route('work-orders.create') }}" class="ar-btn ar-btn-primary">Nueva OT</a>
            @endcan
        </div>
    </x-slot>
    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead><tr><th>Número</th><th>Cliente</th><th>Tipo</th><th>Título</th><th>Estado</th><th class="text-right">Precio USD</th><th></th></tr></thead>
            <tbody>
                @forelse ($orders as $order)
                    <tr>
                        <td>{{ $order->number }}</td>
                        <td>{{ $order->client->name }}</td>
                        <td>{{ $order->type->name }}</td>
                        <td>{{ $order->title }}</td>
                        <td>{{ $order->status->label() }}</td>
                        <td class="text-right">{{ number_format((float) $order->total_price_usd, 2, ',', '.') }}</td>
                        <td class="text-right"><a href="{{ route('work-orders.show', $order) }}" style="color: var(--ar-brand);">Ver</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="ar-muted py-6 text-center">Sin OT.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $orders->links() }}</div>
</x-app-layout>
