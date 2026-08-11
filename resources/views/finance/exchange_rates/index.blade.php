<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <h1 class="text-xl font-semibold">Cotizaciones</h1>
                <x-page-help topic="exchange_rates" />
            </div>
            @can('exchange_rates.create')
                <a href="{{ route('exchange-rates.import') }}" class="ar-btn ar-btn-secondary text-xs">Importar histórico</a>
            @endcan
        </div>
    </x-slot>

    <div class="mb-4 grid gap-4 lg:grid-cols-2">
        <div class="ar-card p-5">
            <h2 class="mb-2 font-semibold">Cotización actual</h2>
            @if ($latest)
                <p class="text-2xl font-bold">{{ number_format((float) $latest->rate, 2, ',', '.') }}</p>
                <p class="ar-muted text-sm">ARS por 1 USD · venta oficial</p>
                @if ($latest->rate_buy)
                    <p class="mt-1 text-sm">Compra: <strong>{{ number_format((float) $latest->rate_buy, 2, ',', '.') }}</strong></p>
                @endif
                <p class="ar-muted mt-2 text-sm">Última actualización: {{ $latest->rate_at?->format('d/m/Y H:i') }}</p>
                <p class="ar-muted text-sm">Origen: {{ $sourceLabel }} · provider {{ $latest->provider }}</p>
            @else
                <p class="ar-muted">Sin cotizaciones.</p>
            @endif

            @can('exchange_rates.create')
                <form method="POST" action="{{ route('exchange-rates.sync') }}" class="mt-4">
                    @csrf
                    <button class="ar-btn ar-btn-secondary">Actualizar desde DolarAPI</button>
                </form>
                @error('sync')
                    <p class="mt-2 text-sm" style="color: var(--ar-danger);">{{ $message }}</p>
                @enderror
            @endcan
        </div>

        @can('exchange_rates.create')
            <form method="POST" action="{{ route('exchange-rates.manual') }}" class="ar-card space-y-3 p-5">
                @csrf
                <h2 class="font-semibold">Carga manual</h2>
                <div>
                    <label class="ar-label" for="rate">Venta (ARS por USD)</label>
                    <input id="rate" name="rate" type="number" step="0.000001" min="0.000001" class="ar-input" required>
                </div>
                <div>
                    <label class="ar-label" for="rate_buy">Compra (opcional)</label>
                    <input id="rate_buy" name="rate_buy" type="number" step="0.000001" min="0.000001" class="ar-input">
                </div>
                <div>
                    <label class="ar-label" for="notes">Notas</label>
                    <input id="notes" name="notes" class="ar-input">
                </div>
                <button class="ar-btn ar-btn-primary">Guardar cotización</button>
            </form>
        @endcan
    </div>

    <form method="GET" class="ar-card mb-4 flex flex-wrap items-end gap-3 p-4">
        <div>
            <label class="ar-label" for="from">Desde</label>
            <input id="from" type="date" name="from" value="{{ $from->format('Y-m-d') }}" class="ar-input">
        </div>
        <div>
            <label class="ar-label" for="to">Hasta</label>
            <input id="to" type="date" name="to" value="{{ $to->format('Y-m-d') }}" class="ar-input">
        </div>
        <button class="ar-btn ar-btn-secondary">Filtrar historial</button>
    </form>

    @can('exchange_rates.create')
        <div class="ar-card mb-4 space-y-3 p-4">
            <h2 class="font-semibold">Backfill histórico (ArgentinaDatos · oficial/BNA)</h2>
            <p class="ar-muted text-sm">DolarAPI sigue siendo la fuente de la cotización vigente. Este backfill completa el histórico sin inventar fines de semana.</p>
            <form method="POST" action="{{ route('exchange-rates.backfill.preview') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <div>
                    <label class="ar-label" for="bf_from">Desde</label>
                    <input id="bf_from" type="date" name="from" value="{{ old('from', $backfillPreview['from'] ?? '2026-01-01') }}" class="ar-input" required>
                </div>
                <div>
                    <label class="ar-label" for="bf_to">Hasta</label>
                    <input id="bf_to" type="date" name="to" value="{{ old('to', $backfillPreview['to'] ?? now()->toDateString()) }}" class="ar-input">
                </div>
                <button class="ar-btn ar-btn-secondary">Vista previa</button>
            </form>
            @error('backfill')
                <p class="text-sm" style="color: var(--ar-danger);">{{ $message }}</p>
            @enderror
            @if (! empty($backfillPreview))
                <div class="rounded border p-3 text-sm" style="border-color: var(--ar-border);">
                    <p><strong>{{ $backfillPreview['from'] }} → {{ $backfillPreview['to'] }}</strong></p>
                    <p>Filas API: {{ $backfillPreview['api_rows'] }} · A importar: {{ $backfillPreview['to_import'] }} · Ya presentes: {{ $backfillPreview['already_present'] }}</p>
                    <p class="ar-muted">{{ $backfillPreview['weekend_note'] }}</p>
                    @if (! empty($backfillPreview['sample']))
                        <ul class="mt-2 list-disc ps-5">
                            @foreach ($backfillPreview['sample'] as $row)
                                <li>{{ $row['fecha'] }} · compra {{ $row['compra'] ?? '—' }} · venta {{ $row['venta'] }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <form method="POST" action="{{ route('exchange-rates.backfill.confirm') }}" class="mt-3">
                        @csrf
                        <button class="ar-btn ar-btn-primary">Confirmar backfill</button>
                    </form>
                </div>
            @endif
        </div>
    @endcan

    @if (count($chartPoints) > 1)
        @php
            $sellValues = array_column($chartPoints, 'sell');
            $buyValues = array_values(array_filter(array_map(fn ($p) => $p['buy'] ?? null, $chartPoints), fn ($v) => $v !== null));
            $allValues = array_merge($sellValues, $buyValues);
            $min = min($allValues);
            $max = max($allValues);
            $span = max($max - $min, 0.01);
            $w = 640; $h = 200; $padL = 48; $padR = 12; $padT = 16; $padB = 36;
            $n = count($chartPoints);
            $xAt = fn (int $i) => $padL + ($n === 1 ? 0 : ($i / ($n - 1)) * ($w - $padL - $padR));
            $yAt = fn (float $v) => $padT + (1 - (($v - $min) / $span)) * ($h - $padT - $padB);
            $sellPts = [];
            $buyPts = [];
            foreach ($chartPoints as $i => $p) {
                $sellPts[] = round($xAt($i), 1).','.round($yAt((float) $p['sell']), 1);
                if (($p['buy'] ?? null) !== null) {
                    $buyPts[] = round($xAt($i), 1).','.round($yAt((float) $p['buy']), 1);
                }
            }
            $tickIdx = array_values(array_unique([0, (int) floor(($n - 1) / 2), $n - 1]));
            $yTicks = [$min, ($min + $max) / 2, $max];
        @endphp
        <div
            class="ar-card mb-4 p-4"
            x-data="{
                tip: null,
                points: {{ Js::from($chartPoints) }},
                show(i, ev) {
                    const p = this.points[i];
                    if (!p) return;
                    this.tip = {
                        x: ev.offsetX,
                        y: ev.offsetY,
                        date: p.date || p.label,
                        buy: p.buy,
                        sell: p.sell,
                        source: p.source || '—',
                    };
                },
                hide() { this.tip = null }
            }"
        >
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold">Evolución ARS/USD (período)</h2>
                <div class="flex items-center gap-4 text-xs">
                    <span class="inline-flex items-center gap-1"><span class="inline-block h-0.5 w-4" style="background: var(--ar-brand);"></span> Venta</span>
                    <span class="inline-flex items-center gap-1"><span class="inline-block h-0.5 w-4" style="background: var(--ar-muted, #64748b);"></span> Compra</span>
                </div>
            </div>
            <div class="relative">
                <svg viewBox="0 0 {{ $w }} {{ $h }}" class="h-48 w-full" role="img" aria-label="Gráfico de cotización compra y venta">
                    @foreach ($yTicks as $yt)
                        <line x1="{{ $padL }}" y1="{{ round($yAt($yt), 1) }}" x2="{{ $w - $padR }}" y2="{{ round($yAt($yt), 1) }}" stroke="var(--ar-border)" stroke-width="1" stroke-dasharray="3 3" />
                        <text x="{{ $padL - 4 }}" y="{{ round($yAt($yt), 1) + 3 }}" text-anchor="end" font-size="9" fill="currentColor">{{ number_format($yt, 0, ',', '.') }}</text>
                    @endforeach
                    <text x="12" y="12" font-size="9" fill="currentColor">ARS/USD</text>
                    @if (count($buyPts) > 1)
                        <polyline fill="none" stroke="var(--ar-muted, #64748b)" stroke-width="1.5" stroke-dasharray="4 3" points="{{ implode(' ', $buyPts) }}" />
                    @endif
                    <polyline fill="none" stroke="var(--ar-brand)" stroke-width="2" points="{{ implode(' ', $sellPts) }}" />
                    @foreach ($chartPoints as $i => $p)
                        <circle
                            cx="{{ round($xAt($i), 1) }}"
                            cy="{{ round($yAt((float) $p['sell']), 1) }}"
                            r="4"
                            fill="var(--ar-brand)"
                            class="cursor-pointer"
                            @mouseenter="show({{ $i }}, $event)"
                            @mousemove="show({{ $i }}, $event)"
                            @mouseleave="hide()"
                        />
                    @endforeach
                    @foreach ($tickIdx as $ti)
                        <text x="{{ round($xAt($ti), 1) }}" y="{{ $h - 8 }}" text-anchor="middle" font-size="9" fill="currentColor">{{ $chartPoints[$ti]['label'] ?? '' }}</text>
                    @endforeach
                </svg>
                <div
                    x-show="tip"
                    x-cloak
                    class="pointer-events-none absolute z-10 rounded border px-2 py-1 text-xs shadow"
                    style="border-color: var(--ar-border); background: var(--ar-surface, #fff); left: 0; top: 0;"
                    :style="tip ? `transform: translate(${tip.x + 12}px, ${tip.y - 8}px)` : ''"
                >
                    <template x-if="tip">
                        <div>
                            <p class="font-semibold" x-text="tip.date"></p>
                            <p>Compra: <span x-text="tip.buy != null ? Number(tip.buy).toLocaleString('es-AR', {minimumFractionDigits: 2}) : '—'"></span></p>
                            <p>Venta: <span x-text="Number(tip.sell).toLocaleString('es-AR', {minimumFractionDigits: 2})"></span></p>
                            <p class="ar-muted" x-text="'Fuente: ' + tip.source"></p>
                        </div>
                    </template>
                </div>
            </div>
            <p class="ar-muted mt-1 text-xs">{{ number_format($min, 2, ',', '.') }} — {{ number_format($max, 2, ',', '.') }} ARS/USD · {{ count($chartPoints) }} puntos</p>
        </div>
    @endif

    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead>
                <tr>
                    <th>Fecha/hora</th>
                    <th>Compra</th>
                    <th>Venta</th>
                    <th>Origen</th>
                    <th>Provider</th>
                    <th>Moneda</th>
                    <th>Usuario</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rates as $rate)
                    <tr>
                        <td>{{ $rate->rate_at?->format('d/m/Y H:i') }}</td>
                        <td>{{ $rate->rate_buy !== null ? number_format((float) $rate->rate_buy, 6, ',', '.') : '—' }}</td>
                        <td>{{ number_format((float) $rate->rate, 6, ',', '.') }}</td>
                        <td>{{ $rate->source }}</td>
                        <td>{{ $rate->provider }}</td>
                        <td>{{ $rate->baseCurrency?->code }}/{{ $rate->quoteCurrency?->code }}</td>
                        <td>{{ $rate->creator?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="ar-muted py-6 text-center">Sin registros en el período.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $rates->links() }}</div>
</x-app-layout>
