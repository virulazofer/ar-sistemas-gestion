@php
    $supplier ??= null;
@endphp

<div>
    <label class="ar-label" for="name">Nombre</label>
    <input id="name" name="name" class="ar-input" value="{{ old('name', $supplier?->name) }}" required>
</div>
<div>
    <label class="ar-label" for="business_name">Razón social</label>
    <input id="business_name" name="business_name" class="ar-input" value="{{ old('business_name', $supplier?->business_name) }}">
</div>
<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="ar-label" for="cuit">CUIT</label>
        <input id="cuit" name="cuit" class="ar-input" value="{{ old('cuit', $supplier?->cuit) }}">
    </div>
    <div>
        <label class="ar-label" for="dni">DNI</label>
        <input id="dni" name="dni" class="ar-input" value="{{ old('dni', $supplier?->dni) }}">
    </div>
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
        <input id="tax_condition" name="tax_condition" class="ar-input" value="{{ old('tax_condition', $supplier?->tax_condition) }}" placeholder="Responsable Inscripto, Monotributo…">
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
