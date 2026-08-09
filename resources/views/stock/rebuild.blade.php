<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Reconstruir stock</h1></x-slot>
    <form method="POST" action="{{ route('stock.rebuild') }}" class="ar-card mx-auto max-w-lg space-y-4 p-6">
        @csrf
        <p class="ar-muted text-sm">Recalcula cantidades cacheadas y lotes desde movimientos posted. Uso administrativo.</p>
        <div>
            <label class="ar-label">Producto</label>
            <select name="product_id" class="ar-input">
                <option value="">—</option>
                @foreach ($products as $product)
                    <option value="{{ $product->id }}">{{ $product->sku }} — {{ $product->name }}</option>
                @endforeach
            </select>
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="all" value="1"> Reconstruir todos los productos físicos
        </label>
        <div class="flex justify-end gap-2">
            <a href="{{ route('stock.index') }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button class="ar-btn ar-btn-primary">Reconstruir</button>
        </div>
    </form>
</x-app-layout>
