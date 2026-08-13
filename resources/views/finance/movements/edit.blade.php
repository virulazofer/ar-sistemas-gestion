<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Editar movimiento</h1>
                <p class="ar-muted text-sm">{{ $movement->displayCode() }} · el código no se puede modificar</p>
            </div>
            <a href="{{ route('movements.show', $movement) }}" class="ar-btn ar-btn-secondary">Cancelar</a>
        </div>
    </x-slot>

    @php
        $conceptsPayload = ($conceptAccounts ?? collect())->map(fn ($c) => [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'type' => $c->type instanceof \BackedEnum ? $c->type->value : (string) $c->type,
            'path' => $c->pathLabel(),
            'suggested_scope' => $c->suggested_scope,
        ])->values();
        $selectedPath = $movement->chartAccount?->pathLabel() ?? '';
    @endphp

    <div
        class="ar-card mx-auto max-w-3xl space-y-4 p-5"
        x-data="{
            type: '{{ old('type', $movement->type->value) }}',
            scope: '{{ old('scope', $movement->scope->value) }}',
            movementDate: '{{ old('movement_date', $movement->movement_date?->toDateString()) }}',
            fxMode: '{{ old('fx_mode', '') }}',
            frozenAt: '{{ $movement->exchange_rate_at?->toDateString() }}',
            get dateMismatch() {
                return this.movementDate && this.frozenAt && this.movementDate !== this.frozenAt
            },
            get scopeLabel() {
                return this.type === 'income' ? 'Origen' : 'Ámbito'
            },
            get scopeOptions() {
                if (this.type === 'income') {
                    return [
                        { value: 'professional', label: 'Profesional' },
                        { value: 'financial', label: 'Financiero' },
                    ]
                }
                if (this.type === 'expense') {
                    return [
                        { value: 'personal', label: 'Personal' },
                        { value: 'professional', label: 'Profesional' },
                        { value: 'mixed', label: 'Mixto' },
                    ]
                }
                return [
                    { value: 'personal', label: 'Personal' },
                    { value: 'professional', label: 'Profesional' },
                    { value: 'mixed', label: 'Mixto' },
                ]
            }
        }"
    >
        @if ($errors->any())
            <div class="rounded border p-3 text-sm" style="border-color: var(--ar-danger); color: var(--ar-danger);">
                <ul class="list-disc ps-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (!empty($links))
            <div class="rounded border p-3 text-sm" style="border-color: var(--ar-border);">
                <p class="font-medium">Relaciones vinculadas</p>
                <p class="ar-muted mt-1">Los cambios de importe, cuenta financiera, moneda, tipo o FX se bloquean si no pueden propagarse de forma segura.</p>
                <ul class="mt-2 list-disc ps-5">
                    @foreach ($links as $link)
                        <li>{{ $link['label'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('movements.update', $movement) }}" class="space-y-4" @chart-account-picked.window="/* noop hook */">
            @csrf
            @method('PUT')

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="ar-label" for="code">Código</label>
                    <input id="code" class="ar-input" value="{{ $movement->displayCode() }}" disabled>
                </div>
                <div>
                    <label class="ar-label" for="movement_date">Fecha</label>
                    <input id="movement_date" type="date" name="movement_date" class="ar-input" x-model="movementDate" required>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="ar-label" for="type">Tipo</label>
                    <select id="type" name="type" class="ar-input" x-model="type" @change="if(!scopeOptions.map(o=>o.value).includes(scope)) scope = scopeOptions[0].value" {{ $movement->isTransfer() ? 'disabled' : '' }}>
                        @if ($movement->isTransfer())
                            <option value="{{ $movement->type->value }}">{{ $movement->type->label() }}</option>
                        @else
                            <option value="income">Ingreso</option>
                            <option value="expense">Egreso</option>
                        @endif
                    </select>
                    @if ($movement->isTransfer())
                        <input type="hidden" name="type" value="{{ $movement->type->value }}">
                    @endif
                </div>
                <div>
                    <label class="ar-label" for="scope" x-text="scopeLabel">Ámbito</label>
                    <select id="scope" name="scope" class="ar-input" x-model="scope" required>
                        <template x-for="opt in scopeOptions" :key="opt.value">
                            <option :value="opt.value" x-text="opt.label" :selected="opt.value === scope"></option>
                        </template>
                    </select>
                </div>
            </div>

            <div>
                <label class="ar-label" for="description">Descripción</label>
                <input id="description" type="text" name="description" class="ar-input" value="{{ old('description', $movement->description) }}">
            </div>

            <div>
                <label class="ar-label" for="observations">Observaciones</label>
                <textarea id="observations" name="observations" class="ar-input" rows="2">{{ old('observations', $movement->observations) }}</textarea>
            </div>

            @unless ($movement->isTransfer())
                <x-chart-account-omnibox
                    :concepts="$conceptsPayload"
                    :recent="$usage['recent'] ?? []"
                    :frequent="$usage['frequent'] ?? []"
                    :initial-id="old('chart_account_id', $movement->chart_account_id)"
                    :initial-query="$selectedPath"
                    :required="false"
                />
            @endunless

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="ar-label" for="amount">Importe</label>
                    <input id="amount" type="number" step="0.01" min="0.01" name="amount" class="ar-input" value="{{ old('amount', $movement->amount) }}" required>
                </div>
                <div>
                    <label class="ar-label" for="financial_account_id">Cuenta financiera</label>
                    <select id="financial_account_id" name="financial_account_id" class="ar-input" required {{ $movement->isTransfer() ? 'disabled' : '' }}>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected(old('financial_account_id', $movement->financial_account_id) == $account->id)>
                                {{ $account->name }} ({{ $account->currency->code }})
                            </option>
                        @endforeach
                    </select>
                    @if ($movement->isTransfer())
                        <input type="hidden" name="financial_account_id" value="{{ $movement->financial_account_id }}">
                    @endif
                    <p class="ar-muted mt-1 text-xs">La moneda sigue a la cuenta financiera.</p>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="ar-label" for="exchange_rate_value">Cotización FX (congelada en el movimiento)</label>
                    <input id="exchange_rate_value" type="number" step="0.000001" min="0.000001" name="exchange_rate_value" class="ar-input" value="{{ old('exchange_rate_value', $movement->exchange_rate_value) }}">
                    <p class="ar-muted mt-1 text-xs">Editar aquí no modifica la tabla de cotizaciones. Requiere motivo.</p>
                </div>
                <div x-show="dateMismatch" x-cloak class="rounded border p-3 text-sm" style="border-color: var(--ar-border);">
                    <p class="font-medium">La fecha no coincide con la cotización congelada ({{ $movement->exchange_rate_at?->format('d/m/Y') }}).</p>
                    <p class="ar-muted mt-1">
                        Recálculo histórico usa la última cotización previa a la nueva fecha
                        @if (!empty($fxPreview['effective_date']))
                            (vigencia sugerida hoy: {{ \Carbon\Carbon::parse($fxPreview['effective_date'])->format('d/m/Y') }} · {{ number_format((float) ($fxPreview['value'] ?? 0), 2, ',', '.') }})
                        @endif
                        . No se recalcula en silencio.
                    </p>
                    <div class="mt-2 space-y-1">
                        <label class="flex items-center gap-2"><input type="radio" name="fx_mode" value="recalculate" x-model="fxMode"> Recalcular histórica</label>
                        <label class="flex items-center gap-2"><input type="radio" name="fx_mode" value="keep" x-model="fxMode"> Conservar cotización actual</label>
                    </div>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="ar-label" for="client_id">Cliente</label>
                    <select id="client_id" name="client_id" class="ar-input">
                        <option value="">—</option>
                        @foreach ($clients as $c)
                            <option value="{{ $c->id }}" @selected(old('client_id', $movement->client_id) == $c->id)>{{ $c->labelWithCode() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="ar-label" for="supplier_id">Proveedor</label>
                    <select id="supplier_id" name="supplier_id" class="ar-input">
                        <option value="">—</option>
                        @foreach ($suppliers as $s)
                            <option value="{{ $s->id }}" @selected(old('supplier_id', $movement->supplier_id) == $s->id)>{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <label class="ar-label" for="edit_reason">Motivo de edición</label>
                <textarea id="edit_reason" name="edit_reason" class="ar-input" rows="2" placeholder="Obligatorio solo para importe, moneda, cuenta financiera, tipo, FX o anulación">{{ old('edit_reason') }}</textarea>
                <p class="ar-muted mt-1 text-xs">Descripción, ámbito, cuenta contable, observaciones y fecha no exigen motivo (la fecha igual se audita).</p>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <a href="{{ route('movements.show', $movement) }}" class="ar-btn ar-btn-secondary">Cancelar</a>
                <button type="submit" class="ar-btn ar-btn-primary">Guardar cambios</button>
            </div>
        </form>
    </div>
</x-app-layout>
