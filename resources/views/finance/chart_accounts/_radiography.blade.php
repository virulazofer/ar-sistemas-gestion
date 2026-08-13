<div>
    <h2 class="text-lg font-semibold">Radiografía del período</h2>
    <p class="ar-muted text-sm mb-4">
        Resumen de las cinco raíces · {{ $period['label'] ?? '' }}
        @if (!empty($period['from']) && !empty($period['to']))
            ({{ \Carbon\Carbon::parse($period['from'])->format('d/m/Y') }} – {{ \Carbon\Carbon::parse($period['to'])->format('d/m/Y') }})
        @endif
    </p>
    <p class="ar-muted text-sm mb-4">
        Seleccioná una cuenta del árbol para ver su detalle. Plan ≠ cuenta financiera.
    </p>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($radiography as $root)
            @php
                $display = $root['display'] ?? 'amount';
                $mode = $root['amount_mode'] ?? \App\Support\UiSemantics::MODE_RESULT;
            @endphp
            <a href="{{ route('chart-accounts.index', array_merge($qs ?? [], ['account' => $root['id']])) }}"
               class="rounded border p-3 block hover:bg-black/[0.02]"
               style="border-color: var(--ar-border);">
                <p class="ar-muted text-xs">{{ $root['code'] }}</p>
                <p class="font-semibold">{{ $root['name'] }}</p>
                @if ($display !== 'amount')
                    <p class="ar-muted text-sm mt-2">{{ $root['display_label'] }}</p>
                @else
                    <p class="mt-2 text-lg tabular-nums {{ \App\Support\UiSemantics::cssClass((string)($root['total_ars'] ?? '0'), $mode) }}">
                        ${{ number_format((float) ($root['total_ars'] ?? 0), 2, ',', '.') }}
                    </p>
                @endif
                @if (!empty($root['help_text']))
                    <p class="ar-muted text-xs mt-2">{{ $root['help_text'] }}</p>
                @endif
            </a>
        @endforeach
    </div>
</div>
