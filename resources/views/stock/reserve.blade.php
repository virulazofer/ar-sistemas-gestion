<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Reserva — {{ $product->name }}</h1></x-slot>
    <form method="POST" action="{{ route('stock.reserve.store', $product) }}" class="ar-card mx-auto max-w-lg space-y-4 p-6">
        @csrf
        <p class="ar-muted text-sm">Actual {{ $product->qty_on_hand }} · Reservado {{ $product->qty_reserved }} · Disponible {{ $product->qtyAvailable() }}</p>
        <div>
            <label class="ar-label">Acción</label>
            <select name="action" class="ar-input">
                <option value="reserve">Reservar</option>
                <option value="release">Liberar</option>
            </select>
        </div>
        <div><label class="ar-label">Cantidad</label><input type="number" step="0.0001" min="0.0001" name="quantity" class="ar-input" required></div>
        <div><label class="ar-label">Motivo</label><input name="reason" class="ar-input"></div>
        <div><label class="ar-label">Fecha</label><input type="date" name="movement_date" class="ar-input" value="{{ now()->toDateString() }}" required></div>
        <div class="flex justify-end gap-2">
            <a href="{{ route('products.show', $product) }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button class="ar-btn ar-btn-primary">Confirmar</button>
        </div>
    </form>
</x-app-layout>
