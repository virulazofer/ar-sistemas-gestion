<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Importación {{ $batch->uuid }}</h1>
                <p class="ar-muted text-sm">
                    {{ $batch->importer_kind ?: $batch->entity_type }}
                    · {{ $batch->original_filename }}
                    · {{ $batch->status }}
                    @if ($batch->file_hash)
                        · hash {{ \Illuminate\Support\Str::limit($batch->file_hash, 12, '') }}
                    @endif
                </p>
            </div>
            <div class="flex gap-2">
                @can('imports.execute')
                    <a href="{{ route('imports.historical.create') }}" class="ar-btn ar-btn-secondary">Histórico / catálogo</a>
                @endcan
                <a href="{{ route('imports.index') }}" class="ar-btn ar-btn-secondary">Listado</a>
            </div>
        </div>
    </x-slot>

    @if (session('status'))
        <div class="ar-card mb-4 p-3 text-sm">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="ar-card mb-4 border border-red-300 p-3 text-sm text-red-700">{{ $errors->first() }}</div>
    @endif

    <div class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="ar-card p-4"><p class="ar-muted text-sm">Total</p><p class="text-xl font-bold">{{ $batch->rows_total }}</p></div>
        <div class="ar-card p-4"><p class="ar-muted text-sm">Verde</p><p class="text-xl font-bold">{{ $batch->rows_green }}</p></div>
        <div class="ar-card p-4"><p class="ar-muted text-sm">Amarillo</p><p class="text-xl font-bold">{{ $batch->rows_yellow }}</p></div>
        <div class="ar-card p-4"><p class="ar-muted text-sm">Rojo</p><p class="text-xl font-bold">{{ $batch->rows_red }}</p></div>
    </div>

    @if ($batch->importer_kind === 'historical_movements')
        @php($recon = $batch->reconciliation_payload ?? [])
        @php($summary = $batch->classification_summary ?? [])
        <div class="ar-card mb-4 overflow-x-auto p-4">
            <h2 class="mb-3 font-semibold">Conciliación (preview)</h2>
            <table class="ar-table">
                <thead><tr><th>Concepto</th><th class="text-right">Excel</th><th class="text-right">AR Sistemas (interp.)</th><th class="text-right">Diferencia</th></tr></thead>
                <tbody>
                    <tr>
                        <td>Ingresos</td>
                        <td class="text-right">{{ number_format((float) ($recon['excel']['ingresos_ars'] ?? 0), 2, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) ($recon['ar_sistemas_preview']['ingresos_ars'] ?? 0), 2, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) ($recon['differences']['ingresos'] ?? 0), 2, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td>Egresos</td>
                        <td class="text-right">{{ number_format((float) ($recon['excel']['egresos_ars'] ?? 0), 2, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) ($recon['ar_sistemas_preview']['egresos_ars'] ?? 0), 2, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) ($recon['differences']['egresos'] ?? 0), 2, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td>CC IN</td>
                        <td class="text-right">{{ number_format((float) ($recon['excel']['cc_in_ars'] ?? 0), 2, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) ($recon['ar_sistemas_preview']['cc_charges'] ?? 0), 2, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) ($recon['differences']['cc_in'] ?? 0), 2, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td>CC OUT</td>
                        <td class="text-right">{{ number_format((float) ($recon['excel']['cc_out_ars'] ?? 0), 2, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) ($recon['ar_sistemas_preview']['cc_payments'] ?? 0), 2, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) ($recon['differences']['cc_out'] ?? 0), 2, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td>Merca IN / OUT</td>
                        <td class="text-right">{{ number_format((float) ($recon['excel']['merca_in'] ?? 0), 2, ',', '.') }} / {{ number_format((float) ($recon['excel']['merca_out'] ?? 0), 2, ',', '.') }}</td>
                        <td class="text-right ar-muted">solo análisis</td>
                        <td class="text-right">—</td>
                    </tr>
                    <tr>
                        <td>Ventas / Utilidad</td>
                        <td class="text-right">{{ number_format((float) ($recon['excel']['ventas'] ?? 0), 2, ',', '.') }} / {{ number_format((float) ($recon['excel']['utilidad_ventas'] ?? 0), 2, ',', '.') }}</td>
                        <td class="text-right ar-muted">revisión manual si compleja</td>
                        <td class="text-right">—</td>
                    </tr>
                </tbody>
            </table>
            <p class="ar-muted mt-3 text-sm">Clientes detectados: {{ $summary['clients_detected'] ?? 0 }} · Fechas sospechosas: {{ $summary['suspicious_dates'] ?? 0 }} · Operaciones complejas: {{ $summary['complex_operations'] ?? 0 }} · Abonos recurrentes: {{ $summary['recurring_subscriptions'] ?? 0 }}</p>
        </div>

        @foreach (['green' => 'Verde', 'yellow' => 'Amarillo', 'red' => 'Rojo'] as $key => $label)
            @php($sample = $batch->preview_payload['rows_sample_'.$key] ?? [])
            <div class="ar-card mb-4 overflow-x-auto">
                <h2 class="p-4 font-semibold">Muestra {{ $label }} ({{ count($sample) }})</h2>
                <table class="ar-table">
                    <thead><tr><th>Fila</th><th>Fecha</th><th>Concepto</th><th>Cuenta</th><th>SubCuenta</th><th>Flags</th></tr></thead>
                    <tbody>
                        @forelse ($sample as $row)
                            <tr>
                                <td>{{ $row['source_row'] ?? '' }}</td>
                                <td>{{ $row['date'] ?? '' }}</td>
                                <td>{{ \Illuminate\Support\Str::limit($row['concepto'] ?? '', 60) }}</td>
                                <td>{{ $row['excel_cuenta_category'] ?? '' }}</td>
                                <td>{{ $row['excel_subcuenta_account'] ?? '' }}</td>
                                <td class="text-xs">{{ implode(', ', $row['flags'] ?? []) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="ar-muted py-4 text-center">Sin filas</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endforeach

        <div class="ar-card mb-4 border border-amber-300 p-4 text-sm">
            Confirmación de movimientos históricos <strong>bloqueada</strong>. Abonos = solo recurrencia detectada (sin crear).
            <div class="mt-2">
                <a href="{{ route('imports.historical.resolve', $batch) }}" class="ar-btn ar-btn-primary">Abrir resolución de preview</a>
            </div>
        </div>

        @can('imports.execute')
            <form method="POST" action="{{ route('imports.historical.reprocess', $batch) }}" class="mb-4">
                @csrf
                <button class="ar-btn ar-btn-secondary">Reprocesar preview con reglas aprobadas</button>
            </form>

            @php($unknown = $batch->preview_payload['masters']['unknown_accounts'] ?? [])
            @if ($unknown)
                <div class="ar-card mb-4 space-y-3 p-4">
                    <h2 class="font-semibold">Aprobar mapping de cuenta (una vez → reaplica a filas compatibles)</h2>
                    <p class="ar-muted text-xs">Cuentas desconocidas: {{ implode(', ', array_keys($unknown)) }}</p>
                    <form method="POST" action="{{ route('imports.historical.approve-account', $batch) }}" class="grid gap-2 sm:grid-cols-3">
                        @csrf
                        <input name="excel_alias" class="ar-input" list="unknown-aliases" placeholder="Alias Excel (ej. Patagonia)" required>
                        <datalist id="unknown-aliases">
                            @foreach (array_keys($unknown) as $alias)
                                <option value="{{ $alias }}"></option>
                            @endforeach
                        </datalist>
                        <input name="name" class="ar-input" placeholder="Nombre AR (ej. Banco Patagonia)" required>
                        <select name="type" class="ar-input" required>
                            <option value="bank">Banco</option>
                            <option value="wallet">Billetera</option>
                            <option value="cash">Efectivo</option>
                            <option value="credit_card">Tarjeta</option>
                            <option value="other">Otro</option>
                        </select>
                        <select name="currency" class="ar-input" required>
                            <option value="ARS">ARS</option>
                            <option value="USD">USD</option>
                        </select>
                        <select name="holder" class="ar-input" required>
                            <option value="fernando">Fernando</option>
                            <option value="gabi">Gabi</option>
                        </select>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="liability" value="1"> Pasivo (tarjeta)</label>
                        <button class="ar-btn ar-btn-primary sm:col-span-3">Aprobar mapping y reprocesar</button>
                    </form>
                </div>
            @endif
        @endcan

        @php($groups = $batch->preview_payload['root_cause_groups'] ?? ['yellow' => [], 'red' => []])
        <div class="grid gap-4 lg:grid-cols-2 mb-4">
            <div class="ar-card p-4">
                <h2 class="mb-2 font-semibold">Amarillos por causa</h2>
                <ul class="space-y-2 text-sm">
                    @forelse (($groups['yellow'] ?? []) as $g)
                        <li>
                            <strong>{{ $g['label'] ?? $g['cause'] }}</strong>: {{ $g['count'] }}
                            <div class="ar-muted text-xs">Riesgo: {{ $g['mass_apply_risk'] ?? '—' }}</div>
                            <div class="text-xs">Regla: {{ $g['proposed_rule']['description'] ?? '' }}</div>
                        </li>
                    @empty
                        <li class="ar-muted">Sin grupos</li>
                    @endforelse
                </ul>
            </div>
            <div class="ar-card p-4">
                <h2 class="mb-2 font-semibold">Rojos por causa</h2>
                <ul class="space-y-2 text-sm">
                    @forelse (($groups['red'] ?? []) as $g)
                        <li>
                            <strong>{{ $g['label'] ?? $g['cause'] }}</strong>: {{ $g['count'] }}
                            <div class="ar-muted text-xs">Riesgo: {{ $g['mass_apply_risk'] ?? '—' }}</div>
                            <div class="text-xs">Regla: {{ $g['proposed_rule']['description'] ?? '' }}</div>
                        </li>
                    @empty
                        <li class="ar-muted">Sin grupos</li>
                    @endforelse
                </ul>
            </div>
        </div>

        @php($attr = $batch->preview_payload['difference_attribution'] ?? [])
        @if ($attr)
            <div class="ar-card mb-4 p-4 text-sm">
                <h2 class="mb-2 font-semibold">Atribución de diferencias (top filas)</h2>
                @foreach (['ingresos' => 'Ingresos', 'cc_out' => 'CC OUT', 'cc_in' => 'CC IN'] as $key => $label)
                    <div class="mb-3">
                        <p class="font-medium">{{ $label }} — gap {{ number_format((float) ($attr[$key]['total_gap'] ?? 0), 2, ',', '.') }}</p>
                        <ul class="ar-muted text-xs">
                            @foreach (array_slice($attr[$key]['top_rows'] ?? [], 0, 5) as $r)
                                <li>Fila {{ $r['source_row'] ?? '?' }} · {{ $r['concepto'] ?? '' }} · gap {{ number_format((float) ($r['gap'] ?? 0), 2, ',', '.') }} · {{ $r['root_cause'] ?? '' }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        @endif
    @elseif ($batch->importer_kind === 'supplier_catalog')
        @php($summary = $batch->classification_summary ?? [])
        <div class="ar-card mb-4 p-4 text-sm">
            <p>Productos válidos: <strong>{{ $summary['products_valid'] ?? 0 }}</strong></p>
            <p>A crear: <strong>{{ $summary['products_to_create'] ?? 0 }}</strong></p>
            <p>Familias: {{ $summary['families'] ?? 0 }} · Fabricantes: {{ $summary['manufacturers'] ?? 0 }} · Part Numbers: {{ $summary['part_numbers'] ?? 0 }} · Duplicados: {{ $summary['duplicates'] ?? 0 }}</p>
            <p class="ar-muted mt-2">{{ $summary['note'] ?? '' }}</p>
        </div>
        <div class="ar-card mb-4 overflow-x-auto">
            <table class="ar-table">
                <thead><tr><th>Fila</th><th>SKU</th><th>Código prov.</th><th>PN</th><th>Nombre</th><th>USD</th><th>Estado</th></tr></thead>
                <tbody>
                    @foreach (array_slice($batch->preview_payload['products'] ?? [], 0, 50) as $row)
                        <tr>
                            <td>{{ $row['source_row'] ?? '' }}</td>
                            <td>{{ $row['sku'] ?? '' }}</td>
                            <td>{{ $row['supplier_code'] ?? '' }}</td>
                            <td>{{ $row['part_number'] ?? '' }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($row['name'] ?? '', 50) }}</td>
                            <td class="text-right">{{ $row['cost_usd'] ?? '' }}</td>
                            <td>{{ $row['review_status'] ?? '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($batch->status === 'preview')
            @can('imports.execute')
                <form method="POST" action="{{ route('imports.historical.confirm-catalog', $batch) }}" onsubmit="return confirm('¿Crear productos con stock 0?');">
                    @csrf
                    <button class="ar-btn ar-btn-primary">Confirmar catálogo (stock 0)</button>
                </form>
            @endcan
        @endif
    @else
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
        @if ($batch->status === 'preview')
            @can('imports.execute')
                <form method="POST" action="{{ route('imports.confirm', $batch) }}">@csrf<button class="ar-btn ar-btn-primary">Confirmar importación</button></form>
            @endcan
        @endif
    @endif

    @if ($batch->status === 'confirmed')
        @can('imports.execute')
            <form method="POST" action="{{ route('imports.rollback', $batch) }}" class="ar-card mt-4 flex gap-2 p-4">
                @csrf
                <input name="reason" class="ar-input" placeholder="Motivo rollback" required>
                <button class="ar-btn ar-btn-secondary">Revertir lote</button>
            </form>
        @endcan
    @endif
</x-app-layout>
