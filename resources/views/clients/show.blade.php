<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">{{ $client->labelWithCode() }}</h1>
                <p class="ar-muted text-sm">{{ $client->business_name }} · {{ $client->taxConditionLabel() }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('clients.edit')
                    <a href="{{ route('clients.edit', $client) }}" class="ar-btn ar-btn-secondary">Editar</a>
                @endcan
                @can('clients.regularize')
                    <a href="{{ route('clients.ledger.opening.create', $client) }}" class="ar-btn ar-btn-secondary">Apertura CC</a>
                @endcan
                @can('clients.create')
                    <a href="{{ route('clients.ledger.adjustment.create', $client) }}" class="ar-btn ar-btn-secondary">Ajuste</a>
                @endcan
                @can('charges.create')
                    <a href="{{ route('charges.create', ['client_id' => $client->id]) }}" class="ar-btn ar-btn-secondary">Nuevo cargo</a>
                @endcan
                @can('receipts.create')
                    <a href="{{ route('receipts.create', ['client_id' => $client->id]) }}" class="ar-btn ar-btn-primary">Registrar cobro</a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="mb-4 grid gap-4 sm:grid-cols-2">
        @php
            $ccClass = fn (string $amount) => \App\Support\UiSemantics::kpiClass(
                \App\Support\UiSemantics::clientCcDisplayBalance($amount),
                \App\Support\UiSemantics::MODE_CLIENT_CC
            );
            $displayArs = \App\Support\UiSemantics::clientCcDisplayBalance((string) $balances['ARS']);
            $displayUsd = \App\Support\UiSemantics::clientCcDisplayBalance((string) $balances['USD']);
        @endphp
        <div class="ar-card p-5">
            <h2 class="ar-muted text-sm">Saldo CC ARS (a cobrar)</h2>
            <p class="text-2xl font-bold {{ $ccClass((string) $balances['ARS']) }}">{{ \App\Support\Money::formatAr($displayArs, 'ARS') }}</p>
            <p class="ar-muted mt-1 text-xs">+ rojo = nos deben · − verde = a favor</p>
        </div>
        <div class="ar-card p-5">
            <h2 class="ar-muted text-sm">Saldo CC USD (a cobrar)</h2>
            <p class="text-2xl font-bold {{ $ccClass((string) $balances['USD']) }}">{{ \App\Support\Money::formatAr($displayUsd, 'USD') }}</p>
        </div>
    </div>

    <div class="mb-4 grid gap-4 lg:grid-cols-3">
        <div class="ar-card space-y-1 p-4 text-sm lg:col-span-2">
            <p><span class="ar-muted">Código:</span> {{ $client->codeFormatted() }} · <span class="ar-muted">CUIT:</span> {{ $client->cuit ?: '—' }} · <span class="ar-muted">DNI:</span> {{ $client->dni ?: '—' }}</p>
            <p><span class="ar-muted">Tel:</span> {{ $client->phone ?: '—' }} · <span class="ar-muted">Email:</span> {{ $client->email ?: '—' }}</p>
            <p><span class="ar-muted">Dirección:</span> {{ $client->address ?: '—' }}</p>
            <p><span class="ar-muted">Control CC desde:</span> {{ $client->control_cc_desde?->format('d/m/Y') ?: '—' }}</p>
            @if ($client->notes)
                <p class="ar-muted">{{ $client->notes }}</p>
            @endif
        </div>

        @can('documents.create')
            <form method="POST" action="{{ route('clients.documents.store', $client) }}" enctype="multipart/form-data" class="ar-card space-y-2 p-4">
                @csrf
                <h2 class="font-semibold">Documento archivo</h2>
                <input type="file" name="file" class="ar-input" required>
                <input type="text" name="notes" class="ar-input" placeholder="Notas (opcional)">
                <button class="ar-btn ar-btn-secondary w-full">Adjuntar</button>
            </form>
        @endcan
    </div>

    @if ($openCharges->isNotEmpty())
        <div class="ar-card mb-4 overflow-x-auto">
            <h2 class="border-b px-4 py-3 font-semibold" style="border-color: var(--ar-border);">Cargos abiertos</h2>
            <table class="ar-table">
                <thead><tr><th>Fecha</th><th>Nº</th><th>Concepto</th><th class="text-right">Abierto</th><th>Doc.</th></tr></thead>
                <tbody>
                    @foreach ($openCharges as $ch)
                        <tr>
                            <td>{{ $ch->charged_on?->format('d/m/Y') }}</td>
                            <td><a href="{{ route('charges.show', $ch) }}" style="color: var(--ar-brand);">{{ $ch->number }}</a></td>
                            <td>{{ $ch->concept }}</td>
                            <td class="text-right">{{ number_format((float) $ch->amount_open, 2, ',', '.') }} {{ $ch->currency_code }}</td>
                            <td>{{ $ch->documental_status->label() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @can('clients.regularize')
        <form method="POST" action="{{ route('clients.regularize', $client) }}" class="ar-card mb-4 grid gap-2 p-4 sm:grid-cols-6">
            @csrf
            <h2 class="sm:col-span-6 font-semibold">Regularizar CC (solo autorizados · movimiento auditado)</h2>
            <div>
                <label class="ar-label">Moneda</label>
                <select name="currency_code" class="ar-input"><option value="ARS">ARS</option><option value="USD">USD</option></select>
            </div>
            <div>
                <label class="ar-label">Importe</label>
                <input type="number" step="0.01" min="0.01" name="amount" class="ar-input" required>
            </div>
            <div>
                <label class="ar-label">Efecto</label>
                <select name="sign" class="ar-input">
                    <option value="-1">Aumentar deuda (IN)</option>
                    <option value="1">A favor cliente (OUT)</option>
                </select>
            </div>
            <div>
                <label class="ar-label">Tipo</label>
                <select name="regularization_kind" class="ar-input">
                    <option value="omitted_charge">Cargo omitido</option>
                    <option value="omitted_payment">Cobro omitido</option>
                    <option value="opening_balance">Saldo apertura</option>
                    <option value="misapplied_payment">Pago mal aplicado</option>
                    <option value="historical_correction">Corrección histórica</option>
                    <option value="reclassification">Reclasificación</option>
                    <option value="other">Otro</option>
                </select>
            </div>
            <div>
                <label class="ar-label">Fecha</label>
                <input type="date" name="entry_date" class="ar-input" value="{{ now()->toDateString() }}" required>
            </div>
            <div class="sm:col-span-6">
                <label class="ar-label">Motivo obligatorio</label>
                <input name="reason" class="ar-input" required>
            </div>
            <div class="sm:col-span-6"><button class="ar-btn ar-btn-secondary">Registrar regularización</button></div>
        </form>
    @endcan

    <div class="ar-card overflow-x-auto">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3" style="border-color: var(--ar-border);">
            <h2 class="font-semibold">Detalle cronológico de cuenta corriente</h2>
            <p class="ar-muted text-sm">{{ $timelineTotal }} movimientos</p>
        </div>
        <div class="flex flex-wrap gap-2 border-b px-4 py-2" style="border-color: var(--ar-border);">
            @foreach ($ccFilters as $key => $label)
                <a href="{{ route('clients.show', ['client' => $client, 'cc_filter' => $key]) }}"
                   class="ar-btn {{ $ccFilter === $key ? 'ar-btn-primary' : 'ar-btn-secondary' }}"
                   style="padding: .25rem .75rem; font-size: .85rem;">{{ $label }}</a>
            @endforeach
        </div>
        <table class="ar-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Tipo</th>
                    <th>Origen</th>
                    <th>Descripción</th>
                    <th>Moneda</th>
                    <th class="text-right">Importe</th>
                    <th class="text-right">Saldo acum. (a cobrar)</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($timeline as $row)
                    @php
                        $tone = $row['running_display'] !== null
                            ? \App\Support\UiSemantics::kpiClass($row['running_display'], \App\Support\UiSemantics::MODE_CLIENT_CC)
                            : 'ar-muted';
                    @endphp
                    <tr>
                        <td>{{ $row['date']?->format('d/m/Y') }}</td>
                        <td>{{ $row['type_label'] }}</td>
                        <td>{{ $row['origin_label'] }}</td>
                        <td>
                            {{ $row['description'] }}
                            @if (! ($row['affects_cc'] ?? true))
                                <span class="ar-muted text-xs block">Sin efecto CC adicional (ya contabilizado o cobro histórico relacionado)</span>
                            @endif
                        </td>
                        <td>{{ $row['currency'] }}</td>
                        <td class="text-right">{{ number_format((float) $row['amount'], 2, ',', '.') }}</td>
                        <td class="text-right {{ $tone }}">
                            @if ($row['running_display'] !== null)
                                {{ number_format((float) $row['running_display'], 2, ',', '.') }}
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $row['status'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="ar-muted py-6 text-center">Sin movimientos.</td></tr>
                @endforelse
            </tbody>
        </table>
        @if ($timeline->hasPages())
            <div class="border-t px-4 py-3" style="border-color: var(--ar-border);">
                {{ $timeline->links() }}
            </div>
        @endif
    </div>
</x-app-layout>
