<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Importación {{ $batch->uuid }}</h1>
                <p class="ar-muted text-sm">{{ $batch->entity_type }} · {{ $batch->original_filename }} · {{ $batch->status }}</p>
            </div>
            <a href="{{ route('imports.index') }}" class="ar-btn ar-btn-secondary">Listado</a>
        </div>
    </x-slot>

    <div class="mb-4 grid gap-4 sm:grid-cols-4">
        <div class="ar-card p-4"><p class="ar-muted text-sm">Total</p><p class="text-xl font-bold">{{ $batch->rows_total }}</p></div>
        <div class="ar-card p-4"><p class="ar-muted text-sm">Válidos</p><p class="text-xl font-bold">{{ $batch->rows_valid }}</p></div>
        <div class="ar-card p-4"><p class="ar-muted text-sm">Inválidos</p><p class="text-xl font-bold">{{ $batch->rows_invalid }}</p></div>
        <div class="ar-card p-4"><p class="ar-muted text-sm">Duplicados</p><p class="text-xl font-bold">{{ $batch->rows_duplicate }}</p></div>
    </div>

    <div class="ar-card mb-4 overflow-x-auto">
        <table class="ar-table">
            <thead><tr><th>#</th><th>Estado</th><th>Datos</th><th>Errores</th></tr></thead>
            <tbody>
                @foreach (($batch->preview_payload['rows'] ?? []) as $row)
                    <tr>
                        <td>{{ $row['index'] ?? '' }}</td>
                        <td>{{ $row['status'] ?? '' }}</td>
                        <td class="text-xs">{{ json_encode($row['data'] ?? [], JSON_UNESCAPED_UNICODE) }}</td>
                        <td class="text-xs">{{ implode('; ', $row['errors'] ?? []) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="flex flex-wrap gap-3">
        @if ($batch->status === 'preview')
            @can('imports.execute')
                <form method="POST" action="{{ route('imports.confirm', $batch) }}">@csrf<button class="ar-btn ar-btn-primary">Confirmar importación</button></form>
            @endcan
        @endif
        @if ($batch->status === 'confirmed')
            @can('imports.execute')
                <form method="POST" action="{{ route('imports.rollback', $batch) }}" class="ar-card flex gap-2 p-4">
                    @csrf
                    <input name="reason" class="ar-input" placeholder="Motivo rollback" required>
                    <button class="ar-btn ar-btn-secondary">Revertir lote</button>
                </form>
            @endcan
        @endif
    </div>
</x-app-layout>
