<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-xl font-semibold">Importaciones</h1>
                    <x-page-help topic="imports" />
                </div>
                <p class="ar-muted text-sm">CSV / XLSX · vista previa · confirmación · rollback.</p>
            </div>
            @can('imports.execute')
                <a href="{{ route('imports.create') }}" class="ar-btn ar-btn-primary">Nueva importación</a>
            @endcan
        </div>
    </x-slot>
    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead><tr><th>Fecha</th><th>Entidad</th><th>Archivo</th><th>Estado</th><th>Válidos</th><th>Importados</th><th></th></tr></thead>
            <tbody>
                @forelse ($batches as $batch)
                    <tr>
                        <td>{{ $batch->created_at?->format('d/m/Y H:i') }}</td>
                        <td>{{ $batch->entity_type }}</td>
                        <td>{{ $batch->original_filename }}</td>
                        <td>{{ $batch->status }}</td>
                        <td>{{ $batch->rows_valid }} / {{ $batch->rows_total }}</td>
                        <td>{{ $batch->rows_imported }}</td>
                        <td class="text-right"><a href="{{ route('imports.show', $batch) }}" style="color: var(--ar-brand);">Ver</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="ar-muted py-6 text-center">Sin importaciones.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $batches->links() }}</div>
</x-app-layout>
