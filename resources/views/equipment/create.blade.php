<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between gap-3">
            <h1 class="text-xl font-semibold">Armar equipo</h1>
            <a href="{{ route('equipment.index') }}" class="ar-btn ar-btn-secondary">Cancelar</a>
        </div>
    </x-slot>

    <form method="POST" action="{{ route('equipment.store') }}" class="ar-card mx-auto max-w-4xl space-y-4 p-6"
          x-data="{ lines: 1 }">
        @csrf
        @if ($errors->any())
            <div class="rounded-lg p-3 text-sm" style="color: var(--ar-danger, #b91c1c);">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="ar-label">Tipo</label>
                <select name="equipment_type_id" class="ar-input" required>
                    @foreach ($equipmentTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->name }} ({{ $type->code_prefix }}-######)</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ar-label">Nombre / identificación</label>
                <input name="name" class="ar-input" value="{{ old('name') }}" required>
            </div>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="ar-label">Ubicación</label>
                <select name="inventory_location_id" class="ar-input">
                    <option value="">—</option>
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}">{{ $location->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ar-label">Notas</label>
                <input name="notes" class="ar-input" value="{{ old('notes') }}">
            </div>
        </div>

        <div>
            <div class="mb-2 flex items-center justify-between">
                <h2 class="font-semibold">Componentes (stock real)</h2>
                <button type="button" class="ar-btn ar-btn-secondary text-sm" @click="lines++">+ Componente</button>
            </div>
            <template x-for="i in lines" :key="i">
                <div class="mb-3 grid gap-2 rounded-lg border p-3 sm:grid-cols-4" style="border-color: var(--ar-border);">
                    <div>
                        <label class="ar-label">Producto</label>
                        <select :name="'components['+(i-1)+'][product_id]'" class="ar-input" required>
                            <option value="">—</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}">{{ $product->sku }} — {{ $product->name }}{{ $product->requires_serial ? ' [S/N]' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="ar-label">Categoría</label>
                        <select :name="'components['+(i-1)+'][component_category_id]'" class="ar-input">
                            <option value="">—</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="ar-label">Cant.</label>
                        <input type="number" min="1" value="1" :name="'components['+(i-1)+'][quantity]'" class="ar-input">
                    </div>
                    <div>
                        <label class="ar-label">Serial (si aplica)</label>
                        <input type="text" :name="'components['+(i-1)+'][serial_number]'" class="ar-input" placeholder="SN…">
                    </div>
                </div>
            </template>
        </div>

        <div class="flex justify-end gap-2">
            <a href="{{ route('equipment.index') }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button class="ar-btn ar-btn-primary">Confirmar armado</button>
        </div>
    </form>
</x-app-layout>
