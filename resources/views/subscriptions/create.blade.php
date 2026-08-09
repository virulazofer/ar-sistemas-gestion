<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Nuevo abono</h1></x-slot>
    <form method="POST" action="{{ route('subscriptions.store') }}" class="ar-card mx-auto max-w-2xl space-y-4 p-6">
        @csrf
        <div>
            <label class="ar-label">Cliente</label>
            <select name="client_id" class="ar-input" required>
                @foreach ($clients as $client)
                    <option value="{{ $client->id }}">{{ $client->name }}</option>
                @endforeach
            </select>
        </div>
        <div><label class="ar-label">Nombre</label><input name="name" class="ar-input" required></div>
        <div><label class="ar-label">Descripción</label><textarea name="description" class="ar-input" rows="2"></textarea></div>
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="ar-label">Periodicidad</label>
                <select name="periodicity" class="ar-input" required>
                    @foreach ($periodicities as $p)
                        <option value="{{ $p->value }}">{{ $p->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div><label class="ar-label">Importe</label><input type="number" step="0.01" name="amount" class="ar-input" required></div>
            <div>
                <label class="ar-label">Moneda</label>
                <select name="currency_code" class="ar-input"><option value="USD">USD</option><option value="ARS">ARS</option></select>
            </div>
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
            <div><label class="ar-label">Inicio</label><input type="date" name="starts_on" class="ar-input" value="{{ now()->toDateString() }}" required></div>
            <div><label class="ar-label">Fin (opc.)</label><input type="date" name="ends_on" class="ar-input"></div>
            <div><label class="ar-label">Día facturación</label><input type="number" min="1" max="28" name="billing_day" class="ar-input" value="1"></div>
        </div>
        <div><label class="ar-label">Condiciones</label><textarea name="terms" class="ar-input" rows="2"></textarea></div>
        <div class="flex justify-end gap-2">
            <a href="{{ route('subscriptions.index') }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button class="ar-btn ar-btn-primary">Guardar</button>
        </div>
    </form>
</x-app-layout>
