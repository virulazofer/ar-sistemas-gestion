<x-app-layout>
    @php
        $filter = $data['filter'];
        $summary = $data['summary'];
        $ccClass = fn (string $amount) => \App\Support\UiSemantics::kpiClass($amount, \App\Support\UiSemantics::MODE_CLIENT_CC);
    @endphp

    <x-slot name="header">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-xl font-semibold">Cuentas corrientes de clientes</h1>
                    <x-page-help topic="clients_cc" />
                </div>
                <p class="ar-muted text-sm">Ranking por saldo · positivo = nos deben · negativo = a favor del cliente</p>
            </div>
            <a href="{{ route('clients.index') }}" class="ar-btn ar-btn-secondary">ABM clientes</a>
        </div>
    </x-slot>

    <div class="mb-4 grid gap-3 sm:grid-cols-2">
        <div class="ar-card p-4">
            <p class="ar-muted text-xs">Clientes que adeudan</p>
            <p class="text-2xl font-bold {{ $ccClass($summary['to_collect']['ARS'] !== '0.00' || $summary['to_collect']['USD'] !== '0.00' ? '1.00' : '0.00') }}">
                {{ $summary['owing_clients_count'] }}
            </p>
            <p class="mt-1 text-sm {{ $ccClass($summary['to_collect']['ARS']) }}">
                Total a cobrar ARS: {{ \App\Support\Money::formatAr($summary['to_collect']['ARS'], 'ARS') }}
            </p>
            <p class="text-sm {{ $ccClass($summary['to_collect']['USD']) }}">
                Total a cobrar USD: {{ \App\Support\Money::formatAr($summary['to_collect']['USD'], 'USD') }}
            </p>
        </div>
        <div class="ar-card p-4">
            <p class="ar-muted text-xs">Clientes con saldo a favor</p>
            <p class="text-2xl font-bold {{ $ccClass($summary['in_favor']['ARS'] !== '0.00' || $summary['in_favor']['USD'] !== '0.00' ? '-1.00' : '0.00') }}">
                {{ $summary['credit_clients_count'] }}
            </p>
            <p class="mt-1 text-sm {{ $ccClass($summary['in_favor']['ARS'] === '0.00' ? '0.00' : '-1.00') }}">
                Saldo a favor ARS: {{ \App\Support\Money::formatAr($summary['in_favor']['ARS'], 'ARS') }}
            </p>
            <p class="text-sm {{ $ccClass($summary['in_favor']['USD'] === '0.00' ? '0.00' : '-1.00') }}">
                Saldo a favor USD: {{ \App\Support\Money::formatAr($summary['in_favor']['USD'], 'USD') }}
            </p>
        </div>
    </div>

    <form method="GET" action="{{ route('clients.current-accounts') }}" class="ar-card mb-4 flex flex-wrap items-end gap-3 p-4">
        <div>
            <label class="ar-label" for="filter">Filtro</label>
            <select id="filter" name="filter" class="ar-input" onchange="this.form.submit()">
                @foreach ($data['filters'] as $key => $label)
                    <option value="{{ $key }}" @selected($filter === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="min-w-[220px] flex-1">
            <label class="ar-label" for="q">Buscar cliente</label>
            <input id="q" name="q" type="search" class="ar-input" value="{{ $data['q'] }}" placeholder="Nombre…">
        </div>
        <button type="submit" class="ar-btn ar-btn-primary">Buscar</button>
    </form>

    <p class="ar-muted mb-2 text-xs">{{ $data['aging_note'] }}</p>

    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Moneda</th>
                    <th class="text-right">Saldo CC</th>
                    <th>Último movimiento</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($data['rows'] as $row)
                    <tr>
                        <td>{{ $row['name'] }}</td>
                        <td>{{ $row['currency'] }}</td>
                        <td class="text-right {{ $ccClass($row['balance']) }}">
                            @if ($row['currency'] === '—' || $row['currency'] === '')
                                {{ \App\Support\Money::formatAr($row['balance'], 'ARS') }}
                            @else
                                {{ \App\Support\Money::formatAr($row['balance'], $row['currency']) }}
                            @endif
                        </td>
                        <td>{{ $row['last_movement_label'] }}</td>
                        <td class="text-right">
                            @if ($row['url'])
                                <a href="{{ $row['url'] }}" class="text-sm" style="color: var(--ar-brand);">Ver cuenta corriente</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="ar-muted px-4 py-6 text-center">Sin resultados para este filtro.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
