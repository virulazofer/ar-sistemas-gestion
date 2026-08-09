<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">Búsqueda</h1>
    </x-slot>
    <form method="GET" class="ar-card mb-4 flex gap-2 p-4">
        <input name="q" class="ar-input" value="{{ $q }}" placeholder="Cliente, producto, OT, venta…" autofocus>
        <button class="ar-btn ar-btn-primary">Buscar</button>
    </form>

    @php
        $groupLabels = [
            'clients' => 'Clientes',
            'suppliers' => 'Proveedores',
            'products' => 'Productos',
            'equipment' => 'Equipos',
            'work_orders' => 'Órdenes de trabajo',
            'quotations' => 'Presupuestos',
            'sales' => 'Ventas',
        ];
    @endphp
    @foreach ($results as $group => $items)
        @if (count($items))
            <div class="ar-card mb-4 p-4">
                <h2 class="mb-2 font-semibold">{{ $groupLabels[$group] ?? str_replace('_', ' ', $group) }}</h2>
                <ul class="space-y-2 text-sm">
                    @foreach ($items as $item)
                        <li>
                            <a href="{{ $item['url'] ?? route($item['route'], $item['params']) }}" style="color: var(--ar-brand);">{{ $item['label'] }}</a>
                            @if ($item['subtitle'])
                                <span class="ar-muted"> — {{ $item['subtitle'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endforeach

    @if ($q !== '' && collect($results)->flatten(1)->isEmpty())
        <p class="ar-muted">Sin resultados.</p>
    @endif
</x-app-layout>
