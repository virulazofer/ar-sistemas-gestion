<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Tablero financiero</h1>
                <p class="ar-muted text-sm">Dinero disponible (no incluye por cobrar / por pagar).</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('movements.create')
                    <a href="{{ route('movements.quick') }}" class="ar-btn ar-btn-primary">Cargar movimiento</a>
                @endcan
                @can('accounts.view')
                    <a href="{{ route('accounts.index') }}" class="ar-btn ar-btn-secondary">Cuentas</a>
                @endcan
                @can('movements.view')
                    <a href="{{ route('movements.index') }}" class="ar-btn ar-btn-secondary">Movimientos</a>
                @endcan
                @can('exchange_rates.view')
                    <a href="{{ route('exchange-rates.index') }}" class="ar-btn ar-btn-secondary">Cotizaciones</a>
                @endcan
                @can('categories.view')
                    <a href="{{ route('categories.index') }}" class="ar-btn ar-btn-secondary">Categorías</a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="ar-card p-5 lg:col-span-1">
            <h2 class="mb-3 font-semibold">Dinero disponible</h2>
            <p class="text-2xl font-bold">ARS {{ number_format((float) $money['ars_total'], 2, ',', '.') }}</p>
            <p class="mt-1 text-xl font-semibold">USD {{ number_format((float) $money['usd_total'], 2, ',', '.') }}</p>
            <p class="ar-muted mt-3 text-sm">Equiv. ARS: {{ number_format((float) $money['ars_equivalent'], 2, ',', '.') }}</p>
            <p class="ar-muted text-sm">Equiv. USD: {{ number_format((float) $money['usd_equivalent'], 2, ',', '.') }}</p>
            @if ($rate)
                <p class="ar-muted mt-3 text-xs">FX venta oficial {{ number_format((float) $rate->rate, 2, ',', '.') }} · {{ $rateLabel }}</p>
            @endif
        </div>

        <div class="ar-card p-5">
            <h2 class="mb-3 font-semibold">Actividad del mes</h2>
            <p>Ingresos: <strong>{{ number_format((float) $month['income'], 2, ',', '.') }}</strong></p>
            <p>Gastos: <strong>{{ number_format((float) $month['expense'], 2, ',', '.') }}</strong></p>
            <p class="mt-2">Resultado: <strong>{{ number_format((float) $month['result'], 2, ',', '.') }}</strong></p>
            <p class="ar-muted mt-3 text-sm">Personal: {{ number_format((float) $month['personal_result'], 2, ',', '.') }}</p>
            <p class="ar-muted text-sm">Profesional: {{ number_format((float) $month['professional_result'], 2, ',', '.') }}</p>
        </div>

        <div class="ar-card p-5">
            <h2 class="mb-3 font-semibold">Distribución (resultado mes)</h2>
            <p>Personal: {{ number_format((float) $money['by_scope']['personal']['result_ars'], 2, ',', '.') }}</p>
            <p>Profesional: {{ number_format((float) $money['by_scope']['professional']['result_ars'], 2, ',', '.') }}</p>
            <p class="ar-muted mt-3 text-xs">El dinero disponible es por cuenta; el ámbito se analiza en los movimientos.</p>
        </div>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <div class="ar-card overflow-x-auto p-5">
            <h2 class="mb-3 font-semibold">Cuentas</h2>
            <table class="ar-table">
                <thead>
                    <tr>
                        <th>Cuenta</th>
                        <th>Moneda</th>
                        <th class="text-right">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($money['accounts'] as $account)
                        <tr>
                            <td>{{ $account->name }}</td>
                            <td>{{ $account->currency->code }}</td>
                            <td class="text-right">{{ number_format((float) $account->computed_balance, 2, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="ar-card overflow-x-auto p-5">
            <h2 class="mb-3 font-semibold">Últimos movimientos</h2>
            <table class="ar-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Tipo</th>
                        <th>Cuenta</th>
                        <th class="text-right">Importe</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recent as $m)
                        <tr>
                            <td>{{ $m->movement_date?->format('d/m/Y') }}</td>
                            <td>{{ $m->type->label() }} @if($m->status->value === 'voided')(anulado)@endif</td>
                            <td>{{ $m->account?->name }}</td>
                            <td class="text-right">{{ number_format((float) $m->amount, 2, ',', '.') }} {{ $m->currency?->code ?? $m->account?->currency?->code }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="ar-muted text-center">Sin movimientos.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
