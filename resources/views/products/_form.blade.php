@php $product ??= null; @endphp
<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="ar-label">SKU</label>
        <input name="sku" class="ar-input" value="{{ old('sku', $product?->sku) }}" required>
    </div>
    <div>
        <label class="ar-label">Código proveedor</label>
        <input name="supplier_code" class="ar-input" value="{{ old('supplier_code', $product?->supplier_code) }}">
    </div>
</div>
<div>
    <label class="ar-label">Nombre</label>
    <input name="name" class="ar-input" value="{{ old('name', $product?->name) }}" required>
</div>
<div>
    <label class="ar-label">Descripción</label>
    <textarea name="description" class="ar-input" rows="2">{{ old('description', $product?->description) }}</textarea>
</div>
<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="ar-label">Categoría</label>
        <select name="product_category_id" class="ar-input">
            <option value="">—</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected((int) old('product_category_id', $product?->product_category_id) === $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="ar-label">Subcategoría</label>
        <select name="product_subcategory_id" class="ar-input">
            <option value="">—</option>
            @foreach ($categories as $category)
                @foreach ($category->subcategories as $sub)
                    <option value="{{ $sub->id }}" @selected((int) old('product_subcategory_id', $product?->product_subcategory_id) === $sub->id)>{{ $category->name }} / {{ $sub->name }}</option>
                @endforeach
            @endforeach
        </select>
    </div>
</div>
<div class="grid gap-4 sm:grid-cols-3">
    <div>
        <label class="ar-label">Marca</label>
        <input name="brand" class="ar-input" value="{{ old('brand', $product?->brand) }}">
    </div>
    <div>
        <label class="ar-label">Modelo</label>
        <input name="model" class="ar-input" value="{{ old('model', $product?->model) }}">
    </div>
    <div>
        <label class="ar-label">Unidad</label>
        <input name="unit" class="ar-input" value="{{ old('unit', $product?->unit ?? 'u') }}">
    </div>
</div>
<div class="grid gap-4 sm:grid-cols-3">
    <div>
        <label class="ar-label">Tipo</label>
        <select name="type" class="ar-input" required>
            @foreach ($types as $type)
                <option value="{{ $type->value }}" @selected(old('type', $product?->type?->value ?? 'physical') === $type->value)>{{ $type->label() }}</option>
            @endforeach
        </select>
    </div>
    <div class="flex items-end pb-2">
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="requires_serial" value="1" @checked(old('requires_serial', $product?->requires_serial))>
            Requiere número de serie
        </label>
    </div>
    <div>
        <label class="ar-label">Estado</label>
        <select name="status" class="ar-input">
            <option value="active" @selected(old('status', $product?->status ?? 'active') === 'active')>Activo</option>
            <option value="inactive" @selected(old('status', $product?->status) === 'inactive')>Inactivo</option>
        </select>
    </div>
</div>
<div class="grid gap-4 sm:grid-cols-3">
    <div>
        <label class="ar-label">Ubicación</label>
        <select name="inventory_location_id" class="ar-input">
            <option value="">—</option>
            @foreach ($locations as $location)
                <option value="{{ $location->id }}" @selected((int) old('inventory_location_id', $product?->inventory_location_id) === $location->id)>{{ $location->name }}</option>
            @endforeach
        </select>
    </div>
</div>
<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="ar-label">Stock mínimo</label>
        <input type="number" step="0.0001" min="0" name="stock_min" class="ar-input" value="{{ old('stock_min', $product?->stock_min ?? 0) }}">
    </div>
    <div>
        <label class="ar-label">Stock máximo</label>
        <input type="number" step="0.0001" min="0" name="stock_max" class="ar-input" value="{{ old('stock_max', $product?->stock_max) }}">
    </div>
</div>
<div>
    <label class="ar-label">Observaciones</label>
    <textarea name="notes" class="ar-input" rows="2">{{ old('notes', $product?->notes) }}</textarea>
</div>
