<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-2">
            <h1 class="text-xl font-semibold">Establecer saldo de apertura — {{ $client->name }}</h1>
            <x-page-help topic="clients_cc_opening" />
        </div>
    </x-slot>
    <form
        method="POST"
        action="{{ route('clients.ledger.opening.store', $client) }}"
        class="ar-card mx-auto max-w-lg space-y-4 p-6"
        x-data="{
            balance: @js(old('balance', '')),
            preview() {
                const n = Number(this.balance);
                if (this.balance === '' || Number.isNaN(n) || n === 0) return null;
                return n > 0
                    ? { label: 'A cobrar', hint: 'El cliente nos debe', cls: 'semantic-amount semantic-amount--attention' }
                    : { label: 'A favor', hint: 'Crédito a favor del cliente', cls: 'semantic-amount semantic-amount--favorable' };
            }
        }"
    >
        @csrf
        <p class="ar-muted text-sm">
            Apertura manual auditada (AJUSTE/APERTURA). No elimina movimientos previos ni requiere comprobante.
            Tras el reset comercial, los saldos parten en cero: usá esta pantalla solo si necesitás un saldo inicial explícito.
        </p>
        <div>
            <label class="ar-label">Moneda</label>
            <select name="currency_code" class="ar-input">
                <option value="ARS">ARS</option>
                <option value="USD" selected>USD</option>
            </select>
        </div>
        <div>
            <label class="ar-label">Saldo de apertura (presentación)</label>
            <input type="number" step="0.01" name="balance" class="ar-input" x-model="balance" value="{{ old('balance') }}" required>
            <p class="ar-muted mt-1 text-xs">Positivo = cliente nos debe · Negativo = a favor del cliente</p>
            <template x-if="preview()">
                <p class="mt-2 text-sm font-semibold" :class="preview().cls">
                    Vista previa: <span x-text="preview().label"></span>
                    — <span x-text="preview().hint"></span>
                    (<span x-text="Math.abs(Number(balance)).toFixed(2)"></span>)
                </p>
            </template>
            @error('balance')<p class="text-sm" style="color: var(--ar-danger);">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="ar-label">Fecha</label>
            <input type="date" name="entry_date" class="ar-input" value="{{ old('entry_date', now()->toDateString()) }}" required>
        </div>
        <div>
            <label class="ar-label">Motivo</label>
            <textarea name="reason" class="ar-input" rows="3" required>{{ old('reason') }}</textarea>
        </div>
        <div>
            <label class="ar-label">Descripción</label>
            <input type="text" name="description" class="ar-input" value="{{ old('description', 'APERTURA de cuenta corriente') }}">
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="set_control_desde" value="1" @checked(old('set_control_desde'))>
            Usar la fecha de apertura como <code>control_cc_desde</code> (timeline desde esa fecha)
        </label>
        <div>
            <label class="ar-label">control_cc_desde (opcional, si no marcás lo anterior)</label>
            <input type="date" name="control_cc_desde" class="ar-input" value="{{ old('control_cc_desde', $client->control_cc_desde?->format('Y-m-d')) }}">
        </div>
        <div class="flex justify-end gap-2">
            <a href="{{ route('clients.show', $client) }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button class="ar-btn ar-btn-primary">Establecer saldo de apertura</button>
        </div>
    </form>
</x-app-layout>
