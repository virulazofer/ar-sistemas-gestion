<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">Importar histórico de cotizaciones</h1>
            <a href="{{ route('exchange-rates.index') }}" class="ar-btn ar-btn-secondary text-xs">Volver</a>
        </div>
    </x-slot>

    <div class="ar-card mb-4 space-y-3 p-5">
        <p class="ar-muted text-sm">
            Cargá un CSV o XLSX con columnas <strong>fecha | compra | venta</strong>.
            DolarAPI no se usa como fuente de histórico: este importador alimenta el histórico propio.
            No se modifican cotizaciones ya usadas por movimientos.
        </p>
        <form method="POST" action="{{ route('exchange-rates.import.preview') }}" enctype="multipart/form-data" class="space-y-3">
            @csrf
            <div>
                <label class="ar-label" for="file">Archivo</label>
                <input id="file" type="file" name="file" accept=".csv,.xlsx,.xls" class="ar-input" required>
            </div>
            <button class="ar-btn ar-btn-primary">Generar vista previa</button>
        </form>
        @error('file')
            <p class="text-sm" style="color: var(--ar-danger);">{{ $message }}</p>
        @enderror
    </div>

    @if (!empty($preview))
        <div class="ar-card mb-4 p-5">
            <h2 class="mb-2 font-semibold">Vista previa</h2>
            <p class="ar-muted mb-3 text-sm">
                Total {{ $preview['rows_total'] }} ·
                Válidas {{ $preview['rows_valid'] }} ·
                Rechazadas {{ $preview['rows_invalid'] }} ·
                Duplicadas {{ $preview['rows_duplicate'] }}
            </p>

            @if (!empty($preview['error_summary']))
                <ul class="mb-3 list-disc ps-5 text-sm" style="color: var(--ar-danger);">
                    @foreach (array_slice($preview['error_summary'], 0, 20) as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            @endif

            <div class="mb-4 max-h-80 overflow-auto">
                <table class="ar-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Estado</th>
                            <th>Detalle</th>
                            <th>Fecha</th>
                            <th>Compra</th>
                            <th>Venta</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($preview['rows'] as $row)
                            <tr>
                                <td>{{ $row['index'] }}</td>
                                <td>{{ $row['status'] }}</td>
                                <td>{{ $row['message'] ?? '—' }}</td>
                                <td>{{ $row['data']['fecha'] ?? '—' }}</td>
                                <td>{{ $row['data']['compra'] ?? '—' }}</td>
                                <td>{{ $row['data']['venta'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if (($preview['rows_valid'] ?? 0) > 0)
                <form method="POST" action="{{ route('exchange-rates.import.confirm') }}">
                    @csrf
                    <button class="ar-btn ar-btn-primary">Confirmar importación ({{ $preview['rows_valid'] }} filas)</button>
                </form>
            @endif
        </div>
    @endif
</x-app-layout>
