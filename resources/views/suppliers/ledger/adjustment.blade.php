<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Ajuste CC — {{ $supplier->name }}</h1></x-slot>
    <form method="POST" action="{{ route('suppliers.ledger.adjustment.store', $supplier) }}" class="ar-card mx-auto max-w-lg space-y-4 p-6">
        @csrf
        <div>
            <label class="ar-label">Moneda</label>
            <select name="currency_code" class="ar-input" required>
                <option value="ARS">ARS</option>
                <option value="USD">USD</option>
            </select>
        </div>
        <div>
            <label class="ar-label">Importe</label>
            <input type="number" step="0.01" min="0.01" name="amount" class="ar-input" required>
        </div>
        <div>
            <label class="ar-label">Signo</label>
            <select name="sign" class="ar-input" required>
                <option value="-1">− Deuda (aumenta lo que debemos)</option>
                <option value="1">+ A favor (crédito nuestro)</option>
            </select>
        </div>
        <div>
            <label class="ar-label">Motivo</label>
            <textarea name="reason" class="ar-input" rows="3" required></textarea>
        </div>
        <div>
            <label class="ar-label">Fecha</label>
            <input type="date" name="entry_date" class="ar-input" value="{{ now()->toDateString() }}" required>
        </div>
        <div>
            <label class="ar-label">Descripción</label>
            <input type="text" name="description" class="ar-input">
        </div>
        <div class="flex justify-end gap-2">
            <a href="{{ route('suppliers.show', $supplier) }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button class="ar-btn ar-btn-primary">Registrar ajuste</button>
        </div>
    </form>
</x-app-layout>
