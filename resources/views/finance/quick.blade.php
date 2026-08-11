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
        ])->values();
    @endphp

    <div
        class="ar-card mx-auto max-w-2xl p-5"
        x-data="{
            type: '{{ old('type', 'expense') }}',
            scope: '{{ old('scope', 'personal') }}',
            categoryId: '{{ old('category_id') }}',
            subcategoryId: '{{ old('subcategory_id') }}',
            categories: {{ Js::from($categoriesPayload) }},
            accounts: {{ Js::from($accountsPayload) }},
            get filteredCategories() {
                return this.categories.filter(c => c.scope === this.scope || c.scope === 'both')
            },
            get filteredSubs() {
                const cat = this.categories.find(c => String(c.id) === String(this.categoryId))
                return cat ? cat.subcategories : []
            }
        }"
    >
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

            <div x-show="type !== 'transfer'" class="space-y-4">
                <div>
                    <label class="ar-label" for="financial_account_id">Cuenta</label>
                    <select id="financial_account_id" name="financial_account_id" class="ar-input" :required="type !== 'transfer'">
                        <option value="">Seleccionar…</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected(old('financial_account_id') == $account->id)>
                                {{ $account->name }} ({{ $account->currency->code }})
                            </option>
                        @endforeach
                    </select>
                </div>

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

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="ar-label" for="amount">Importe</label>
                    <input id="amount" type="number" step="0.01" min="0.01" name="amount" class="ar-input" value="{{ old('amount') }}" required autofocus>
                </div>
                <div>
                    <label class="ar-label" for="description">Descripción</label>
                    <input id="description" type="text" name="description" class="ar-input" value="{{ old('description') }}" placeholder="Opcional">
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <a href="{{ route('dashboard') }}" class="ar-btn ar-btn-secondary">Tablero</a>
                <button type="submit" class="ar-btn ar-btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</x-app-layout>
