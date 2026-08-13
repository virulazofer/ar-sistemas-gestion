@php
    $isEdit = isset($account);
    $typeValue = old('type', $isEdit ? $account->type->value : 'bank');
    $unmapped = $unmapped ?? false;
    $derivedCode = $derivedCode ?? null;
@endphp
<div x-data="{
    type: @js($typeValue),
    pan: @js(old('card_number', '')),
    formatPan() {
        const digits = (this.pan || '').replace(/\D+/g, '').slice(0, 19);
        this.pan = digits.replace(/(\d{4})(?=\d)/g, '$1 ').trim();
    }
}" class="space-y-4">
    @if ($errors->any())
        <div class="rounded border p-3 text-sm" style="border-color: var(--ar-danger); color: var(--ar-danger);">
            <p class="font-medium mb-1">Revisá los datos:</p>
            <ul class="list-disc ps-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($unmapped)
        <div class="rounded border p-3 text-sm" style="border-color: #b45309; background: #fffbeb;">
            <p class="font-medium">Cuenta financiera sin ubicación contable</p>
            <p class="mt-1 ar-muted">El tipo no tiene una rama automática en el Plan. Asigná una ubicación excepcional abajo o cambiá el tipo a Banco / Billetera / Efectivo / Tarjeta.</p>
        </div>
    @elseif ($isEdit && $derivedCode)
        <p class="ar-muted text-xs">Ubicación contable derivada del tipo: <strong>{{ $derivedCode }}</strong> (sin cuenta contable duplicada).</p>
    @endif

    <div>
        <label class="ar-label" for="name">Nombre</label>
        <input id="name" name="name" class="ar-input" value="{{ old('name', $account->name ?? '') }}" required>
        @error('name')<p class="text-sm" style="color: var(--ar-danger);">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="ar-label" for="alias">Alias</label>
        <input id="alias" name="alias" class="ar-input" value="{{ old('alias', $account->alias ?? '') }}" maxlength="80">
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="ar-label" for="type">Tipo</label>
            <select id="type" name="type" class="ar-input" required x-model="type">
                @foreach ($types as $type)
                    <option value="{{ $type->value }}" @selected($typeValue === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </select>
        </div>
        @unless ($isEdit)
            <div>
                <label class="ar-label" for="currency_id">Moneda</label>
                <select id="currency_id" name="currency_id" class="ar-input" required>
                    @foreach ($currencies as $currency)
                        <option value="{{ $currency->id }}" @selected(old('currency_id') == $currency->id)>{{ $currency->code }} — {{ $currency->name }}</option>
                    @endforeach
                </select>
            </div>
        @else
            <div>
                <label class="ar-label">Moneda</label>
                <input class="ar-input" value="{{ $account->currency?->code }}" disabled>
            </div>
        @endunless
    </div>
    <div>
        <label class="ar-label" for="status">Estado</label>
        <select id="status" name="status" class="ar-input">
            <option value="active" @selected(old('status', $account->status ?? 'active') === 'active')>Activa</option>
            <option value="inactive" @selected(old('status', $account->status ?? '') === 'inactive')>Inactiva</option>
        </select>
    </div>

    <template x-if="type === 'bank' || type === 'wallet'">
        <div class="space-y-3 rounded border p-3" style="border-color: var(--ar-border);">
            <h3 class="text-sm font-semibold" x-text="type === 'bank' ? 'Datos bancarios' : 'Datos de billetera'"></h3>
            <div>
                <label class="ar-label" for="institution" x-text="type === 'bank' ? 'Banco' : 'Proveedor'"></label>
                <input id="institution" name="institution" class="ar-input" value="{{ old('institution', $account->institution ?? '') }}">
            </div>
            <div>
                <label class="ar-label" for="holder_name">Titular</label>
                <input id="holder_name" name="holder_name" class="ar-input" value="{{ old('holder_name', $account->holder_name ?? '') }}">
            </div>
            <div>
                <label class="ar-label" for="cbu_cvu" x-text="type === 'bank' ? 'CBU (22 dígitos)' : 'CVU (22 dígitos)'"></label>
                <input id="cbu_cvu" name="cbu_cvu" class="ar-input" maxlength="32" inputmode="numeric" value="{{ old('cbu_cvu', $account->cbu_cvu ?? '') }}">
                @error('cbu_cvu')<p class="text-sm" style="color: var(--ar-danger);">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="ar-label" for="cuit">CUIT</label>
                <input id="cuit" name="cuit" class="ar-input" value="{{ old('cuit', $account->cuit ?? '') }}" placeholder="XX-XXXXXXXX-X">
                @error('cuit')<p class="text-sm" style="color: var(--ar-danger);">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="ar-label" for="external_identifier" x-text="type === 'bank' ? 'Número de cuenta (opcional)' : 'Identificador (opcional)'"></label>
                <input id="external_identifier" name="external_identifier" class="ar-input" value="{{ old('external_identifier', $account->external_identifier ?? '') }}">
            </div>
        </div>
    </template>

    <template x-if="type === 'cash'">
        <div class="space-y-3 rounded border p-3" style="border-color: var(--ar-border);">
            <h3 class="text-sm font-semibold">Efectivo / caja</h3>
            <div>
                <label class="ar-label" for="holder_name_cash">Titular</label>
                <input id="holder_name_cash" name="holder_name" class="ar-input" value="{{ old('holder_name', $account->holder_name ?? '') }}">
            </div>
        </div>
    </template>

    <template x-if="type === 'credit_card'">
        <div class="space-y-3 rounded border p-3" style="border-color: var(--ar-border);">
            <h3 class="text-sm font-semibold">Tarjeta (sin CVV / CVC)</h3>
            <div>
                <label class="ar-label" for="institution_card">Emisor / banco</label>
                <input id="institution_card" name="institution" class="ar-input" value="{{ old('institution', $account->institution ?? '') }}">
            </div>
            <div>
                <label class="ar-label" for="card_number">Número (validación Luhn; solo se guardan los últimos 4)</label>
                <input id="card_number" name="card_number" class="ar-input" x-model="pan" @input="formatPan()" maxlength="23" inputmode="numeric" autocomplete="cc-number" placeholder="XXXX XXXX XXXX XXXX">
                @error('card_number')<p class="text-sm" style="color: var(--ar-danger);">{{ $message }}</p>@enderror
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="ar-label" for="card_brand">Marca</label>
                    <input id="card_brand" name="card_brand" class="ar-input" value="{{ old('card_brand', $account->card_brand ?? '') }}" placeholder="Visa, Mastercard…">
                </div>
                <div>
                    <label class="ar-label" for="card_last4">Últimos 4</label>
                    <input id="card_last4" name="card_last4" class="ar-input" maxlength="4" value="{{ old('card_last4', $account->card_last4 ?? '') }}" placeholder="Si no cargás el número">
                </div>
                <div class="sm:col-span-2">
                    <label class="ar-label" for="card_holder">Titular</label>
                    <input id="card_holder" name="card_holder" class="ar-input" value="{{ old('card_holder', $account->card_holder ?? $account->holder_name ?? '') }}" autocomplete="cc-name">
                </div>
                <div>
                    <label class="ar-label" for="card_issue_date">Fecha de emisión (opcional)</label>
                    <input id="card_issue_date" type="date" name="card_issue_date" class="ar-input" value="{{ old('card_issue_date', isset($account) && $account->card_issue_date ? $account->card_issue_date->format('Y-m-d') : '') }}">
                </div>
                <div>
                    <label class="ar-label" for="card_expiry_month">Mes venc.</label>
                    <input id="card_expiry_month" type="number" min="1" max="12" name="card_expiry_month" class="ar-input" value="{{ old('card_expiry_month', $account->card_expiry_month ?? '') }}" required>
                    @error('card_expiry_month')<p class="text-sm" style="color: var(--ar-danger);">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="ar-label" for="card_expiry_year">Año venc.</label>
                    <input id="card_expiry_year" type="number" min="2020" max="2100" name="card_expiry_year" class="ar-input" value="{{ old('card_expiry_year', $account->card_expiry_year ?? '') }}" required>
                    @error('card_expiry_year')<p class="text-sm" style="color: var(--ar-danger);">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="ar-label" for="default_payment_financial_account_id">Cuenta habitual de pago (opcional)</label>
                    <select id="default_payment_financial_account_id" name="default_payment_financial_account_id" class="ar-input">
                        <option value="">—</option>
                        @foreach (($paymentAccounts ?? []) as $pa)
                            <option value="{{ $pa->id }}" @selected((int) old('default_payment_financial_account_id', $account->default_payment_financial_account_id ?? 0) === (int) $pa->id)>
                                {{ $pa->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            <p class="ar-muted text-xs">Nunca se solicita ni almacena CVV/CVC. El PAN completo se valida (Luhn), se deriva last4 y se descarta.</p>
        </div>
    </template>

    <template x-if="type === 'other'">
        <div class="space-y-3 rounded border p-3" style="border-color: var(--ar-border);">
            <h3 class="text-sm font-semibold">Otros instrumentos</h3>
            <div>
                <label class="ar-label" for="institution_other">Institución</label>
                <input id="institution_other" name="institution" class="ar-input" value="{{ old('institution', $account->institution ?? '') }}">
            </div>
            <div>
                <label class="ar-label" for="holder_name_other">Titular</label>
                <input id="holder_name_other" name="holder_name" class="ar-input" value="{{ old('holder_name', $account->holder_name ?? '') }}">
            </div>
            <div>
                <label class="ar-label" for="external_identifier_other">Identificador</label>
                <input id="external_identifier_other" name="external_identifier" class="ar-input" value="{{ old('external_identifier', $account->external_identifier ?? '') }}">
            </div>
            @if ($unmapped || ($isEdit && ($account->type?->value ?? '') === 'other'))
                <div>
                    <label class="ar-label" for="chart_account_id">Ubicación contable (excepción)</label>
                    <select id="chart_account_id" name="chart_account_id" class="ar-input">
                        <option value="">Sin asignar</option>
                        @foreach (\App\Models\ChartAccount::query()->orderBy('code')->get(['id','code','name']) as $ca)
                            <option value="{{ $ca->id }}" @selected(old('chart_account_id', $account->chart_account_id ?? null) == $ca->id)>
                                {{ $ca->code }} — {{ $ca->name }}
                            </option>
                        @endforeach
                    </select>
                    <p class="ar-muted mt-1 text-xs">Solo para tipos sin rama automática.</p>
                </div>
            @endif
        </div>
    </template>

    <div>
        <label class="ar-label" for="description">Descripción</label>
        <textarea id="description" name="description" class="ar-input" rows="3">{{ old('description', $account->description ?? '') }}</textarea>
    </div>
</div>
