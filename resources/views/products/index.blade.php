<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Productos</h1>
                <p class="ar-muted text-sm">Catálogo físico / servicio · stock denormalizado.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-page-help topic="products" />
                @can('products.create')
                    <a href="{{ route('products.create') }}" class="ar-btn ar-btn-primary">Nuevo producto</a>
                @endcan
            </div>
        </div>
    </x-slot>

    <form method="GET" class="mb-4 grid gap-2 sm:grid-cols-4">
        <input type="search" name="q" value="{{ $q }}" class="ar-input sm:col-span-2" placeholder="SKU, descripción, PN, código proveedor, marca…">
        <select name="brand" class="ar-input">
            <option value="">Fabricante (todos)</option>
            @foreach ($brands as $b)
                <option value="{{ $b }}" @selected($brand === $b)>{{ $b }}</option>
            @endforeach
        </select>
        <select name="product_category_id" class="ar-input">
            <option value="">Familia (todas)</option>
            @foreach ($categories as $cat)
                <option value="{{ $cat->id }}" @selected((int) $categoryId === (int) $cat->id)>{{ $cat->name }}</option>
            @endforeach
        </select>
        <button class="ar-btn ar-btn-secondary sm:col-span-4 sm:w-auto">Buscar / filtrar</button>
    </form>

    <p class="ar-muted mb-2 text-xs">Columna Precio omitida: no hay campo de precio de venta claro en el maestro (solo <code>reference_cost_usd</code>). El precio se define en ventas/presupuestos.</p>

    <form method="POST" action="{{ route('products.bulk-destroy') }}" x-data="{ all: false, ids: [] }"
        @change="all = false">
        @csrf
        @can('products.void')
            <div class="mb-2 flex flex-wrap items-center gap-2">
                <button type="submit" class="ar-btn ar-btn-secondary text-xs"
                    onclick="return confirm('Eliminar seleccionados; si tienen relaciones se archivan.')">
                    Eliminar / archivar selección
                </button>
                <label class="text-xs"><input type="checkbox" @click="
                    document.querySelectorAll('[data-product-check]').forEach(el => el.checked = $el.checked);
                "> Seleccionar visibles</label>
            </div>
        @endcan

        <div class="ar-card overflow-x-auto">
            <table class="ar-table">
                <thead>
                    <tr>
                        <th></th>
                        <th>SKU</th>
                        <th>Familia</th>
                        <th>Nombre</th>
                        <th class="text-right">Stock</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($products as $product)
                        <tr>
                            <td>
                                @can('products.void')
                                    <input type="checkbox" name="ids[]" value="{{ $product->id }}" data-product-check>
                                @endcan
                            </td>
                            <td>{{ $product->sku }}</td>
                            <td>{{ $product->category?->name ?: '—' }}</td>
                            <td>{{ $product->name }}</td>
                            <td class="text-right">{{ $product->tracksStock() ? number_format((float) $product->qty_on_hand, 4, ',', '.') : '—' }}</td>
                            <td class="text-right"><a href="{{ route('products.show', $product) }}" style="color: var(--ar-brand);">Ver</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="ar-muted py-6 text-center">Sin productos.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </form>
    <div class="mt-4">{{ $products->links() }}</div>
</x-app-layout>
