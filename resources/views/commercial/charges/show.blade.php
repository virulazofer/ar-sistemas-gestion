<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">{{ $charge->number }}</h1>
                <p class="ar-muted text-sm">{{ $charge->client?->labelWithCode() }} · {{ $charge->charge_type->label() }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('receipts.create')
                    @if ($charge->isOpen())
                        <a href="{{ route('receipts.create', ['client_id' => $charge->client_id]) }}" class="ar-btn ar-btn-primary">Registrar cobro</a>
                    @endif
                @endcan
                <a href="{{ route('clients.show', $charge->client_id) }}" class="ar-btn ar-btn-secondary">Cuenta corriente</a>
            </div>
        </div>
    </x-slot>

    <div class="mb-4 grid gap-4 sm:grid-cols-4">
        <div class="ar-card p-4"><p class="ar-muted text-xs">Importe</p><p class="text-lg font-semibold">{{ number_format((float) $charge->amount, 2, ',', '.') }} {{ $charge->currency_code }}</p></div>
        <div class="ar-card p-4"><p class="ar-muted text-xs">Aplicado</p><p class="text-lg font-semibold">{{ number_format((float) $charge->amount_applied, 2, ',', '.') }}</p></div>
        <div class="ar-card p-4"><p class="ar-muted text-xs">Abierto</p><p class="text-lg font-semibold">{{ number_format((float) $charge->amount_open, 2, ',', '.') }}</p></div>
        <div class="ar-card p-4"><p class="ar-muted text-xs">Estado</p><p class="text-lg font-semibold">{{ $charge->status->label() }}</p></div>
    </div>

    <div class="mb-4 ar-card space-y-1 p-4 text-sm">
        <p><span class="ar-muted">Concepto:</span> {{ $charge->concept }}</p>
        <p><span class="ar-muted">Fecha:</span> {{ $charge->charged_on?->format('d/m/Y') }} · <span class="ar-muted">Origen:</span> {{ $charge->originLabel() }}</p>
        <p><span class="ar-muted">Documental:</span> {{ $charge->documental_status->label() }}</p>
        @if ($charge->client_ledger_entry_id)
            <p><span class="ar-muted">Movimiento CC:</span> #{{ $charge->client_ledger_entry_id }}</p>
        @endif
        @if ($charge->notes)<p class="ar-muted">{{ $charge->notes }}</p>@endif
    </div>

    <div class="mb-4 ar-card overflow-x-auto">
        <h2 class="border-b px-4 py-3 font-semibold" style="border-color: var(--ar-border);">Aplicaciones de cobro</h2>
        <table class="ar-table">
            <thead><tr><th>Cobro</th><th>Fecha</th><th class="text-right">Importe</th><th>Estado</th></tr></thead>
            <tbody>
                @forelse ($charge->applications as $app)
                    <tr>
                        <td><a href="{{ route('receipts.show', $app->receipt_id) }}" style="color: var(--ar-brand);">{{ $app->receipt?->number }}</a></td>
                        <td>{{ $app->receipt?->received_on?->format('d/m/Y') }}</td>
                        <td class="text-right">{{ number_format((float) $app->amount, 2, ',', '.') }}</td>
                        <td>{{ $app->status->label() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="ar-muted py-4 text-center">Sin aplicaciones.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @can('documents.create')
        <form method="POST" action="{{ route('charges.vouchers.store', $charge) }}" class="ar-card mb-4 grid gap-3 p-4 sm:grid-cols-3">
            @csrf
            <h2 class="sm:col-span-3 font-semibold">Asociar comprobante</h2>
            <div>
                <label class="ar-label">Tipo</label>
                <select name="voucher_type" class="ar-input" required>
                    @foreach (\App\Enums\CommercialVoucherType::cases() as $vt)
                        <option value="{{ $vt->value }}">{{ $vt->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div><label class="ar-label">Punto de venta</label><input name="point_of_sale" class="ar-input"></div>
            <div><label class="ar-label">Número</label><input name="number" class="ar-input"></div>
            <div><label class="ar-label">Fecha</label><input type="date" name="issued_on" class="ar-input"></div>
            <div><label class="ar-label">Importe</label><input type="number" step="0.01" name="amount" class="ar-input"></div>
            <div class="flex items-end"><button class="ar-btn ar-btn-secondary w-full">Asociar</button></div>
        </form>
    @endcan

    @if ($charge->vouchers->isNotEmpty())
        <div class="ar-card mb-4 p-4 text-sm">
            <h2 class="mb-2 font-semibold">Comprobantes</h2>
            <ul class="list-disc ps-5">
                @foreach ($charge->vouchers as $v)
                    <li>{{ $v->voucher_type->label() }} {{ $v->point_of_sale }}-{{ $v->number }} · {{ $v->issued_on?->format('d/m/Y') }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @can('charges.void')
        @if ($charge->status->value !== 'voided')
            <form method="POST" action="{{ route('charges.void', $charge) }}" class="ar-card flex flex-wrap items-end gap-2 p-4">
                @csrf
                <div class="grow">
                    <label class="ar-label">Anular cargo (reversión, no borrado)</label>
                    <input name="void_reason" class="ar-input" required placeholder="Motivo">
                </div>
                <button class="ar-btn ar-btn-secondary">Anular</button>
            </form>
        @endif
    @endcan
</x-app-layout>
