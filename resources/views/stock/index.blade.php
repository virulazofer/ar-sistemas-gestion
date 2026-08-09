<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Stock</h1>
                <p class="ar-muted text-sm">Valor FIFO histórico · USD {{ number_format((float) $value['value_usd'], 2, ',', '.') }} · ARS {{ number_format((float) $value['value_ars'], 2, ',', '.') }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('stock.movements') }}" class="ar-btn ar-btn-secondary">Movimientos</a>
                @can('stock.rebuild')
                    <a href="{{ route('stock.rebuild.form') }}" class="ar-btn ar-btn-secondary">Reconstruir</a>
                @endcan
            </div>
        </div>
    </x-slot>
    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead><tr><th>SKU</th><th>Producto</th><th class="text-right">Actual</th><th class="text-right">Reservado</th><th class="text-right">Disponible</th><th></th></tr></thead>
            <tbody>
                @foreach ($products as $product)
                    <tr>
                        <td>{{ $product->sku }}</td>
                        <td>{{ $product->name }}</td>
                        <td class="text-right">{{ number_format((float) $product->qty_on_hand, 4, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) $product->qty_reserved, 4, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) $product->qtyAvailable(), 4, ',', '.') }}</td>
                        <td class="text-right"><a href="{{ route('products.show', $product) }}" style="color: var(--ar-brand);">Ver</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $products->links() }}</div>
</x-app-layout>
