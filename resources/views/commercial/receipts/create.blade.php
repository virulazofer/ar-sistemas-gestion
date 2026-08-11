<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">Registrar cobro</h1>
        <p class="ar-muted text-sm">Seleccione cliente e importe. Si no hay deuda suficiente, elija A/B/C.</p>
    </x-slot>

    @if ($decision)
        <div class="ar-card mb-4 border p-4" style="border-color: var(--ar-danger, #b91c1c);">
            <p class="font-semibold">{{ $decision['message'] ?? 'No existe deuda suficiente para aplicar este cobro.' }}</p>
            <p class="ar-muted text-sm mt-1">Deuda abierta: {{ number_format((float) ($decision['open_debt'] ?? 0), 2, ',', '.') }} · Cobro: {{ number_format((float) ($decision['amount'] ?? 0), 2, ',', '.') }}</p>
            <p class="mt-2 text-sm">Opciones: <strong>A</strong> Crear cargo faltante y aplicar · <strong>B</strong> Pago a cuenta · <strong>C</strong> Cancelar. Nunca se inventa factura.</p>
        </div>
    @endif

    <form method="POST" action="{{ route('receipts.store') }}" class="ar-card mx-auto max-w-3xl space-y-4 p-6">
        @csrf
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="ar-label">Cliente</label>
                <select name="client_id" class="ar-input" required onchange="window.location='{{ route('receipts.create') }}?client_id='+this.value">
                    <option value="">Seleccionar…</option>
                    @foreach ($clients as $c)
                        <option value="{{ $c->id }}" @selected(old('client_id', $preselect) == $c->id)>{{ $c->labelWithCode() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ar-label">Cuenta receptora</label>
                <select name="financial_account_id" class="ar-input" required>
                    @foreach ($accounts as $a)
                        <option value="{{ $a->id }}" @selected(old('financial_account_id') == $a->id)>{{ $a->name }} ({{ $a->currency?->code }})</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="ar-label">Importe</label>
                <input type="number" step="0.01" min="0.01" name="amount" class="ar-input" value="{{ old('amount', $decision['amount'] ?? '') }}" required>
            </div>
            <div>
                <label class="ar-label">Fecha</label>
                <input type="date" name="received_on" class="ar-input" value="{{ old('received_on', now()->toDateString()) }}" required>
            </div>
            <div>
                <label class="ar-label">Aplicación</label>
                <select name="application_mode" class="ar-input">
                    <option value="auto" @selected(old('application_mode', 'auto') === 'auto')>Automática por antigüedad</option>
                    <option value="manual" @selected(old('application_mode') === 'manual')>Manual</option>
                </select>
            </div>
        </div>
        <div>
            <label class="ar-label">Concepto / observación</label>
            <input type="text" name="concept" class="ar-input" value="{{ old('concept') }}">
        </div>

        <div class="rounded border p-3 text-sm" style="border-color: var(--ar-border);">
            <p class="font-medium">Cargos abiertos ({{ $currencyHint }}): deuda {{ number_format((float) $openDebt, 2, ',', '.') }}</p>
            @if ($openCharges->isEmpty())
                <p class="ar-muted mt-1">Sin cargos abiertos en esta moneda.</p>
            @else
                <ul class="mt-2 space-y-1">
                    @foreach ($openCharges as $i => $ch)
                        <li class="flex flex-wrap items-center justify-between gap-2">
                            <span>{{ $ch->charged_on?->format('d/m/Y') }} · {{ $ch->concept }} · abierto {{ number_format((float) $ch->amount_open, 2, ',', '.') }}</span>
                            <input type="hidden" name="applications[{{ $i }}][commercial_charge_id]" value="{{ $ch->id }}">
                            <input type="number" step="0.01" min="0" name="applications[{{ $i }}][amount]" class="ar-input w-32" placeholder="Manual" value="{{ old('applications.'.$i.'.amount') }}">
                        </li>
                    @endforeach
                </ul>
                <p class="ar-muted mt-1 text-xs">En modo automático se ignoran los importes manuales vacíos; complete importes solo en modo manual.</p>
            @endif
        </div>

        @if ($decision)
            <div class="space-y-3 rounded border p-3" style="border-color: var(--ar-border);">
                <label class="flex items-center gap-2 text-sm"><input type="radio" name="insufficient_option" value="create_charge" required> A) Crear cargo faltante y aplicar cobro</label>
                <div class="ms-6 grid gap-2 sm:grid-cols-2">
                    <select name="missing_charge[charge_type]" class="ar-input">
                        @foreach ($types as $t)
                            <option value="{{ $t->value }}">{{ $t->label() }}</option>
                        @endforeach
                    </select>
                    <input name="missing_charge[concept]" class="ar-input" placeholder="Concepto del cargo faltante">
                    <input type="date" name="missing_charge[charged_on]" class="ar-input" value="{{ now()->toDateString() }}">
                    <select name="missing_charge[documental_status]" class="ar-input">
                        @foreach ($documentalStatuses as $ds)
                            <option value="{{ $ds->value }}" @selected($ds->value === 'pending')>{{ $ds->label() }}</option>
                        @endforeach
                    </select>
                    <input type="hidden" name="missing_charge[scope]" value="professional">
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="radio" name="insufficient_option" value="on_account"> B) Registrar como pago a cuenta / saldo a favor</label>
                <label class="flex items-center gap-2 text-sm"><input type="radio" name="insufficient_option" value="cancel"> C) Cancelar</label>
            </div>
        @endif

        <div class="flex gap-2">
            <button class="ar-btn ar-btn-primary">Confirmar cobro</button>
            <a href="{{ route('receipts.index') }}" class="ar-btn ar-btn-secondary">Volver</a>
        </div>
    </form>
</x-app-layout>
