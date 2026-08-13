<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">Configuración avanzada</h1>
            <a href="{{ route('chart-accounts.index') }}" class="ar-btn ar-btn-secondary text-xs">Volver al plan</a>
        </div>
    </x-slot>

    <p class="ar-muted mb-4 text-sm">
        Herramientas técnicas. El uso cotidiano es el Plan de cuentas y Cuentas financieras.
    </p>

    <div class="grid gap-3 sm:grid-cols-2 max-w-3xl">
        <a href="{{ route('remembered-classifications.index') }}" class="ar-card p-4 block hover:bg-black/[0.02]">
            <p class="font-semibold">Clasificaciones recordadas</p>
            <p class="ar-muted text-sm mt-1">Patrones aprendidos al cargar operaciones (descripción → plan + ámbito).</p>
        </a>
        @can('categories.edit')
            <a href="{{ route('chart-accounts.mapping') }}" class="ar-card p-4 block hover:bg-black/[0.02]">
                <p class="font-semibold">Vinculaciones contables</p>
                <p class="ar-muted text-sm mt-1">Excepciones / compatibilidad legacy (no es tarea cotidiana).</p>
            </a>
            <a href="{{ route('imputation-rules.index') }}" class="ar-card p-4 block hover:bg-black/[0.02]">
                <p class="font-semibold">Reglas técnicas (legacy)</p>
                <p class="ar-muted text-sm mt-1">Reglas de imputación antiguas. Preferí clasificaciones recordadas.</p>
            </a>
        @endcan
        @if (($pending ?? 0) > 0)
            <a href="{{ route('chart-accounts.classify') }}" class="ar-card p-4 block" style="border-color: var(--ar-danger);">
                <p class="font-semibold" style="color: var(--ar-danger);">Pendientes de clasificación ({{ $pending }})</p>
                <p class="ar-muted text-sm mt-1">Movimientos sin categoría operativa.</p>
            </a>
        @endif
    </div>
</x-app-layout>
