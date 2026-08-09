<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Compra #{{ $purchase->id }}</h1>
                <p class="ar-muted text-sm">
                    {{ $purchase->supplier->name }} ·
                    {{ $purchase->payment_mode === 'cash' ? 'Contado' : 'Crédito' }} ·
                    {{ $purchase->status->value }}
                </p>
            </div>
            <a href="{{ route('purchases.index') }}" class="ar-btn ar-btn-secondary">Listado</a>
        </div>
    </x-slot>

    <div class="mb-4 grid gap-4 lg:grid-cols-3">
        <div class="ar-card space-y-1 p-4 text-sm lg:col-span-2">
            <p><span class="ar-muted">Fecha:</span> {{ $purchase->purchase_date?->format('d/m/Y') }}</p>
            <p>
                <span class="ar-muted">Comprobante:</span>
                {{ trim(($purchase->voucher_type ?? '').' '.($purchase->voucher_letter ?? '').' '.($purchase->voucher_number ?? '')) ?: '—' }}
            </p>
            <p>
                <span class="ar-muted">Moneda:</span> {{ $purchase->currency->code }} ·
                <span class="ar-muted">Cotización congelada:</span>
                {{ $purchase->exchange_rate_value ? number_format((float) $purchase->exchange_rate_value, 6, ',', '.') : '—' }}
            </p>
            <p>
                <span class="ar-muted">Subtotal:</span> {{ number_format((float) $purchase->subtotal, 2, ',', '.') }} ·
                <span class="ar-muted">IVA:</span> {{ number_format((float) $purchase->tax_amount, 2, ',', '.') }} ·
                <span class="ar-muted">Otros:</span> {{ number_format((float) $purchase->other_taxes, 2, ',', '.') }} ·
                <span class="ar-muted">Desc.:</span> {{ number_format((float) $purchase->discount_amount, 2, ',', '.') }}
            </p>
            <p class="text-lg font-semibold">
                Total: {{ $purchase->currency->code }} {{ number_format((float) $purchase->total, 2, ',', '.') }}
                <span class="ar-muted text-sm font-normal">
                    (ARS {{ number_format((float) $purchase->total_ars, 2, ',', '.') }} ·
                    USD {{ number_format((float) $purchase->total_usd, 2, ',', '.') }})
                </span>
            </p>
            @if ($purchase->financial_movement_id)
                <p><span class="ar-muted">Egreso financiero:</span>
                    <a href="{{ route('movements.show', $purchase->financial_movement_id) }}" style="color: var(--ar-brand);">#{{ $purchase->financial_movement_id }}</a>
                    @if ($purchase->financialAccount) — {{ $purchase->financialAccount->name }} @endif
                </p>
            @endif
            @if ($purchase->obligation_ledger_entry_id)
                <p><span class="ar-muted">Obligación CC:</span> #{{ $purchase->obligation_ledger_entry_id }}</p>
            @endif
            @if ($purchase->notes)
                <p class="ar-muted">{{ $purchase->notes }}</p>
            @endif
            @if ($purchase->void_reason)
                <p class="text-sm" style="color: var(--ar-danger, #b91c1c);">Anulada: {{ $purchase->void_reason }}</p>
            @endif
        </div>

        <div class="space-y-4">
            @can('documents.create')
                <form method="POST" action="{{ route('purchases.documents.store', $purchase) }}" enctype="multipart/form-data" class="ar-card space-y-2 p-4">
                    @csrf
                    <h2 class="font-semibold">Documento</h2>
                    <input type="file" name="file" class="ar-input" required>
                    <input type="text" name="notes" class="ar-input" placeholder="factura / remito / otro">
                    <button class="ar-btn ar-btn-secondary w-full">Adjuntar</button>
                </form>
            @endcan

            @if ($purchase->isPosted())
                @can('purchases.void')
                    <form method="POST" action="{{ route('purchases.void', $purchase) }}" class="ar-card space-y-2 p-4">
                        @csrf
                        <h2 class="font-semibold">Anular compra</h2>
                        <input type="text" name="void_reason" class="ar-input" placeholder="Motivo" required>
                        <button class="ar-btn ar-btn-secondary w-full">Anular</button>
                    </form>
                @endcan
            @endif
        </div>
    </div>

    @if ($purchase->documents->isNotEmpty())
        <div class="ar-card mb-4 p-4">
            <h2 class="mb-2 font-semibold">Documentos</h2>
            <ul class="list-disc ps-5 text-sm">
                @foreach ($purchase->documents as $doc)
                    <li>{{ $doc->original_name }} @if($doc->notes)— {{ $doc->notes }}@endif</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="ar-card overflow-x-auto">
        <h2 class="border-b px-4 py-3 font-semibold" style="border-color: var(--ar-border);">Líneas y costo histórico</h2>
        <table class="ar-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Descripción</th>
                    <th class="text-right">Cant.</th>
                    <th class="text-right">P. unit.</th>
                    <th class="text-right">Total</th>
                    <th class="text-right">Costo u. ARS</th>
                    <th class="text-right">Costo u. USD</th>
                    <th class="text-right">Pend. stock</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($purchase->items as $item)
                    <tr>
                        <td>{{ $item->line_number }}</td>
                        <td>{{ $item->description }} @if($item->sku)<span class="ar-muted">({{ $item->sku }})</span>@endif</td>
                        <td class="text-right">{{ number_format((float) $item->quantity, 4, ',', '.') }} {{ $item->unit }}</td>
                        <td class="text-right">{{ number_format((float) $item->unit_price, 6, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) $item->line_total, 2, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) $item->unit_cost_ars, 6, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) $item->unit_cost_usd, 6, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) $item->qty_pending_stock, 4, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-app-layout>
