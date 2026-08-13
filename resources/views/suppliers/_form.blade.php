@php
    use App\Enums\PartyType;
    use App\Enums\TaxCondition;

    $supplier ??= null;
    $partyType = old('party_type', $supplier?->party_type?->value ?? $supplier?->party_type ?? PartyType::Particular->value);
    $taxCondition = old('tax_condition', $supplier?->tax_condition?->value ?? $supplier?->tax_condition);
@endphp

<div
    class="space-y-4"
    x-data="{ partyType: @js($partyType) }"
>
    <div>
        <label class="ar-label" for="party_type">Tipo de proveedor</label>
        <select id="party_type" name="party_type" class="ar-input" x-model="partyType" required>
            @foreach (PartyType::cases() as $type)
                <option value="{{ $type->value }}" @selected($partyType === $type->value)>{{ $type->label() }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label class="ar-label" for="name">Nombre</label>
        <input id="name" name="name" class="ar-input" value="{{ old('name', $supplier?->name) }}" required>
    </div>

    @if ($supplier?->code)
        <div>
            <label class="ar-label">Código</label>
            <p class="ar-input bg-transparent" style="border-style: dashed;">{{ $supplier->codeFormatted() }} <span class="ar-muted text-xs">(inmutable)</span></p>
        </div>
    @endif

    <div x-show="partyType === 'empresa'" x-cloak>
        <label class="ar-label" for="business_name">Razón social</label>
        <input id="business_name" name="business_name" class="ar-input" value="{{ old('business_name', $supplier?->business_name) }}" :required="partyType === 'empresa'">
        <p class="ar-muted mt-1 text-xs">Obligatoria para empresas.</p>
    </div>

    <div>
        <label class="ar-label" for="cuit">CUIT</label>
        <input id="cuit" name="cuit" class="ar-input" value="{{ old('cuit', $supplier?->cuit) }}" required>
        <p class="ar-muted mt-1 text-xs">Proveedores se identifican solo con CUIT (11 dígitos). No se usa DNI.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="ar-label" for="phone">Teléfono</label>
            <input id="phone" name="phone" class="ar-input" value="{{ old('phone', $supplier?->phone) }}">
        </div>
        <div>
            <label class="ar-label" for="email">Email</label>
            <input id="email" name="email" type="email" class="ar-input" value="{{ old('email', $supplier?->email) }}">
        </div>
    </div>
    <div>
        <label class="ar-label" for="address">Dirección</label>
        <input id="address" name="address" class="ar-input" value="{{ old('address', $supplier?->address) }}">
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="ar-label" for="contact_name">Contacto</label>
            <input id="contact_name" name="contact_name" class="ar-input" value="{{ old('contact_name', $supplier?->contact_name) }}">
        </div>
        <div>
            <label class="ar-label" for="tax_condition">Condición fiscal</label>
            <select id="tax_condition" name="tax_condition" class="ar-input" required>
                <option value="">Seleccionar…</option>
                @foreach (TaxCondition::cases() as $condition)
                    <option value="{{ $condition->value }}" @selected($taxCondition === $condition->value)>{{ $condition->label() }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div>
        <label class="ar-label" for="status">Estado</label>
        <select id="status" name="status" class="ar-input">
            <option value="active" @selected(old('status', $supplier?->status ?? 'active') === 'active')>Activo</option>
            <option value="inactive" @selected(old('status', $supplier?->status) === 'inactive')>Inactivo</option>
        </select>
    </div>
    <div>
        <label class="ar-label" for="notes">Notas</label>
        <textarea id="notes" name="notes" class="ar-input" rows="3">{{ old('notes', $supplier?->notes) }}</textarea>
    </div>
</div>
