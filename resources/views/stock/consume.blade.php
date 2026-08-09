<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Consumo FIFO — {{ $product->name }}</h1></x-slot>
    <form method="POST" action="{{ route('stock.consume.store', $product) }}" class="ar-card mx-auto max-w-lg space-y-4 p-6">
        @csrf
        <p class="ar-muted text-sm">Disponible: {{ number_format((float) $product->qtyAvailable(), 4, ',', '.') }} {{ $product->unit }}</p>
        <div><label class="ar-label">Cantidad</label><input type="number" step="0.0001" min="0.0001" name="quantity" class="ar-input" required></div>
        <div><label class="ar-label">Motivo</label><input name="reason" class="ar-input" required></div>
        <div><label class="ar-label">Fecha</label><input type="date" name="movement_date" class="ar-input" value="{{ now()->toDateString() }}" required></div>
        <div><label class="ar-label">Notas</label><textarea name="notes" class="ar-input" rows="2"></textarea></div>
        <div class="flex justify-end gap-2">
            <a href="{{ route('products.show', $product) }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button class="ar-btn ar-btn-primary">Consumir</button>
        </div>
    </form>
</x-app-layout>
