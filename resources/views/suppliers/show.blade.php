<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">{{ $supplier->name }}</h1>
                <p class="ar-muted text-sm">{{ $supplier->business_name }} · {{ $supplier->tax_condition }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('suppliers.edit')
                    <a href="{{ route('suppliers.edit', $supplier) }}" class="ar-btn ar-btn-secondary">Editar</a>
                @endcan
                @can('purchases.create')
                    <a href="{{ route('purchases.create', ['supplier_id' => $supplier->id]) }}" class="ar-btn ar-btn-secondary">Nueva compra</a>
                @endcan
                @can('suppliers.create')
                    <a href="{{ route('suppliers.ledger.payment.create', $supplier) }}" class="ar-btn ar-btn-primary">Pago</a>
                    <a href="{{ route('suppliers.ledger.adjustment.create', $supplier) }}" class="ar-btn ar-btn-secondary">Ajuste</a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="mb-4 grid gap-4 sm:grid-cols-2">
        <div class="ar-card p-5">
            <h2 class="ar-muted text-sm">Saldo ARS</h2>
            <p class="text-2xl font-bold">{{ number_format((float) $balances['ARS'], 2, ',', '.') }}</p>
            <p class="ar-muted mt-1 text-xs">Negativo = le debemos · Positivo = a nuestro favor</p>
        </div>
        <div class="ar-card p-5">
            <h2 class="ar-muted text-sm">Saldo USD</h2>
            <p class="text-2xl font-bold">{{ number_format((float) $balances['USD'], 2, ',', '.') }}</p>
            <p class="ar-muted mt-1 text-xs">Saldos independientes (sin conversión automática)</p>
        </div>
    </div>

    <div class="mb-4 grid gap-4 lg:grid-cols-3">
        <div class="ar-card space-y-1 p-4 text-sm lg:col-span-2">
            <p><span class="ar-muted">CUIT:</span> {{ $supplier->cuit ?: '—' }} · <span class="ar-muted">DNI:</span> {{ $supplier->dni ?: '—' }}</p>
            <p><span class="ar-muted">Tel:</span> {{ $supplier->phone ?: '—' }} · <span class="ar-muted">Email:</span> {{ $supplier->email ?: '—' }}</p>
            <p><span class="ar-muted">Contacto:</span> {{ $supplier->contact_name ?: '—' }}</p>
            <p><span class="ar-muted">Dirección:</span> {{ $supplier->address ?: '—' }}</p>
            @if ($supplier->notes)
                <p class="ar-muted">{{ $supplier->notes }}</p>
            @endif
        </div>

        @can('documents.create')
            <form method="POST" action="{{ route('suppliers.documents.store', $supplier) }}" enctype="multipart/form-data" class="ar-card space-y-2 p-4">
                @csrf
                <h2 class="font-semibold">Documento</h2>
                <input type="file" name="file" class="ar-input" required>
                <input type="text" name="notes" class="ar-input" placeholder="Notas (opcional)">
                <button class="ar-btn ar-btn-secondary w-full">Adjuntar</button>
            </form>
        @endcan
    </div>

    @if ($supplier->documents->isNotEmpty())
        <div class="ar-card mb-4 p-4">
            <h2 class="mb-2 font-semibold">Documentos</h2>
            <ul class="list-disc ps-5 text-sm">
                @foreach ($supplier->documents as $doc)
                    <li>{{ $doc->original_name }} @if($doc->notes)— {{ $doc->notes }}@endif</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($purchases->isNotEmpty())
        <div class="ar-card mb-4 overflow-x-auto">
            <h2 class="border-b px-4 py-3 font-semibold" style="border-color: var(--ar-border);">Últimas compras</h2>
            <table class="ar-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Comprobante</th>
                        <th>Modo</th>
                        <th class="text-right">Total</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($purchases as $purchase)
                        <tr>
                            <td>{{ $purchase->purchase_date?->format('d/m/Y') }}</td>
                            <td>{{ trim(($purchase->voucher_type ?? '').' '.($purchase->voucher_letter ?? '').' '.($purchase->voucher_number ?? '')) ?: '—' }}</td>
                            <td>{{ $purchase->payment_mode === 'cash' ? 'Contado' : 'Crédito' }}</td>
                            <td class="text-right">{{ $purchase->currency->code }} {{ number_format((float) $purchase->total, 2, ',', '.') }}</td>
                            <td>{{ $purchase->status->value }}</td>
                            <td class="text-right"><a href="{{ route('purchases.show', $purchase) }}" style="color: var(--ar-brand);">Ver</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="ar-card overflow-x-auto">
        <h2 class="border-b px-4 py-3 font-semibold" style="border-color: var(--ar-border);">Movimientos de cuenta corriente</h2>
        <table class="ar-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Tipo</th>
                    <th>Moneda</th>
                    <th class="text-right">Importe</th>
                    <th class="text-right">Efecto</th>
                    <th>Compra</th>
                    <th>Finanzas</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($entries as $entry)
                    <tr>
                        <td>{{ $entry->entry_date?->format('d/m/Y') }}</td>
                        <td>{{ $entry->type->label() }}</td>
                        <td>{{ $entry->currency->code }}</td>
                        <td class="text-right">{{ number_format((float) $entry->amount, 2, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) $entry->signed_amount, 2, ',', '.') }}</td>
                        <td>
                            @if ($entry->purchase_id)
                                <a href="{{ route('purchases.show', $entry->purchase_id) }}" style="color: var(--ar-brand);">#{{ $entry->purchase_id }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if ($entry->financial_movement_id)
                                <a href="{{ route('movements.show', $entry->financial_movement_id) }}" style="color: var(--ar-brand);">#{{ $entry->financial_movement_id }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $entry->status->value }}</td>
                        <td>
                            @if ($entry->isPosted())
                                @can('suppliers.void')
                                    <form method="POST" action="{{ route('suppliers.ledger.void', [$supplier, $entry]) }}" class="flex gap-1">
                                        @csrf
                                        <input type="text" name="void_reason" class="ar-input" placeholder="Motivo" required>
                                        <button class="ar-btn ar-btn-secondary text-xs">Anular</button>
                                    </form>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="ar-muted py-6 text-center">Sin movimientos.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $entries->links() }}</div>
</x-app-layout>
