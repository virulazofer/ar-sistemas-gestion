<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Clasificar movimientos — dry-run estructural</h1>
                <p class="ar-muted text-sm">Super / Comida / Miranda / MYU / Remotos / Auto — informe únicamente. Sin aplicar masa.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('chart-accounts.classify') }}" class="ar-btn ar-btn-secondary">Clasificar movimientos</a>
                <a href="{{ route('reports.show', 'operational-classification') }}" class="ar-btn ar-btn-secondary">Reporte operativo</a>
            </div>
        </div>
    </x-slot>

    @if (session('status'))
        <p class="ar-card mb-4 p-3 text-sm">{{ session('status') }}</p>
    @endif

    @can('categories.edit')
        <div class="ar-card mb-4 flex flex-wrap items-end gap-3 p-4">
            <form method="POST" action="{{ route('chart-accounts.dry-run') }}" class="flex flex-wrap gap-2">
                @csrf
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="ensure_taxonomy" value="1"> Asegurar cat/sub canónicas (maestro; no mueve movimientos)
                </label>
                <button class="ar-btn ar-btn-primary">Ejecutar dry-run</button>
            </form>
            <a href="{{ route('chart-accounts.export-ambiguous', ['format' => 'xlsx']) }}" class="ar-btn ar-btn-secondary">Export ambiguos XLSX</a>
            <a href="{{ route('chart-accounts.export-ambiguous', ['format' => 'csv']) }}" class="ar-btn ar-btn-secondary">Export ambiguos CSV</a>
        </div>
    @endcan

    @if (! empty($dryRun))
        <div class="ar-card mb-6 space-y-3 p-4">
            <h2 class="font-semibold">Tabla dry-run (sin aplicar)</h2>
            <div class="overflow-x-auto">
                <table class="ar-table text-sm">
                    <thead>
                        <tr>
                            <th>Grupo</th>
                            <th>Encontrados</th>
                            <th>Propuesta</th>
                            <th>Confianza</th>
                            <th>Alta conf.</th>
                            <th>Ambiguos</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($dryRun['groups'] as $g)
                            <tr>
                                <td>{{ $g['grupo'] }}</td>
                                <td class="tabular-nums">{{ $g['encontrados'] }}</td>
                                <td>{{ $g['propuesta'] }}</td>
                                <td>{{ $g['confianza'] }}</td>
                                <td class="tabular-nums">{{ $g['propuesta_alta_confianza'] }}</td>
                                <td class="tabular-nums">{{ $g['ambiguos'] ?? 0 }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <p>Pendientes antes: <strong>{{ $dryRun['summary']['pendientes_antes'] }}</strong></p>
                <p>Resueltos potencialmente: <strong>{{ $dryRun['summary']['resueltos_potencialmente'] }}</strong></p>
                <p>Pendientes reales después (est.): <strong>{{ $dryRun['summary']['pendientes_reales_despues_estimado'] }}</strong></p>
                <p>Cat OK sin cuenta contable (opcional): <strong>{{ $dryRun['summary']['missing_chart_optional'] }}</strong></p>
            </div>
            @if (! empty($dryRun['auto_breakdown']))
                <div>
                    <h3 class="font-semibold">Auto — breakdown por subcategoría</h3>
                    <ul class="list-disc ps-5 text-sm">
                        @foreach ($dryRun['auto_breakdown'] as $sub => $n)
                            <li>{{ $sub }}: {{ $n }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <p class="text-sm" style="color: var(--ar-danger);">DETENERSE: no aplicar reclasificación masiva hasta aprobación explícita.</p>
        </div>
    @endif

    @foreach ($analysis as $label => $block)
        <div class="ar-card mb-4 space-y-2 p-4">
            <h2 class="text-lg font-semibold">{{ $label }}</h2>
            <p class="text-sm">Movimientos: <strong>{{ $block['movement_count'] }}</strong> · Total ARS: <strong>{{ $block['total_ars'] ?? '—' }}</strong></p>
            <p class="text-sm">Ámbitos: {{ collect($block['by_scope'] ?? [])->map(fn ($c, $k) => "$k=$c")->implode(', ') ?: '—' }}</p>
            <p class="text-sm">Cuentas financieras: {{ collect($block['by_account'] ?? [])->take(8)->map(fn ($c, $k) => "$k=$c")->implode(', ') ?: '—' }}</p>
            <p class="text-sm">Categorías: {{ collect($block['by_category'] ?? [])->take(8)->map(fn ($c, $k) => "$k=$c")->implode(', ') ?: '—' }}</p>
            <div class="rounded border p-3 text-sm" style="border-color: var(--ar-border);">
                <p class="font-semibold">Propuesta (no aplicada)</p>
                <pre class="overflow-x-auto text-xs">{{ json_encode($block['proposal'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                <p class="ar-muted mt-2 text-xs">{{ $block['reason'] }}</p>
            </div>
            @if (! empty($block['concepts_sample']))
                <details>
                    <summary class="cursor-pointer text-sm font-medium">Muestra de conceptos</summary>
                    <ul class="mt-2 list-disc ps-5 text-xs">
                        @foreach ($block['concepts_sample'] as $row)
                            <li>#{{ $row['id'] }} {{ $row['date'] }} · {{ $row['description'] }} · {{ $row['amount_ars'] }} · {{ $row['scope'] }} · {{ $row['account'] }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </div>
    @endforeach
</x-app-layout>
