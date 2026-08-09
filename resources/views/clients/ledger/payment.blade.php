<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Pago — {{ $client->name }}</h1></x-slot>
    <form method="POST" action="{{ route('clients.ledger.payment.store', $client) }}" class="ar-card mx-auto max-w-lg space-y-4 p-6">
        @csrf
        <p class="ar-muted text-sm">El pago reduce la CC y genera un <strong>ingreso</strong> en la cuenta financiera (atómico).</p>
        <div>
            <label class="ar-label">Cuenta de cobro</label>
            <select name="financial_account_id" class="ar-input" required>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }} ({{ $account->currency->code }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="ar-label">Importe</label>
            <input type="number" step="0.01" min="0.01" name="amount" class="ar-input" required autofocus>
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
            <a href="{{ route('clients.show', $client) }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button class="ar-btn ar-btn-primary">Registrar pago</button>
        </div>
    </form>
</x-app-layout>
