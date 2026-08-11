<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Cobros</h1>
                <p class="ar-muted text-sm">Ingreso financiero + CC OUT + aplicaciones a cargos.</p>
            </div>
            @can('receipts.create')
                <a href="{{ route('receipts.create') }}" class="ar-btn ar-btn-primary">Registrar cobro</a>
            @endcan
        </div>
    </x-slot>

    <form method="GET" class="mb-4 flex gap-2">
        <input type="search" name="q" value="{{ $q }}" class="ar-input" placeholder="Nº, cliente, concepto…">
        <button class="ar-btn ar-btn-secondary">Buscar</button>
    </form>

    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead>
                <tr>
                    <th>Nº</th>
                    <th>Fecha</th>
                    <th>Cliente</th>
                    <th class="text-right">Importe</th>
                    <th class="text-right">Aplicado</th>
                    <th class="text-right">A cuenta</th>
                    <th>Cuenta</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($receipts as $receipt)
                    <tr>
                        <td><a href="{{ route('receipts.show', $receipt) }}" style="color: var(--ar-brand);">{{ $receipt->number }}</a></td>
                        <td>{{ $receipt->received_on?->format('d/m/Y') }}</td>
                        <td>{{ $receipt->client?->labelWithCode() }}</td>
                        <td class="text-right">{{ number_format((float) $receipt->amount, 2, ',', '.') }} {{ $receipt->currency_code }}</td>
                        <td class="text-right">{{ number_format((float) $receipt->amount_applied, 2, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) $receipt->amount_on_account, 2, ',', '.') }}</td>
                        <td>{{ $receipt->financialAccount?->name }}</td>
                        <td>{{ $receipt->status->label() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="ar-muted py-6 text-center">Sin cobros.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $receipts->links() }}</div>
</x-app-layout>
