<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Productos</h1>
                <p class="ar-muted text-sm">Catálogo físico / servicio · stock denormalizado.</p>
            </div>
            @can('products.create')
                <a href="{{ route('products.create') }}" class="ar-btn ar-btn-primary">Nuevo producto</a>
            @endcan
        </div>
    </x-slot>

    <form method="GET" class="mb-4 flex gap-2">
        <input type="search" name="q" value="{{ $q }}" class="ar-input" placeholder="SKU, nombre, marca…">
        <button class="ar-btn ar-btn-secondary">Buscar</button>
    </form>

    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Nombre</th>
                    <th>Tipo</th>
                    <th class="text-right">Actual</th>
                    <th class="text-right">Reservado</th>
                    <th class="text-right">Disponible</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($products as $product)
                    <tr>
                        <td>{{ $product->sku }}</td>
                        <td>{{ $product->name }}</td>
                        <td>{{ $product->type->label() }}</td>
                        <td class="text-right">{{ $product->tracksStock() ? number_format((float) $product->qty_on_hand, 4, ',', '.') : '—' }}</td>
                        <td class="text-right">{{ $product->tracksStock() ? number_format((float) $product->qty_reserved, 4, ',', '.') : '—' }}</td>
                        <td class="text-right">{{ $product->tracksStock() ? number_format((float) $product->qtyAvailable(), 4, ',', '.') : '—' }}</td>
                        <td class="text-right"><a href="{{ route('products.show', $product) }}" style="color: var(--ar-brand);">Ver</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="ar-muted py-6 text-center">Sin productos.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $products->links() }}</div>
</x-app-layout>
