<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Carga rápida</h1>
                <p class="ar-muted text-sm">Ingreso / egreso / transferencia. Concepto = hoja del plan de cuentas.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @can('documents.create')
                    <a href="{{ route('documents.capture') }}" class="ar-btn ar-btn-secondary lg:hidden">Capturar documento</a>
                @endcan
                <x-page-help topic="movements.quick" />
            </div>

    @php
        $accountsPayload = $accounts->map(fn ($a) => [
            'id' => $a->id,
            'name' => $a->name,
            'currency' => $a->currency->code,
            'is_liability' => (bool) ($a->is_liability || $a->type?->value === 'credit_card'),
        ])->values();
        $conceptsPayload = ($conceptAccounts ?? collect())->map(fn ($c) => [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'type' => $c->type instanceof \BackedEnum ? $c->type->value : (string) $c->type,
            'path' => $c->pathLabel(),
            'suggested_scope' => $c->suggested_scope,
        ])->values();
    @endphp

    @if (session('remember_prompt'))
        @php $rp = session('remember_prompt'); @endphp
        <div class="ar-card mx-auto max-w-2xl mb-4 p-4 text-sm" style="border-color: var(--ar-accent, #2563eb);">
            @if (($rp['mode'] ?? '') === 'update')
                <p class="font-medium">¿Actualizar también la clasificación recordada para «{{ $rp['description'] }}»?</p>
                <p class="ar-muted mt-1">Nueva: {{ $rp['path'] ?? '—' }} / {{ config('finance.scopes.'.$rp['scope'], $rp['scope']) }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('remembered-classifications.store') }}">
                        @csrf
                        <input type="hidden" name="description" value="{{ $rp['description'] }}">
                        <input type="hidden" name="type" value="{{ $rp['type'] }}">
                        <input type="hidden" name="chart_account_id" value="{{ $rp['chart_account_id'] }}">
                        <input type="hidden" name="scope" value="{{ $rp['scope'] }}">
                        <button class="ar-btn ar-btn-primary text-xs">Actualizar</button>
                    </form>
                    <a href="{{ route('movements.quick') }}" class="ar-btn ar-btn-secondary text-xs">Solo esta vez</a>
                </div>
            @else
                <p class="font-medium">¿Recordar esta clasificación para «{{ $rp['description'] }}»?</p>
                <p class="ar-muted mt-1">{{ $rp['path'] ?? '—' }} · {{ config('finance.scopes.'.$rp['scope'], $rp['scope']) }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('remembered-classifications.store') }}">
                        @csrf
                        <input type="hidden" name="description" value="{{ $rp['description'] }}">
                        <input type="hidden" name="type" value="{{ $rp['type'] }}">
                        <input type="hidden" name="chart_account_id" value="{{ $rp['chart_account_id'] }}">
                        <input type="hidden" name="scope" value="{{ $rp['scope'] }}">
                        <button class="ar-btn ar-btn-primary text-xs">Sí</button>
                    </form>
                    <a href="{{ route('movements.quick') }}" class="ar-btn ar-btn-secondary text-xs">No</a>
                </div>
            @endif
        </div>
    @endif

    <div
        class="ar-card mx-auto max-w-2xl p-5"
        x-data="{
            type: '{{ old('type', session('quick_income_decision') ? 'income' : 'expense') }}',
            scope: '{{ old('scope', 'personal') }}',
            chartAccountId: '{{ old('chart_account_id') }}',
            description: '{{ old('description') }}',
            accountId: '{{ old('financial_account_id') }}',
            applyToCc: {{ old('apply_to_cc', $decision['apply_to_cc'] ?? false) ? 'true' : 'false' }},
            accounts: {{ Js::from($accountsPayload) }},
            concepts: {{ Js::from($conceptsPayload) }},
            memoryMatch: null,
            memoryPath: null,
            memoryId: null,
            lookupTimer: null,
            get scopeOptions() {
                if (this.type === 'income') {
                    return [
                        { value: 'professional', label: 'Profesional' },
                        { value: 'financial', label: 'Financiero' },
                    ]
                }
                return [
                    { value: 'personal', label: 'Personal' },
                    { value: 'professional', label: 'Profesional' },
                    { value: 'mixed', label: 'Mixto' },
                ]
            },
            get scopeLabel() {
                return this.type === 'income' ? 'Origen' : 'Ámbito'
            },
            get selectedConcept() {
                return this.concepts.find(c => String(c.id) === String(this.chartAccountId))
            },
            get selectedAccount() {
                return this.accounts.find(a => String(a.id) === String(this.accountId))
            },
            get accountLabel() {
                const a = this.selectedAccount
                if (!a) return 'Cuenta financiera'
                if (this.type === 'income') {
                    return a.is_liability ? 'Cuenta acreditada (pasivo)' : 'Cuenta acreditada'
                }
                if (this.type === 'expense') {
                    return a.is_liability ? 'Cuenta debitada (pasivo tarjeta)' : 'Cuenta debitada'
                }
                return 'Cuenta financiera'
            },
            onTypeChange() {
                const allowed = this.scopeOptions.map(o => o.value)
                if (!allowed.includes(this.scope)) {
                    this.scope = allowed[0]
                }
                this.chartAccountId = ''
                this.memoryMatch = null
                this.$dispatch('chart-account-selected', { id: '' })
                this.lookupMemory()
            },
            onConceptPicked(detail) {
                if (!detail?.id) return
                this.chartAccountId = String(detail.id)
                const c = detail.concept || this.selectedConcept
                if (c && c.suggested_scope && this.memoryMatch !== 'exact') {
                    const allowed = this.scopeOptions.map(o => o.value)
                    if (allowed.includes(c.suggested_scope)) {
                        this.scope = c.suggested_scope
                    }
                }
            },
            async lookupMemory() {
                if (this.type === 'transfer' || !(this.description || '').trim()) {
                    this.memoryMatch = null
                    return
                }
                try {
                    const url = @js(route('remembered-classifications.lookup')) + '?description=' + encodeURIComponent(this.description) + '&type=' + encodeURIComponent(this.type)
                    const r = await fetch(url, { headers: { 'Accept': 'application/json' } })
                    const j = await r.json()
                    this.memoryMatch = j.match || null
                    this.memoryPath = j.path || null
                    this.memoryId = j.memory_id || null
                    if (j.match === 'exact' && j.chart_account_id) {
                        this.chartAccountId = String(j.chart_account_id)
                        this.$dispatch('chart-account-selected', { id: j.chart_account_id })
                        if (j.scope) {
                            const allowed = this.scopeOptions.map(o => o.value)
                            if (allowed.includes(j.scope)) this.scope = j.scope
                        }
                    }
                } catch (e) {
                    this.memoryMatch = null
                }
            },
            onDescriptionInput() {
                clearTimeout(this.lookupTimer)
                this.lookupTimer = setTimeout(() => this.lookupMemory(), 350)
            },
            async forgetMemory() {
                if (!this.description) return
                await fetch(@js(route('remembered-classifications.forget')), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''
                    },
                    body: JSON.stringify({ description: this.description, type: this.type })
                })
                this.memoryMatch = null
                this.memoryPath = null
                this.memoryId = null
            },
            applySuggestion() {
                fetch(@js(route('remembered-classifications.lookup')) + '?description=' + encodeURIComponent(this.description) + '&type=' + this.type)
                    .then(r => r.json()).then(j => {
                        if (j.chart_account_id) {
                            this.chartAccountId = String(j.chart_account_id)
                            this.$dispatch('chart-account-selected', { id: j.chart_account_id })
                            if (j.scope) this.scope = j.scope
                        }
                    })
            }
        }"
        x-init="onTypeChange()"
        @chart-account-picked.window="onConceptPicked($event.detail)"
    >
        @if (session('status'))
            <p class="mb-4 rounded border px-3 py-2 text-sm" style="border-color: var(--ar-border); background: var(--ar-surface-2, transparent);">
                {{ session('status') }}
            </p>
        @endif

        @if ($rateInfo)
            <p class="ar-muted mb-4 text-sm">
                Cotización venta oficial:
                <strong>{{ number_format((float) $rateInfo['rate']->rate, 2, ',', '.') }}</strong>
                ARS/USD
                · origen: {{ $rateInfo['source_label'] }}
                · {{ $rateInfo['rate']->rate_at?->format('d/m/Y H:i') }}
            </p>
        @else
            <p class="mb-4 text-sm" style="color: var(--ar-danger);">No hay cotización cargada. Andá a Cotizaciones antes de operar.</p>
        @endif

        @if ($decision)
            <div class="mb-4 rounded border p-3 text-sm" style="border-color: var(--ar-danger, #b91c1c);">
                <p class="font-semibold">{{ $decision['message'] ?? 'No hay deuda abierta suficiente en CC.' }}</p>
                <p class="ar-muted mt-1">Deuda abierta: {{ number_format((float) ($decision['open_debt'] ?? 0), 2, ',', '.') }} · Importe: {{ number_format((float) ($decision['amount'] ?? 0), 2, ',', '.') }}</p>
                <p class="mt-2">A) Pago a cuenta · B) Crear cargo + aplicar · C) Solo ingreso (sin inventar deuda) · D) Cancelar</p>
            </div>
        @endif

        <form method="POST" action="{{ route('movements.quick.store') }}" class="space-y-4">
            @csrf

            <div class="grid grid-cols-3 gap-2">
                <label class="ar-btn text-center" :class="type === 'expense' ? 'ar-btn-primary' : 'ar-btn-secondary'">
                    <input type="radio" name="type" value="expense" class="sr-only" x-model="type" @change="onTypeChange()">
                    Egreso
                </label>
                <label class="ar-btn text-center" :class="type === 'income' ? 'ar-btn-primary' : 'ar-btn-secondary'">
                    <input type="radio" name="type" value="income" class="sr-only" x-model="type" @change="onTypeChange()">
                    Ingreso
                </label>
                <label class="ar-btn text-center" :class="type === 'transfer' ? 'ar-btn-primary' : 'ar-btn-secondary'">
                    <input type="radio" name="type" value="transfer" class="sr-only" x-model="type" @change="onTypeChange()">
                    Transferencia
                </label>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="ar-label" for="movement_date">Fecha</label>
                    <input id="movement_date" type="date" name="movement_date" class="ar-input" value="{{ old('movement_date', now()->toDateString()) }}" required>
                </div>
                <div>
                    <label class="ar-label" for="scope" x-text="type === 'transfer' ? 'Ámbito' : scopeLabel">Ámbito</label>
                    <select id="scope" name="scope" class="ar-input" x-model="scope" required>
                        <template x-for="opt in (type === 'transfer'
                            ? [{value:'personal',label:'Personal'},{value:'professional',label:'Profesional'},{value:'mixed',label:'Mixto'}]
                            : scopeOptions)" :key="opt.value">
                            <option :value="opt.value" x-text="opt.label"></option>
                        </template>
                    </select>
                    <p class="ar-muted mt-1 text-xs" x-show="type !== 'transfer' && selectedConcept?.suggested_scope">
                        Sugerido por concepto (editable).
                    </p>
                </div>
            </div>

            <div>
                <label class="ar-label" for="description">Descripción</label>
                <input id="description" type="text" name="description" class="ar-input" x-model="description" @input="onDescriptionInput()" placeholder="Ej. Nafta Shell / Abono DAASA">
            </div>

            <div x-show="type !== 'transfer'" class="space-y-4">
                <div>
                    <x-chart-account-omnibox
                        :concepts="$conceptsPayload"
                        :recent="$chartUsage['recent'] ?? []"
                        :frequent="$chartUsage['frequent'] ?? []"
                        :initial-id="old('chart_account_id')"
                        :required="true"
                    />
                    <p class="ar-muted mt-1 text-xs">Concepto = hoja del plan de cuentas. Un solo selector (recientes, frecuentes y typeahead).</p>
                    <div x-show="memoryMatch === 'exact'" class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                        <span class="rounded px-2 py-0.5" style="background: var(--ar-surface-2, #f3f4f6);">Recordada (exacta)</span>
                        <button type="button" class="underline" @click="forgetMemory()">Dejar de recordar</button>
                    </div>
                    <div x-show="memoryMatch === 'probable'" class="mt-2 text-xs rounded border p-2" style="border-color: var(--ar-border);">
                        <span class="font-medium">Sugerido:</span>
                        <span x-text="memoryPath"></span>
                        <span class="ar-muted"> · confirmá o cambiá (no se aplica sola)</span>
                        <button type="button" class="underline ml-2" @click="applySuggestion()">Usar sugerencia</button>
                    </div>
                    @error('chart_account_id')
                        <p class="mt-1 text-xs" style="color: var(--ar-danger);">{{ $message }}</p>
                    @enderror
                </div>
                <input type="hidden" name="remember_action" value="ask">

                <div>
                    <label class="ar-label" for="amount">Importe</label>
                    <input id="amount" type="number" step="0.01" min="0.01" name="amount" class="ar-input" value="{{ old('amount', $decision['amount'] ?? '') }}" required autofocus>
                </div>

                <div>
                    <label class="ar-label" for="financial_account_id" x-text="accountLabel">Cuenta financiera</label>
                    <select id="financial_account_id" name="financial_account_id" class="ar-input" x-model="accountId" :required="type !== 'transfer'">
                        <option value="">Seleccionar…</option>
                        @foreach ($accounts as $account)
                            @php $isLiab = $account->is_liability || ($account->type?->value === 'credit_card'); @endphp
                            <option value="{{ $account->id }}" @selected(old('financial_account_id') == $account->id)>
                                {{ $account->name }} ({{ $account->currency->code }}){{ $isLiab ? ' · pasivo' : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div x-show="type === 'income'" class="space-y-3 rounded border p-3" style="border-color: var(--ar-border);">
                    <div>
                        <label class="ar-label" for="client_id">Cliente (opcional)</label>
                        <select id="client_id" name="client_id" class="ar-input"
                            onchange="const cc=document.getElementById('apply_to_cc'); if(cc&&cc.checked){ const p=new URLSearchParams(window.location.search); p.set('client_id', this.value); const acc=document.getElementById('financial_account_id'); if(acc?.value) p.set('financial_account_id', acc.value); window.location='{{ route('movements.quick') }}?'+p.toString(); }">
                            <option value="">—</option>
                            @foreach ($clients as $c)
                                <option value="{{ $c->id }}" @selected(old('client_id', $preselectClient) == $c->id)>{{ $c->labelWithCode() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="apply_to_cc" id="apply_to_cc" value="1" x-model="applyToCc" @checked(old('apply_to_cc', $decision['apply_to_cc'] ?? ($preselectClient ? true : false)))>
                        <span>Aplicar a cuenta corriente (cobro / cargos abiertos)</span>
                    </label>
                    <p class="ar-muted text-xs" x-show="applyToCc">
                        Un solo ingreso financiero. Deuda abierta ({{ $currencyHint }}): {{ number_format((float) $openDebt, 2, ',', '.') }}
                        · {{ $openCharges->count() }} cargo(s).
                    </p>
                </div>
            </div>

            <div x-show="type === 'transfer'" class="grid gap-4 sm:grid-cols-2" style="display:none;">
                <div>
                    <label class="ar-label" for="from_account_id">Desde</label>
                    <select id="from_account_id" name="from_account_id" class="ar-input" :required="type === 'transfer'">
                        <option value="">Seleccionar…</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected(old('from_account_id') == $account->id)>
                                {{ $account->name }} ({{ $account->currency->code }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="ar-label" for="to_account_id">Hacia</label>
                    <select id="to_account_id" name="to_account_id" class="ar-input" :required="type === 'transfer'">
                        <option value="">Seleccionar…</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected(old('to_account_id') == $account->id)>
                                {{ $account->name }} ({{ $account->currency->code }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            @if ($decision)
                <div class="space-y-3 rounded border p-3" style="border-color: var(--ar-border);" x-show="type === 'income'">
                    <label class="flex items-center gap-2 text-sm"><input type="radio" name="insufficient_option" value="on_account" required> A) Pago a cuenta / saldo a favor</label>
                    <label class="flex items-center gap-2 text-sm"><input type="radio" name="insufficient_option" value="create_charge"> B) Crear cargo faltante y aplicar</label>
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
                    <label class="flex items-center gap-2 text-sm"><input type="radio" name="insufficient_option" value="income_only"> C) Solo ingreso (confirmar; no inventa deuda)</label>
                    <label class="flex items-center gap-2 text-sm"><input type="radio" name="insufficient_option" value="cancel"> D) Cancelar</label>
                </div>
            @endif

            <div class="flex justify-end gap-2 pt-2">
                <a href="{{ route('dashboard') }}" class="ar-btn ar-btn-secondary">Tablero</a>
                <button type="submit" class="ar-btn ar-btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</x-app-layout>
