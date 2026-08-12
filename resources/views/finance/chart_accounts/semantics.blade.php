<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Análisis semántico pendiente</h1>
                <p class="ar-muted text-sm">Comida / Auto / Miranda / MYU — solo informe. No se aplican cambios automáticos.</p>
            </div>
            <a href="{{ route('chart-accounts.unclassified') }}" class="ar-btn ar-btn-secondary">Volver</a>
        </div>
    </x-slot>

    @foreach ($analysis as $label => $block)
        <div class="ar-card mb-4 space-y-2 p-4">
            <h2 class="text-lg font-semibold">{{ $label }}</h2>
            <p class="text-sm">Movimientos: <strong>{{ $block['movement_count'] }}</strong> · Total ARS: <strong>{{ $block['total_ars'] }}</strong></p>
            <p class="text-sm">Ámbitos: {{ collect($block['by_scope'])->map(fn ($c, $k) => "$k=$c")->implode(', ') ?: '—' }}</p>
            <p class="text-sm">Cuentas financieras: {{ collect($block['by_account'])->take(8)->map(fn ($c, $k) => "$k=$c")->implode(', ') ?: '—' }}</p>
            <p class="text-sm">Categorías: {{ collect($block['by_category'])->take(8)->map(fn ($c, $k) => "$k=$c")->implode(', ') ?: '—' }}</p>
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
