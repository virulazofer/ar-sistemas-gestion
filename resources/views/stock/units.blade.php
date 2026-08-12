<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Unidades de inventario</h1>
                <p class="ar-muted text-sm">
                    @if ($product)
                        Producto: {{ $product->sku }} — {{ $product->name }}
                    @else
                        Todas las unidades serializadas / trackeadas
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('products.view')
                    <a href="{{ route('products.index') }}" class="ar-btn ar-btn-secondary">Productos</a>
                @endcan
                @if ($product)
                    <a href="{{ route('products.show', $product) }}" class="ar-btn ar-btn-secondary">Ficha producto</a>
                @endif
            </div>
        </div>
    </x-slot>

    <form method="GET" class="mb-4 flex flex-wrap gap-2">
        @if ($productId)
            <input type="hidden" name="product_id" value="{{ $productId }}">
        @endif
        <input type="search" name="q" value="{{ $q }}" class="ar-input" placeholder="Código interno, serial…">
        <button class="ar-btn ar-btn-secondary">Buscar</button>
    </form>

    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Producto</th>
                    <th>Serial fab.</th>
                    <th>Condición</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($units as $unit)
                    <tr>
                        <td>{{ $unit->internal_code }}</td>
                        <td>{{ $unit->product?->sku }} — {{ $unit->product?->name }}</td>
                        <td>{{ $unit->manufacturer_serial ?: '—' }}</td>
                        <td>{{ $unit->condition instanceof \BackedEnum ? $unit->condition->value : $unit->condition }}</td>
                        <td>{{ $unit->status instanceof \BackedEnum ? $unit->status->value : $unit->status }}</td>
                        <td class="text-right">
                            @if ($unit->product)
                                <a href="{{ route('products.show', $unit->product) }}" style="color: var(--ar-brand);">Producto</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="ar-muted py-6 text-center">Sin unidades.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $units->links() }}</div>
</x-app-layout>
