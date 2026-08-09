<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">Movimiento #{{ $movement->id }}</h1>
    </x-slot>

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="ar-card space-y-2 p-5 text-sm">
            <p><span class="ar-muted">Fecha:</span> {{ $movement->movement_date?->format('d/m/Y') }} {{ $movement->movement_time }}</p>
            <p><span class="ar-muted">Tipo:</span> {{ $movement->type->label() }}</p>
            <p><span class="ar-muted">Ámbito:</span> {{ $movement->scope->label() }}</p>
            <p><span class="ar-muted">Cuenta:</span> {{ $movement->account?->name }}</p>
            <p><span class="ar-muted">Importe:</span> {{ number_format((float) $movement->amount, 2, ',', '.') }} {{ $movement->currency?->code }}</p>
            <p><span class="ar-muted">Cotización congelada:</span> {{ $movement->exchange_rate_value }} ({{ $movement->exchange_rate_at }})</p>
            <p><span class="ar-muted">Equiv. ARS:</span> {{ number_format((float) $movement->amount_ars, 2, ',', '.') }}</p>
            <p><span class="ar-muted">Equiv. USD:</span> {{ number_format((float) $movement->amount_usd, 2, ',', '.') }}</p>
            <p><span class="ar-muted">Categoría:</span> {{ $movement->category?->name ?? '—' }} / {{ $movement->subcategory?->name ?? '—' }}</p>
            <p><span class="ar-muted">Descripción:</span> {{ $movement->description ?? '—' }}</p>
            <p><span class="ar-muted">Estado:</span> {{ $movement->status->value }}</p>
            @if ($movement->transfer_id)
                <p><span class="ar-muted">Transfer ID:</span> {{ $movement->transfer_id }}</p>
            @endif
            @if ($movement->status->value === 'voided')
                <p><span class="ar-muted">Anulado por:</span> {{ $movement->voidedByUser?->name }} · {{ $movement->voided_at }}</p>
                <p><span class="ar-muted">Motivo:</span> {{ $movement->void_reason }}</p>
            @endif
        </div>

        @if ($pair)
            <div class="ar-card space-y-2 p-5 text-sm">
                <h2 class="font-semibold">Pierna vinculada</h2>
                <p>{{ $pair->type->label() }} · {{ $pair->account?->name }} · {{ number_format((float) $pair->amount, 2, ',', '.') }}</p>
                <a href="{{ route('movements.show', $pair) }}" style="color: var(--ar-brand);">Ver #{{ $pair->id }}</a>
            </div>
        @endif
    </div>

    @if ($movement->isPosted())
        @can('movements.void')
            <form method="POST" action="{{ route('movements.void', $movement) }}" class="ar-card mt-4 max-w-xl space-y-3 p-5">
                @csrf
                <h2 class="font-semibold">Anular movimiento</h2>
                <p class="ar-muted text-sm">No se elimina: queda anulado y fuera de saldos. Si es transferencia, se anulan ambas piernas.</p>
                <textarea name="void_reason" class="ar-input" rows="3" required placeholder="Motivo de anulación"></textarea>
                <button class="ar-btn ar-btn-secondary" style="border-color: var(--ar-danger); color: var(--ar-danger);">Anular</button>
            </form>
        @endcan
    @endif
</x-app-layout>
