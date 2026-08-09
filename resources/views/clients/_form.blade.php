@php
    $client ??= null;
@endphp

<div>
    <label class="ar-label" for="name">Nombre</label>
    <input id="name" name="name" class="ar-input" value="{{ old('name', $client?->name) }}" required>
</div>
<div>
    <label class="ar-label" for="business_name">Razón social</label>
    <input id="business_name" name="business_name" class="ar-input" value="{{ old('business_name', $client?->business_name) }}">
</div>
<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="ar-label" for="cuit">CUIT</label>
        <input id="cuit" name="cuit" class="ar-input" value="{{ old('cuit', $client?->cuit) }}">
    </div>
    <div>
        <label class="ar-label" for="dni">DNI</label>
        <input id="dni" name="dni" class="ar-input" value="{{ old('dni', $client?->dni) }}">
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
        <input id="tax_condition" name="tax_condition" class="ar-input" value="{{ old('tax_condition', $client?->tax_condition) }}" placeholder="Responsable Inscripto, Monotributo…">
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
