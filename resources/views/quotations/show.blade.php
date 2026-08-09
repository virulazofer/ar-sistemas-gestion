<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">{{ $quotation->number }}</h1>
                <p class="ar-muted text-sm">{{ $quotation->client->name }} · {{ $quotation->status->label() }} · vence {{ $quotation->valid_until?->format('d/m/Y') }}</p>
            </div>
            <a href="{{ route('quotations.index') }}" class="ar-btn ar-btn-secondary">Listado</a>
        </div>
    </x-slot>

    <div class="mb-4 grid gap-4 sm:grid-cols-4">
        <div class="ar-card p-4"><p class="ar-muted text-sm">Total</p><p class="text-xl font-bold">{{ $quotation->currency_code }} {{ number_format((float) $quotation->total, 2, ',', '.') }}</p></div>
        <div class="ar-card p-4"><p class="ar-muted text-sm">Costo est. (interno)</p><p class="text-xl font-bold">{{ number_format((float) $quotation->estimated_cost_usd, 2, ',', '.') }} USD</p></div>
        <div class="ar-card p-4"><p class="ar-muted text-sm">Margen est.</p><p class="text-xl font-bold">{{ number_format((float) $quotation->estimated_margin, 2, ',', '.') }}</p></div>
        <div class="ar-card p-4 text-sm">
            <p><span class="ar-muted">Cotización:</span> {{ number_format((float) $quotation->exchange_rate_value, 2, ',', '.') }}</p>
            @if ($quotation->convertedSale)
                <p><span class="ar-muted">Venta:</span> <a href="{{ route('sales.show', $quotation->convertedSale) }}" style="color: var(--ar-brand);">{{ $quotation->convertedSale->number }}</a></p>
            @endif
        </div>
    </div>

    <div class="ar-card mb-4 overflow-x-auto">
        <table class="ar-table">
            <thead><tr><th>Tipo</th><th>Descripción</th><th class="text-right">Cant.</th><th class="text-right">Precio</th><th class="text-right">Total</th><th class="text-right">Costo est.</th></tr></thead>
            <tbody>
                @foreach ($quotation->items as $item)
                    <tr>
                        <td>{{ $item->item_type->label() }}</td>
                        <td>{{ $item->description }}</td>
                        <td class="text-right">{{ number_format((float) $item->quantity, 4, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) $item->unit_price, 2, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) $item->line_total, 2, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) $item->estimated_cost_usd, 2, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="flex flex-wrap gap-2">
        @can('quotations.send')
            @if ($quotation->status->value === 'draft')
                <form method="POST" action="{{ route('quotations.send', $quotation) }}">@csrf<button class="ar-btn ar-btn-secondary">Marcar enviado</button></form>
            @endif
        @endcan
        @can('quotations.accept')
            @if (in_array($quotation->status->value, ['draft', 'sent']))
                <form method="POST" action="{{ route('quotations.accept', $quotation) }}">@csrf<button class="ar-btn ar-btn-secondary">Aceptar</button></form>
            @endif
        @endcan
        @can('quotations.convert')
            @if (in_array($quotation->status->value, ['sent', 'accepted']))
                <form method="POST" action="{{ route('quotations.convert', $quotation) }}">@csrf<button class="ar-btn ar-btn-primary">Convertir a venta</button></form>
            @endif
        @endcan
        @if ($quotation->status->value === 'expired')
            <form method="POST" action="{{ route('quotations.renew', $quotation) }}" class="ar-card flex gap-2 p-3">
                @csrf
                <input type="date" name="valid_until" class="ar-input" required>
                <button class="ar-btn ar-btn-secondary">Renovar</button>
            </form>
        @endif
        @can('quotations.cancel')
            @if (! in_array($quotation->status->value, ['converted', 'cancelled']))
                <form method="POST" action="{{ route('quotations.cancel', $quotation) }}" class="flex gap-2">
                    @csrf
                    <input name="reason" class="ar-input" placeholder="Motivo" required>
                    <button class="ar-btn ar-btn-secondary">Cancelar</button>
                </form>
            @endif
        @endcan
    </div>
</x-app-layout>
