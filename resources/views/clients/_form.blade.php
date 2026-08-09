@php
    use App\Enums\PartyType;
    use App\Enums\TaxCondition;

    $client ??= null;
    $partyType = old('party_type', $client?->party_type?->value ?? $client?->party_type ?? PartyType::Particular->value);
    $taxCondition = old('tax_condition', $client?->tax_condition?->value ?? $client?->tax_condition);
@endphp

<div
    class="space-y-4"
    x-data="{ partyType: @js($partyType) }"
>
    <div>
        <label class="ar-label" for="party_type">Tipo de cliente</label>
        <select id="party_type" name="party_type" class="ar-input" x-model="partyType" required>
            @foreach (PartyType::cases() as $type)
                <option value="{{ $type->value }}" @selected($partyType === $type->value)>{{ $type->label() }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label class="ar-label" for="name">Nombre</label>
        <input id="name" name="name" class="ar-input" value="{{ old('name', $client?->name) }}" required>
    </div>

    <div x-show="partyType === 'empresa'" x-cloak>
        <label class="ar-label" for="business_name">Razón social</label>
        <input id="business_name" name="business_name" class="ar-input" value="{{ old('business_name', $client?->business_name) }}" :required="partyType === 'empresa'">
        <p class="ar-muted mt-1 text-xs">Obligatoria para empresas.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div x-show="partyType === 'particular'" x-cloak>
            <label class="ar-label" for="dni">DNI</label>
            <input id="dni" name="dni" class="ar-input" value="{{ old('dni', $client?->dni) }}" inputmode="numeric" :required="partyType === 'particular'">
            <p class="ar-muted mt-1 text-xs">Obligatorio para particulares (7–8 dígitos).</p>
        </div>
        <div>
            <label class="ar-label" for="cuit">
                CUIT
                <span class="ar-muted" x-show="partyType === 'particular'" x-cloak>(opcional)</span>
            </label>
            <input id="cuit" name="cuit" class="ar-input" value="{{ old('cuit', $client?->cuit) }}" :required="partyType === 'empresa'">
            <p class="ar-muted mt-1 text-xs" x-show="partyType === 'empresa'" x-cloak>Obligatorio para empresas (11 dígitos).</p>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="ar-label" for="phone">Teléfono</label>
            <input id="phone" name="phone" class="ar-input" value="{{ old('phone', $client?->phone) }}">
        </div>
        <div>
            <label class="ar-label" for="email">Email</label>
            <input id="email" name="email" type="email" class="ar-input" value="{{ old('email', $client?->email) }}">
        </div>
    </div>
    <div>
        <label class="ar-label" for="address">Dirección</label>
        <input id="address" name="address" class="ar-input" value="{{ old('address', $client?->address) }}">
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="ar-label" for="tax_condition">Condición fiscal</label>
            <select id="tax_condition" name="tax_condition" class="ar-input" required>
                <option value="">Seleccionar…</option>
                @foreach (TaxCondition::cases() as $condition)
                    <option value="{{ $condition->value }}" @selected($taxCondition === $condition->value)>{{ $condition->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="ar-label" for="status">Estado</label>
            <select id="status" name="status" class="ar-input">
                <option value="active" @selected(old('status', $client?->status ?? 'active') === 'active')>Activo</option>
                <option value="inactive" @selected(old('status', $client?->status) === 'inactive')>Inactivo</option>
            </select>
        </div>
    </div>
    <div>
        <label class="ar-label" for="notes">Notas</label>
        <textarea id="notes" name="notes" class="ar-input" rows="3">{{ old('notes', $client?->notes) }}</textarea>
    </div>
</div>
