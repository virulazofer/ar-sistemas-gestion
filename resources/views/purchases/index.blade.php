<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Compras</h1>
                <p class="ar-muted text-sm">Contado o crédito · costos históricos congelados.</p>
            </div>
            @can('purchases.create')
                <a href="{{ route('purchases.create') }}" class="ar-btn ar-btn-primary">Nueva compra</a>
            @endcan
        </div>
    </x-slot>

    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Proveedor</th>
                    <th>Comprobante</th>
                    <th>Modo</th>
                    <th class="text-right">Total</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($purchases as $purchase)
                    <tr>
                        <td>{{ $purchase->purchase_date?->format('d/m/Y') }}</td>
                        <td>{{ $purchase->supplier->name }}</td>
                        <td>{{ trim(($purchase->voucher_type ?? '').' '.($purchase->voucher_letter ?? '').' '.($purchase->voucher_number ?? '')) ?: '—' }}</td>
                        <td>{{ $purchase->payment_mode === 'cash' ? 'Contado' : 'Crédito' }}</td>
                        <td class="text-right">{{ $purchase->currency->code }} {{ number_format((float) $purchase->total, 2, ',', '.') }}</td>
                        <td>{{ $purchase->status->value }}</td>
                        <td class="text-right">
                            <a href="{{ route('purchases.show', $purchase) }}" class="text-sm" style="color: var(--ar-brand);">Ver</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="ar-muted py-6 text-center">Sin compras.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $purchases->links() }}</div>
</x-app-layout>
