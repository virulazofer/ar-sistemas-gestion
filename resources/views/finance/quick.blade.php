<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Carga rápida</h1>
                <p class="ar-muted text-sm">Registrá un ingreso, gasto o transferencia en segundos.</p>
            </div>
            <x-page-help topic="movements.quick" />
        </div>
    </x-slot>

    @php
        $categoriesPayload = $categories->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'scope' => $c->scope,
            'subcategories' => $c->subcategories->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
        ])->values();
        $accountsPayload = $accounts->map(fn ($a) => [
            'id' => $a->id,
            'name' => $a->name,
            'currency' => $a->currency->code,
            'is_liability' => (bool) ($a->is_liability || $a->type?->value === 'credit_card'),
        ])->values();
    @endphp

    <div
        class="ar-card mx-auto max-w-2xl p-5"
        x-data="{
            type: '{{ old('type', session('quick_income_decision') ? 'income' : 'expense') }}',
            scope: '{{ old('scope', 'personal') }}',
            categoryId: '{{ old('category_id') }}',
            subcategoryId: '{{ old('subcategory_id') }}',
            accountId: '{{ old('financial_account_id') }}',
            applyToCc: {{ old('apply_to_cc', $decision['apply_to_cc'] ?? false) ? 'true' : 'false' }},
            categories: {{ Js::from($categoriesPayload) }},
            accounts: {{ Js::from($accountsPayload) }},
            get filteredCategories() {
                return this.categories.filter(c => c.scope === this.scope || c.scope === 'both')
            },
            get filteredSubs() {
                const cat = this.categories.find(c => String(c.id) === String(this.categoryId))
                return cat ? cat.subcategories : []
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
            }
        }"
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
                    <input type="radio" name="type" value="expense" class="sr-only" x-model="type">
                    Gasto
                </label>
                <label class="ar-btn text-center" :class="type === 'income' ? 'ar-btn-primary' : 'ar-btn-secondary'">
                    <input type="radio" name="type" value="income" class="sr-only" x-model="type">
                    Ingreso
                </label>
                <label class="ar-btn text-center" :class="type === 'transfer' ? 'ar-btn-primary' : 'ar-btn-secondary'">
                    <input type="radio" name="type" value="transfer" class="sr-only" x-model="type">
                    Transferencia
                </label>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="ar-label" for="movement_date">Fecha</label>
                    <input id="movement_date" type="date" name="movement_date" class="ar-input" value="{{ old('movement_date', now()->toDateString()) }}" required>
                </div>
                <div>
                    <label class="ar-label" for="scope">Ámbito</label>
                    <select id="scope" name="scope" class="ar-input" x-model="scope" required>
                        <option value="personal">Personal</option>
                        <option value="professional">Profesional</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="ar-label" for="description">Descripción</label>
                <input id="description" type="text" name="description" class="ar-input" value="{{ old('description') }}" placeholder="Opcional">
            </div>

            <div x-show="type !== 'transfer'" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="ar-label" for="category_id">Categoría</label>
                        <select id="category_id" name="category_id" class="ar-input" x-model="categoryId" @change="subcategoryId = ''">
                            <option value="">—</option>
                            <template x-for="cat in filteredCategories" :key="cat.id">
                                <option :value="cat.id" x-text="cat.name" :selected="String(categoryId) === String(cat.id)"></option>
                            </template>
                        </select>
                    </div>
                    <div>
                        <label class="ar-label" for="subcategory_id">Subcategoría</label>
                        <select id="subcategory_id" name="subcategory_id" class="ar-input" x-model="subcategoryId">
                            <option value="">—</option>
                            <template x-for="sub in filteredSubs" :key="sub.id">
                                <option :value="sub.id" x-text="sub.name"></option>
                            </template>
                        </select>
                    </div>
                </div>

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
                    <p class="ar-muted mt-1 text-xs" x-show="selectedAccount?.is_liability && type === 'expense'">
                        Tarjeta/pasivo: el gasto incrementa la deuda de la tarjeta; el pago de resumen es transferencia (no duplica egreso).
                    </p>
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
                        <input type="checkbox" name="apply_to_cc" id="apply_to_cc" value="1" x-model="applyToCc" @checked(old('apply_to_cc', $decision['apply_to_cc'] ?? false))>
                        Aplicar a cuenta corriente (cobro / cargos abiertos)
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
