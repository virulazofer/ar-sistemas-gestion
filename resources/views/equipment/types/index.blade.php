<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between gap-3">
            <h1 class="text-xl font-semibold">Tipos y plantillas de equipo</h1>
            <a href="{{ route('equipment.index') }}" class="ar-btn ar-btn-secondary">Equipos</a>
        </div>
    </x-slot>

    @can('equipment.create')
        <form method="POST" action="{{ route('equipment.types.store') }}" class="ar-card mb-4 grid gap-3 p-4 sm:grid-cols-4">
            @csrf
            <div><label class="ar-label">Nombre</label><input name="name" class="ar-input" required></div>
            <div><label class="ar-label">Prefijo código</label><input name="code_prefix" class="ar-input" placeholder="PC" required></div>
            <div><label class="ar-label">Notas</label><input name="notes" class="ar-input"></div>
            <div class="flex items-end"><button class="ar-btn ar-btn-primary w-full">Crear tipo</button></div>
        </form>
    @endcan

    @foreach ($types as $type)
        <div class="ar-card mb-4 overflow-x-auto">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3" style="border-color: var(--ar-border);">
                <h2 class="font-semibold">{{ $type->name }} <span class="ar-muted text-sm">({{ $type->code_prefix }}-###### · próximo {{ $type->next_sequence }})</span></h2>
            </div>
            <table class="ar-table">
                <thead><tr><th>Categoría</th><th>Min</th><th>Default</th><th>Max</th><th>Req.</th></tr></thead>
                <tbody>
                    @foreach ($type->templateItems as $item)
                        <tr>
                            <td>{{ $item->category->name }}</td>
                            <td>{{ $item->qty_min }}</td>
                            <td>{{ $item->qty_default }}</td>
                            <td>{{ $item->qty_max ?? '—' }}</td>
                            <td>{{ $item->is_required ? 'Sí' : 'No' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @can('equipment.edit')
                <form method="POST" action="{{ route('equipment.types.template.store', $type) }}" class="grid gap-2 border-t p-4 sm:grid-cols-6" style="border-color: var(--ar-border);">
                    @csrf
                    <select name="component_category_id" class="ar-input" required>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                    <input type="number" name="qty_min" class="ar-input" placeholder="Min" value="0">
                    <input type="number" name="qty_default" class="ar-input" placeholder="Default" value="1" required>
                    <input type="number" name="qty_max" class="ar-input" placeholder="Max">
                    <label class="flex items-center gap-1 text-sm"><input type="checkbox" name="is_required" value="1" checked> Req.</label>
                    <button class="ar-btn ar-btn-secondary">Agregar a plantilla</button>
                </form>
            @endcan
        </div>
    @endforeach
</x-app-layout>
