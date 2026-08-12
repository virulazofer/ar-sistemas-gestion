<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Stock</h1>
                <p class="ar-muted text-sm">Valor FIFO histórico · USD {{ number_format((float) $value['value_usd'], 2, ',', '.') }} · ARS {{ number_format((float) $value['value_ars'], 2, ',', '.') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-page-help topic="stock" />
                @can('products.view')
                    <a href="{{ route('products.index') }}" class="ar-btn ar-btn-secondary">Productos</a>
                @endcan
                <a href="{{ route('stock.units', array_filter(['product_id' => $productId ?? null])) }}" class="ar-btn ar-btn-secondary">Unidades</a>
                <a href="{{ route('stock.movements') }}" class="ar-btn ar-btn-secondary">Historial</a>
                @can('stock.rebuild')
                    <a href="{{ route('stock.rebuild.form') }}" class="ar-btn ar-btn-secondary">Reconstruir</a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="ar-card mb-4 space-y-2 p-4 text-sm">
        <p>Stock muestra existencias físicas. Los ingresos normalmente vienen de <strong>Compras</strong>. Ajustes, reservas y consumos se operan desde la <strong>ficha del producto</strong> (no desde esta grilla).</p>
        <p class="ar-muted">Desde cero: Maestros → Productos → Nuevo, luego Comprar o abrir el producto para Ingreso/Ajuste/Reserva.</p>
        <div class="flex flex-wrap gap-2 pt-1">
            <span class="ar-muted text-xs">Acciones en ficha de producto:</span>
            <span class="rounded border border-[var(--ar-border)] px-2 py-0.5 text-xs">Ingreso / ajuste +</span>
            <span class="rounded border border-[var(--ar-border)] px-2 py-0.5 text-xs">Ajuste −</span>
            <span class="rounded border border-[var(--ar-border)] px-2 py-0.5 text-xs">Reservar</span>
            <span class="rounded border border-[var(--ar-border)] px-2 py-0.5 text-xs">Liberar</span>
            <span class="rounded border border-[var(--ar-border)] px-2 py-0.5 text-xs">Consumir</span>
        </div>
    </div>

    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead><tr><th>SKU</th><th>Producto</th><th class="text-right">Actual</th><th class="text-right">Reservado</th><th class="text-right">Disponible</th><th></th></tr></thead>
            <tbody>
                @forelse ($products as $product)
                    <tr>
                        <td>{{ $product->sku }}</td>
                        <td>{{ $product->name }}</td>
                        <td class="text-right">{{ number_format((float) $product->qty_on_hand, 4, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) $product->qty_reserved, 4, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) $product->qtyAvailable(), 4, ',', '.') }}</td>
                        <td class="text-right"><a href="{{ route('products.show', $product) }}" style="color: var(--ar-brand);">Operar</a></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="ar-muted py-8 text-center">
                            No hay productos con stock para listar.
                            @can('products.create')
                                <a href="{{ route('products.create') }}" class="ms-1" style="color: var(--ar-brand);">Crear producto</a>
                            @endcan
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $products->links() }}</div>
</x-app-layout>
