<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Ajuste — {{ $product->name }}</h1></x-slot>
    <form method="POST" action="{{ route('stock.adjust.store', $product) }}" class="ar-card mx-auto max-w-lg space-y-4 p-6">
        @csrf
        <div>
            <label class="ar-label">Dirección</label>
            <select name="direction" class="ar-input" required>
                <option value="in">Ajuste positivo (+)</option>
                <option value="out">Ajuste negativo (−)</option>
            </select>
        </div>
        <div><label class="ar-label">Cantidad</label><input type="number" step="0.0001" min="0.0001" name="quantity" class="ar-input" required></div>
        <div><label class="ar-label">Motivo</label><input name="reason" class="ar-input" required></div>
        <div><label class="ar-label">Fecha</label><input type="date" name="movement_date" class="ar-input" value="{{ now()->toDateString() }}" required></div>
        <div><label class="ar-label">Notas</label><textarea name="notes" class="ar-input" rows="2"></textarea></div>
        <div class="flex justify-end gap-2">
            <a href="{{ route('products.show', $product) }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button class="ar-btn ar-btn-primary">Registrar</button>
        </div>
    </form>
</x-app-layout>
