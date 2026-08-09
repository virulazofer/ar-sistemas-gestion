<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Importación histórica / catálogo</h1>
                <p class="ar-muted text-sm">Solo análisis y preview. Corte operativo: {{ $cutover }} · Histórico {{ $periodFrom }} → {{ $periodTo }}</p>
            </div>
            <a href="{{ route('imports.index') }}" class="ar-btn ar-btn-secondary">Volver</a>
        </div>
    </x-slot>

    <div class="grid gap-6 lg:grid-cols-2">
        <form method="POST" action="{{ route('imports.historical.catalog') }}" enctype="multipart/form-data" class="ar-card space-y-4 p-5">
            @csrf
            <h2 class="font-semibold">1. Lista de precios proveedor</h2>
            <p class="ar-muted text-sm">Crea preview de maestros de productos. No genera compras ni stock.</p>
            <div>
                <label class="ar-label">Archivo XLSX</label>
                <input type="file" name="file" class="ar-input" accept=".xlsx,.xls" required>
            </div>
            <div>
                <label class="ar-label">Fecha de lista</label>
                <input type="date" name="list_date" class="ar-input" value="2026-08-07">
            </div>
            <button class="ar-btn ar-btn-primary">Analizar catálogo</button>
        </form>

        <form method="POST" action="{{ route('imports.historical.movements') }}" enctype="multipart/form-data" class="ar-card space-y-4 p-5">
            @csrf
            <h2 class="font-semibold">2. Gastos mensuales / Movimientos</h2>
            <p class="ar-muted text-sm">Preview Verde/Amarillo/Rojo + conciliación. Confirmación definitiva bloqueada.</p>
            <div>
                <label class="ar-label">Archivo XLSX</label>
                <input type="file" name="file" class="ar-input" accept=".xlsx,.xls" required>
            </div>
            <div>
                <label class="ar-label">Fecha de corte</label>
                <input type="date" name="cutover_date" class="ar-input" value="{{ $cutover }}">
            </div>
            <button class="ar-btn ar-btn-primary">Analizar movimientos</button>
        </form>
    </div>

    @if ($errors->any())
        <div class="ar-card mt-4 border border-red-300 p-4 text-sm text-red-700">
            {{ $errors->first() }}
        </div>
    @endif
</x-app-layout>
