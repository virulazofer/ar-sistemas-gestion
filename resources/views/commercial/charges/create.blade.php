<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">Nuevo cargo al cliente</h1>
        <p class="ar-muted text-sm">Genera cargo comercial + CC IN. No crea ingreso financiero ni factura.</p>
    </x-slot>

    <form method="POST" action="{{ route('charges.store') }}" class="ar-card mx-auto max-w-2xl space-y-4 p-6">
        @csrf
        <div>
            <label class="ar-label">Cliente</label>
            <select name="client_id" class="ar-input" required>
                <option value="">Seleccionar…</option>
                @foreach ($clients as $c)
                    <option value="{{ $c->id }}" @selected(old('client_id', $preselect) == $c->id)>{{ $c->labelWithCode() }}</option>
                @endforeach
            </select>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="ar-label">Tipo</label>
                <select name="charge_type" class="ar-input" required>
                    @foreach ($types as $t)
                        <option value="{{ $t->value }}" @selected(old('charge_type') === $t->value)>{{ $t->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ar-label">Ámbito</label>
                <select name="scope" class="ar-input" required>
                    <option value="professional" @selected(old('scope', 'professional') === 'professional')>Profesional</option>
                    <option value="personal" @selected(old('scope') === 'personal')>Personal</option>
                </select>
            </div>
        </div>
        <div>
            <label class="ar-label">Concepto</label>
            <input type="text" name="concept" class="ar-input" value="{{ old('concept') }}" required>
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="ar-label">Importe</label>
                <input type="number" step="0.01" min="0.01" name="amount" class="ar-input" value="{{ old('amount') }}" required>
            </div>
            <div>
                <label class="ar-label">Moneda</label>
                <select name="currency_code" class="ar-input" required>
                    <option value="ARS" @selected(old('currency_code', 'ARS') === 'ARS')>ARS</option>
                    <option value="USD" @selected(old('currency_code') === 'USD')>USD</option>
                </select>
            </div>
            <div>
                <label class="ar-label">Fecha</label>
                <input type="date" name="charged_on" class="ar-input" value="{{ old('charged_on', now()->toDateString()) }}" required>
            </div>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="ar-label">Vencimiento (opcional)</label>
                <input type="date" name="due_on" class="ar-input" value="{{ old('due_on') }}">
            </div>
            <div>
                <label class="ar-label">Estado documental</label>
                <select name="documental_status" class="ar-input" required>
                    @foreach ($documentalStatuses as $ds)
                        <option value="{{ $ds->value }}" @selected(old('documental_status', 'none') === $ds->value)>{{ $ds->label() }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div>
            <label class="ar-label">Observaciones</label>
            <textarea name="notes" class="ar-input" rows="2">{{ old('notes') }}</textarea>
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="apply_available_credit" value="1" @checked(old('apply_available_credit', true))>
            Aplicar saldo a favor disponible
        </label>
        <div class="flex gap-2">
            <button class="ar-btn ar-btn-primary">Crear cargo</button>
            <a href="{{ route('charges.index') }}" class="ar-btn ar-btn-secondary">Cancelar</a>
        </div>
    </form>
</x-app-layout>
