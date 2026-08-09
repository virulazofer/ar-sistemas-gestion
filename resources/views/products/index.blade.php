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

    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Cód. prov.</th>
                    <th>PN</th>
                    <th>Nombre</th>
                    <th>Fabricante</th>
                    <th>Familia</th>
                    <th class="text-right">Stock</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($products as $product)
                    <tr>
                        <td>{{ $product->sku }}</td>
                        <td>{{ $product->supplier_code ?: '—' }}</td>
                        <td>{{ $product->part_number ?: '—' }}</td>
                        <td>{{ $product->name }}</td>
                        <td>{{ $product->brand ?: '—' }}</td>
                        <td>{{ $product->category?->name ?: '—' }}</td>
                        <td class="text-right">{{ $product->tracksStock() ? number_format((float) $product->qty_on_hand, 4, ',', '.') : '—' }}</td>
                        <td class="text-right"><a href="{{ route('products.show', $product) }}" style="color: var(--ar-brand);">Ver</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="ar-muted py-6 text-center">Sin productos.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $products->links() }}</div>
</x-app-layout>
