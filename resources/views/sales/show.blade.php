<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">{{ $sale->number }}</h1>
                <p class="ar-muted text-sm">{{ $sale->client->name }} · {{ $sale->status->label() }} · {{ $sale->origin }}</p>
            </div>
            <a href="{{ route('sales.index') }}" class="ar-btn ar-btn-secondary">Listado</a>
        </div>
    </x-slot>

    <div class="mb-4 grid gap-4 sm:grid-cols-4">
        <div class="ar-card p-4"><p class="ar-muted text-sm">Total</p><p class="text-xl font-bold">{{ $sale->currency_code }} {{ number_format((float) $sale->total, 2, ',', '.') }}</p></div>
        <div class="ar-card p-4"><p class="ar-muted text-sm">Costo FIFO</p><p class="text-xl font-bold">{{ number_format((float) $sale->total_cost, 2, ',', '.') }}</p></div>
        <div class="ar-card p-4"><p class="ar-muted text-sm">Margen</p><p class="text-xl font-bold">{{ number_format((float) $sale->gross_margin, 2, ',', '.') }}</p></div>
        <div class="ar-card p-4 text-sm">
            @if ($sale->quotation)
                <p><span class="ar-muted">Presupuesto:</span> {{ $sale->quotation->number }}</p>
            @endif
            @if ($sale->chargeEntry)
                <p><span class="ar-muted">Cargo CC:</span> #{{ $sale->charge_ledger_entry_id }}</p>
            @endif
            @if ($sale->paymentEntry)
                <p><span class="ar-muted">Pago:</span> #{{ $sale->payment_ledger_entry_id }}</p>
            @endif
        </div>
    </div>

    <div class="ar-card mb-4 overflow-x-auto">
        <table class="ar-table">
            <thead><tr><th>Tipo</th><th>Desc.</th><th class="text-right">Cant.</th><th class="text-right">Precio</th><th class="text-right">Costo</th><th class="text-right">Margen</th></tr></thead>
            <tbody>
                @foreach ($sale->items as $item)
                    <tr>
                        <td>{{ $item->item_type->label() }}</td>
                        <td>{{ $item->description }}</td>
                        <td class="text-right">{{ number_format((float) $item->quantity, 4, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) $item->line_total, 2, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) $item->line_cost, 2, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) $item->line_margin, 2, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($sale->isEditable())
        @can('sales.confirm')
            <form method="POST" action="{{ route('sales.confirm', $sale) }}" class="ar-card mb-4 flex flex-wrap items-end gap-3 p-4">
                @csrf
                <div>
                    <label class="ar-label">Modo</label>
                    <select name="payment_mode" class="ar-input" required>
                        <option value="credit">Crédito (cargo CC)</option>
                        <option value="cash">Contado (pago inmediato)</option>
                    </select>
                </div>
                <div>
                    <label class="ar-label">Cuenta (contado)</label>
                    <select name="financial_account_id" class="ar-input">
                        <option value="">—</option>
                        @foreach ($accounts as $acc)
                            <option value="{{ $acc->id }}">{{ $acc->name }} ({{ $acc->currency->code }})</option>
                        @endforeach
                    </select>
                </div>
                <button class="ar-btn ar-btn-primary">Confirmar venta</button>
            </form>
        @endcan
    @endif

    @if ($sale->status->value === 'confirmed')
        @can('sales.void')
            <form method="POST" action="{{ route('sales.void', $sale) }}" class="ar-card flex gap-2 p-4">
                @csrf
                <input name="reason" class="ar-input" placeholder="Motivo anulación" required>
                <button class="ar-btn ar-btn-secondary">Anular venta</button>
            </form>
        @endcan
    @endif
</x-app-layout>
