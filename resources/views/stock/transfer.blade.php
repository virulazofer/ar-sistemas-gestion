<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Transferencia — {{ $product->name }}</h1></x-slot>
    <form method="POST" action="{{ route('stock.transfer.store', $product) }}" class="ar-card mx-auto max-w-lg space-y-4 p-6">
        @csrf
        <div>
            <label class="ar-label">Desde</label>
            <select name="inventory_location_id" class="ar-input" required>
                @foreach ($locations as $location)
                    <option value="{{ $location->id }}" @selected($product->inventory_location_id === $location->id)>{{ $location->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="ar-label">Hacia</label>
            <select name="inventory_location_to_id" class="ar-input" required>
                @foreach ($locations as $location)
                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                @endforeach
            </select>
        </div>
        <div><label class="ar-label">Cantidad</label><input type="number" step="0.0001" min="0.0001" name="quantity" class="ar-input" required></div>
        <div><label class="ar-label">Motivo</label><input name="reason" class="ar-input"></div>
        <div><label class="ar-label">Fecha</label><input type="date" name="movement_date" class="ar-input" value="{{ now()->toDateString() }}" required></div>
        <div class="flex justify-end gap-2">
            <a href="{{ route('products.show', $product) }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button class="ar-btn ar-btn-primary">Transferir</button>
        </div>
    </form>
</x-app-layout>
