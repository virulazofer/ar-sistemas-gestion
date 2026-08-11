@php
    $isEdit = isset($account);
    $typeValue = old('type', $isEdit ? $account->type->value : 'bank');
@endphp
<div x-data="{ type: @js($typeValue) }" class="space-y-4">
    <div>
        <label class="ar-label" for="name">Nombre</label>
        <input id="name" name="name" class="ar-input" value="{{ old('name', $account->name ?? '') }}" required>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="ar-label" for="type">Tipo</label>
            <select id="type" name="type" class="ar-input" required x-model="type">
                @foreach ($types as $type)
                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
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

    <div class="space-y-3 rounded border p-3" style="border-color: var(--ar-border);" x-show="type === 'bank' || type === 'wallet'">
        <h3 class="text-sm font-semibold">Datos bancarios / billetera</h3>
        <div>
            <label class="ar-label" for="cbu_cvu">CBU / CVU (22 dígitos)</label>
            <input id="cbu_cvu" name="cbu_cvu" class="ar-input" maxlength="32" value="{{ old('cbu_cvu', $account->cbu_cvu ?? '') }}">
            @error('cbu_cvu')<p class="text-sm" style="color: var(--ar-danger);">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="ar-label" for="cuit">CUIT</label>
            <input id="cuit" name="cuit" class="ar-input" value="{{ old('cuit', $account->cuit ?? '') }}">
            @error('cuit')<p class="text-sm" style="color: var(--ar-danger);">{{ $message }}</p>@enderror
        </div>
    </div>

    <div class="space-y-3 rounded border p-3" style="border-color: var(--ar-border);" x-show="type === 'credit_card'">
        <h3 class="text-sm font-semibold">Tarjeta (sin CVV ni PAN completo)</h3>
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label class="ar-label" for="card_brand">Marca</label>
                <input id="card_brand" name="card_brand" class="ar-input" value="{{ old('card_brand', $account->card_brand ?? '') }}" placeholder="Visa, Mastercard…">
            </div>
            <div>
                <label class="ar-label" for="card_last4">Últimos 4</label>
                <input id="card_last4" name="card_last4" class="ar-input" maxlength="4" value="{{ old('card_last4', $account->card_last4 ?? '') }}">
            </div>
            <div class="sm:col-span-2">
                <label class="ar-label" for="card_holder">Titular</label>
                <input id="card_holder" name="card_holder" class="ar-input" value="{{ old('card_holder', $account->card_holder ?? '') }}">
            </div>
            <div>
                <label class="ar-label" for="card_expiry_month">Mes venc.</label>
                <input id="card_expiry_month" type="number" min="1" max="12" name="card_expiry_month" class="ar-input" value="{{ old('card_expiry_month', $account->card_expiry_month ?? '') }}">
            </div>
            <div>
                <label class="ar-label" for="card_expiry_year">Año venc.</label>
                <input id="card_expiry_year" type="number" min="2020" max="2100" name="card_expiry_year" class="ar-input" value="{{ old('card_expiry_year', $account->card_expiry_year ?? '') }}">
            </div>
        </div>
        <p class="ar-muted text-xs">No se solicita ni almacena CVV. Si se intenta cargar el número completo, queda auditado y se descarta.</p>
    </div>

    <div>
        <label class="ar-label" for="external_identifier">Identificador opcional</label>
        <input id="external_identifier" name="external_identifier" class="ar-input" value="{{ old('external_identifier', $account->external_identifier ?? '') }}">
    </div>
    <div>
        <label class="ar-label" for="description">Descripción</label>
        <textarea id="description" name="description" class="ar-input" rows="3">{{ old('description', $account->description ?? '') }}</textarea>
    </div>
</div>
