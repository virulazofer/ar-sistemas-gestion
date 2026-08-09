<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">{{ $product->name }}</h1>
                <p class="ar-muted text-sm">{{ $product->sku }} · {{ $product->type->label() }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('products.edit')
                    <a href="{{ route('products.edit', $product) }}" class="ar-btn ar-btn-secondary">Editar</a>
                @endcan
                @if ($product->tracksStock())
                    @can('stock.adjust')
                        <a href="{{ route('stock.adjust.create', $product) }}" class="ar-btn ar-btn-secondary">Ajuste</a>
                    @endcan
                    @can('stock.consume')
                        <a href="{{ route('stock.consume.create', $product) }}" class="ar-btn ar-btn-primary">Consumir FIFO</a>
                    @endcan
                    @can('stock.create')
                        <a href="{{ route('stock.reserve.create', $product) }}" class="ar-btn ar-btn-secondary">Reserva</a>
                    @endcan
                    @can('stock.transfer')
                        <a href="{{ route('stock.transfer.create', $product) }}" class="ar-btn ar-btn-secondary">Transferir</a>
                    @endcan
                @endif
            </div>
        </div>
    </x-slot>

    @if ($product->tracksStock())
        <div class="mb-4 grid gap-4 sm:grid-cols-4">
            <div class="ar-card p-4"><p class="ar-muted text-sm">Actual</p><p class="text-xl font-bold">{{ number_format((float) $snapshot['qty_on_hand'], 4, ',', '.') }}</p></div>
            <div class="ar-card p-4"><p class="ar-muted text-sm">Reservado</p><p class="text-xl font-bold">{{ number_format((float) $snapshot['qty_reserved'], 4, ',', '.') }}</p></div>
            <div class="ar-card p-4"><p class="ar-muted text-sm">Disponible</p><p class="text-xl font-bold">{{ number_format((float) $snapshot['qty_available'], 4, ',', '.') }}</p></div>
            <div class="ar-card p-4"><p class="ar-muted text-sm">Valor FIFO</p><p class="text-sm font-semibold">USD {{ number_format((float) $value['value_usd'], 2, ',', '.') }}</p><p class="ar-muted text-xs">ARS {{ number_format((float) $value['value_ars'], 2, ',', '.') }}</p></div>
        </div>
    @endif

    <div class="ar-card mb-4 space-y-1 p-4 text-sm">
        <p><span class="ar-muted">Categoría:</span> {{ $product->category?->name ?: '—' }} / {{ $product->subcategory?->name ?: '—' }}</p>
        <p><span class="ar-muted">Marca/Modelo:</span> {{ $product->brand ?: '—' }} {{ $product->model }}</p>
        <p><span class="ar-muted">Ubicación:</span> {{ $product->location?->name ?: '—' }} · <span class="ar-muted">Unidad:</span> {{ $product->unit }}</p>
        <p><span class="ar-muted">Mín/Máx:</span> {{ $product->stock_min }} / {{ $product->stock_max ?? '—' }}</p>
        @if ($product->notes)<p class="ar-muted">{{ $product->notes }}</p>@endif
    </div>

    @if ($product->tracksStock())
        <div class="mb-4 grid gap-4 lg:grid-cols-2">
            <div class="ar-card overflow-x-auto">
                <h2 class="border-b px-4 py-3 font-semibold" style="border-color: var(--ar-border);">Lotes</h2>
                <table class="ar-table">
                    <thead><tr><th>#</th><th>Ingreso</th><th class="text-right">Restante</th><th class="text-right">Costo u.</th><th>Estado</th></tr></thead>
                    <tbody>
                        @forelse ($lots as $lot)
                            <tr>
                                <td>{{ $lot->id }}</td>
                                <td>{{ $lot->received_at?->format('d/m/Y') }}</td>
                                <td class="text-right">{{ number_format((float) $lot->qty_remaining, 4, ',', '.') }}</td>
                                <td class="text-right">{{ $lot->currency->code }} {{ number_format((float) $lot->unit_cost, 6, ',', '.') }}</td>
                                <td>{{ $lot->status->value }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="ar-muted py-4 text-center">Sin lotes.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="ar-card overflow-x-auto">
                <h2 class="border-b px-4 py-3 font-semibold" style="border-color: var(--ar-border);">Últimos movimientos</h2>
                <table class="ar-table">
                    <thead><tr><th>Fecha</th><th>Tipo</th><th class="text-right">Cant.</th><th>Estado</th></tr></thead>
                    <tbody>
                        @forelse ($movements as $m)
                            <tr>
                                <td>{{ $m->movement_date?->format('d/m/Y') }}</td>
                                <td>{{ $m->type->label() }}</td>
                                <td class="text-right">{{ number_format((float) $m->quantity, 4, ',', '.') }}</td>
                                <td>{{ $m->status->value }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="ar-muted py-4 text-center">Sin movimientos.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-app-layout>
