<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">Cotizaciones</h1>
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

    @if (count($chartPoints) > 1)
        @php
            $values = array_column($chartPoints, 'value');
            $min = min($values);
            $max = max($values);
            $span = max($max - $min, 0.01);
            $w = 600; $h = 120; $pad = 8;
            $n = count($chartPoints);
            $pts = [];
            foreach ($chartPoints as $i => $p) {
                $x = $pad + ($n === 1 ? 0 : ($i / ($n - 1)) * ($w - 2 * $pad));
                $y = $pad + (1 - (($p['value'] - $min) / $span)) * ($h - 2 * $pad);
                $pts[] = round($x, 1).','.round($y, 1);
            }
        @endphp
        <div class="ar-card mb-4 p-4">
            <h2 class="mb-2 text-sm font-semibold">Evolución venta (período)</h2>
            <svg viewBox="0 0 {{ $w }} {{ $h }}" class="h-28 w-full" role="img" aria-label="Gráfico de cotización">
                <polyline fill="none" stroke="var(--ar-brand)" stroke-width="2" points="{{ implode(' ', $pts) }}" />
            </svg>
            <p class="ar-muted mt-1 text-xs">{{ number_format($min, 2, ',', '.') }} — {{ number_format($max, 2, ',', '.') }} · {{ count($chartPoints) }} puntos</p>
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
