<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">{{ $receipt->number }}</h1>
                <p class="ar-muted text-sm">{{ $receipt->client?->labelWithCode() }} · {{ $receipt->financialAccount?->name }}</p>
            </div>
            <a href="{{ route('clients.show', $receipt->client_id) }}" class="ar-btn ar-btn-secondary">Cuenta corriente</a>
        </div>
    </x-slot>

    <div class="mb-4 grid gap-4 sm:grid-cols-4">
        <div class="ar-card p-4"><p class="ar-muted text-xs">Cobrado</p><p class="text-lg font-semibold">{{ number_format((float) $receipt->amount, 2, ',', '.') }} {{ $receipt->currency_code }}</p></div>
        <div class="ar-card p-4"><p class="ar-muted text-xs">Aplicado a cargos</p><p class="text-lg font-semibold">{{ number_format((float) $receipt->amount_applied, 2, ',', '.') }}</p></div>
        <div class="ar-card p-4"><p class="ar-muted text-xs">Pago a cuenta</p><p class="text-lg font-semibold">{{ number_format((float) $receipt->amount_on_account, 2, ',', '.') }}</p></div>
        <div class="ar-card p-4"><p class="ar-muted text-xs">Estado</p><p class="text-lg font-semibold">{{ $receipt->status->label() }}</p></div>
    </div>

    <div class="ar-card mb-4 overflow-x-auto">
        <h2 class="border-b px-4 py-3 font-semibold" style="border-color: var(--ar-border);">Aplicaciones</h2>
        <table class="ar-table">
            <thead><tr><th>Cargo</th><th>Concepto</th><th class="text-right">Importe</th><th>Estado</th></tr></thead>
            <tbody>
                @forelse ($receipt->applications as $app)
                    <tr>
                        <td><a href="{{ route('charges.show', $app->commercial_charge_id) }}" style="color: var(--ar-brand);">{{ $app->charge?->number }}</a></td>
                        <td>{{ $app->charge?->concept }}</td>
                        <td class="text-right">{{ number_format((float) $app->amount, 2, ',', '.') }}</td>
                        <td>{{ $app->status->label() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="ar-muted py-4 text-center">Sin aplicaciones (pago a cuenta puro).</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($receipt->financial_movement_id)
        <p class="mb-4 text-sm">Movimiento financiero: <a href="{{ route('movements.show', $receipt->financial_movement_id) }}" style="color: var(--ar-brand);">#{{ $receipt->financial_movement_id }}</a></p>
    @endif

    @can('receipts.void')
        @if ($receipt->isPosted())
            <form method="POST" action="{{ route('receipts.void', $receipt) }}" class="ar-card flex flex-wrap items-end gap-2 p-4">
                @csrf
                <div class="grow">
                    <label class="ar-label">Anular cobro (revierte finanzas, CC y aplicaciones)</label>
                    <input name="void_reason" class="ar-input" required>
                </div>
                <button class="ar-btn ar-btn-secondary">Anular</button>
            </form>
        @endif
    @endcan
</x-app-layout>
