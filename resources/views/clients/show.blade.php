<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">{{ $client->name }}</h1>
                <p class="ar-muted text-sm">{{ $client->business_name }} · {{ $client->tax_condition }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('clients.edit')
                    <a href="{{ route('clients.edit', $client) }}" class="ar-btn ar-btn-secondary">Editar</a>
                @endcan
                @can('clients.create')
                    <a href="{{ route('clients.ledger.charge.create', $client) }}" class="ar-btn ar-btn-secondary">Cargo</a>
                    <a href="{{ route('clients.ledger.payment.create', $client) }}" class="ar-btn ar-btn-primary">Pago</a>
                    <a href="{{ route('clients.ledger.credit.create', $client) }}" class="ar-btn ar-btn-secondary">Crédito</a>
                    <a href="{{ route('clients.ledger.adjustment.create', $client) }}" class="ar-btn ar-btn-secondary">Ajuste</a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="mb-4 grid gap-4 sm:grid-cols-2">
        <div class="ar-card p-5">
            <h2 class="ar-muted text-sm">Saldo ARS</h2>
            <p class="text-2xl font-bold">{{ number_format((float) $balances['ARS'], 2, ',', '.') }}</p>
            <p class="ar-muted mt-1 text-xs">Negativo = deuda · Positivo = a favor</p>
        </div>
        <div class="ar-card p-5">
            <h2 class="ar-muted text-sm">Saldo USD</h2>
            <p class="text-2xl font-bold">{{ number_format((float) $balances['USD'], 2, ',', '.') }}</p>
            <p class="ar-muted mt-1 text-xs">Saldos independientes (sin conversión automática)</p>
        </div>
    </div>

    <div class="mb-4 grid gap-4 lg:grid-cols-3">
        <div class="ar-card space-y-1 p-4 text-sm lg:col-span-2">
            <p><span class="ar-muted">CUIT:</span> {{ $client->cuit ?: '—' }} · <span class="ar-muted">DNI:</span> {{ $client->dni ?: '—' }}</p>
            <p><span class="ar-muted">Tel:</span> {{ $client->phone ?: '—' }} · <span class="ar-muted">Email:</span> {{ $client->email ?: '—' }}</p>
            <p><span class="ar-muted">Dirección:</span> {{ $client->address ?: '—' }}</p>
            @if ($client->notes)
                <p class="ar-muted">{{ $client->notes }}</p>
            @endif
        </div>

        @can('documents.create')
            <form method="POST" action="{{ route('clients.documents.store', $client) }}" enctype="multipart/form-data" class="ar-card space-y-2 p-4">
                @csrf
                <h2 class="font-semibold">Documento</h2>
                <input type="file" name="file" class="ar-input" required>
                <input type="text" name="notes" class="ar-input" placeholder="Notas (opcional)">
                <button class="ar-btn ar-btn-secondary w-full">Adjuntar</button>
            </form>
        @endcan
    </div>

    @if ($client->documents->isNotEmpty())
        <div class="ar-card mb-4 p-4">
            <h2 class="mb-2 font-semibold">Documentos</h2>
            <ul class="list-disc ps-5 text-sm">
                @foreach ($client->documents as $doc)
                    <li>{{ $doc->original_name }} @if($doc->notes)— {{ $doc->notes }}@endif</li>
                @endforeach
            </ul>
        </div>
    @endif

    @can('clients.create')
        <form method="POST" action="{{ route('clients.ledger.credit.apply', $client) }}" class="ar-card mb-4 flex flex-wrap items-end gap-2 p-4">
            @csrf
            <div>
                <label class="ar-label">Aplicar crédito</label>
                <select name="currency_code" class="ar-input">
                    <option value="USD">USD</option>
                    <option value="ARS">ARS</option>
                </select>
            </div>
            <div>
                <label class="ar-label">Importe</label>
                <input type="number" step="0.01" min="0.01" name="amount" class="ar-input" required>
            </div>
            <button class="ar-btn ar-btn-secondary">Aplicar</button>
        </form>
    @endcan

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
                            @if ($entry->financial_movement_id)
                                <a href="{{ route('movements.show', $entry->financial_movement_id) }}" style="color: var(--ar-brand);">#{{ $entry->financial_movement_id }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $entry->status->value }}</td>
                        <td>
                            @if ($entry->isPosted())
                                @can('clients.void')
                                    <form method="POST" action="{{ route('clients.ledger.void', [$client, $entry]) }}" class="flex gap-1">
                                        @csrf
                                        <input type="text" name="void_reason" class="ar-input" placeholder="Motivo" required>
                                        <button class="ar-btn ar-btn-secondary text-xs">Anular</button>
                                    </form>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="ar-muted py-6 text-center">Sin movimientos.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $entries->links() }}</div>
</x-app-layout>
