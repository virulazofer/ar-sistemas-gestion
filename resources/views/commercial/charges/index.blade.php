<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Cargos al cliente</h1>
                <p class="ar-muted text-sm">Operaciones comerciales · CC IN a crédito · sin ingreso hasta cobro.</p>
            </div>
            @can('charges.create')
                <a href="{{ route('charges.create') }}" class="ar-btn ar-btn-primary">Nuevo cargo</a>
            @endcan
        </div>
    </x-slot>

    <form method="GET" class="mb-4 flex flex-wrap gap-2">
        <input type="search" name="q" value="{{ $q }}" class="ar-input" placeholder="Código, cliente, concepto…">
        <select name="status" class="ar-input">
            <option value="">Todos los estados</option>
            @foreach (['pending' => 'Pendiente', 'partial' => 'Parcial', 'collected' => 'Cobrado', 'voided' => 'Anulado'] as $k => $label)
                <option value="{{ $k }}" @selected($status === $k)>{{ $label }}</option>
            @endforeach
        </select>
        <button class="ar-btn ar-btn-secondary">Filtrar</button>
    </form>

    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead>
                <tr>
                    <th>Nº</th>
                    <th>Fecha</th>
                    <th>Cliente</th>
                    <th>Tipo</th>
                    <th>Concepto</th>
                    <th class="text-right">Abierto</th>
                    <th>Estado</th>
                    <th>Doc.</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($charges as $charge)
                    <tr>
                        <td><a href="{{ route('charges.show', $charge) }}" style="color: var(--ar-brand);">{{ $charge->number }}</a></td>
                        <td>{{ $charge->charged_on?->format('d/m/Y') }}</td>
                        <td>{{ $charge->client?->labelWithCode() }}</td>
                        <td>{{ $charge->charge_type->label() }}</td>
                        <td>{{ $charge->concept }}</td>
                        <td class="text-right">{{ number_format((float) $charge->amount_open, 2, ',', '.') }} {{ $charge->currency_code }}</td>
                        <td>{{ $charge->status->label() }}</td>
                        <td>{{ $charge->documental_status->label() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="ar-muted py-6 text-center">Sin cargos.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $charges->links() }}</div>
</x-app-layout>
