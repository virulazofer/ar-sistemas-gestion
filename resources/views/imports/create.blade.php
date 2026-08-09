<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Nueva importación</h1></x-slot>
    <form method="POST" action="{{ route('imports.store') }}" enctype="multipart/form-data" class="ar-card mx-auto max-w-xl space-y-4 p-6">
        @csrf
        <div>
            <label class="ar-label">Entidad</label>
            <select name="entity_type" class="ar-input" required>
                @foreach ($entityTypes as $type)
                    <option value="{{ $type }}">{{ $type }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="ar-label">Archivo CSV / XLSX</label>
            <input type="file" name="file" class="ar-input" accept=".csv,.xlsx,.xls,.txt" required>
        </div>
        <p class="ar-muted text-sm">No se inserta nada hasta confirmar la vista previa. Duplicados se detectan (CUIT/DNI, SKU, external_id).</p>
        <div class="flex justify-end gap-2">
            <a href="{{ route('imports.index') }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button class="ar-btn ar-btn-primary">Analizar</button>
        </div>
    </form>
</x-app-layout>
