<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-semibold">Operaciones sin comprobante asociado</h1>
            <p class="ar-muted text-sm">Control documental. Asociar comprobante no duplica el cargo.</p>
        </div>
    </x-slot>

    <form method="GET" class="ar-card mb-4 grid gap-3 p-4 sm:grid-cols-5">
        <div>
            <label class="ar-label">Cliente</label>
            <select name="client_id" class="ar-input">
                <option value="0">Todos</option>
                @foreach ($clients as $c)
                    <option value="{{ $c->id }}" @selected($clientId === $c->id)>{{ $c->labelWithCode() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="ar-label">Desde</label>
            <input type="date" name="from" class="ar-input" value="{{ $from }}">
        </div>
        <div>
            <label class="ar-label">Hasta</label>
            <input type="date" name="to" class="ar-input" value="{{ $to }}">
        </div>
        <div>
            <label class="ar-label">Tipo operación</label>
            <select name="op_type" class="ar-input">
                <option value="charges" @selected($opType === 'charges')>Cargos</option>
                <option value="sales" @selected($opType === 'sales')>Ventas</option>
                <option value="receipts" @selected($opType === 'receipts')>Cobros</option>
            </select>
        </div>
        <div>
            <label class="ar-label">Estado documental</label>
            <select name="documental_status" class="ar-input">
                <option value="">Atención (sin/pendiente/revisar)</option>
                @foreach ($documentalStatuses as $ds)
                    <option value="{{ $ds->value }}" @selected($docStatus === $ds->value)>{{ $ds->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-5"><button class="ar-btn ar-btn-secondary">Consultar</button></div>
    </form>

    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Cliente</th>
                    <th>Referencia</th>
                    <th class="text-right">Importe</th>
                    <th>Documental</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>
                            {{ $row->charged_on?->format('d/m/Y')
                                ?? $row->sold_on?->format('d/m/Y')
                                ?? $row->received_on?->format('d/m/Y') }}
                        </td>
                        <td>{{ $row->client?->labelWithCode() }}</td>
                        <td>{{ $row->number ?? ('#'.$row->id) }} · {{ $row->concept ?? $row->notes ?? '—' }}</td>
                        <td class="text-right">{{ number_format((float) ($row->amount ?? $row->total ?? 0), 2, ',', '.') }} {{ $row->currency_code ?? '' }}</td>
                        <td>{{ $row->documental_status?->label() ?? $row->documental_status }}</td>
                        <td class="text-right">
                            @if ($opType === 'charges')
                                <a href="{{ route('charges.show', $row) }}" style="color: var(--ar-brand);">Ver</a>
                            @elseif ($opType === 'sales')
                                <a href="{{ route('sales.show', $row) }}" style="color: var(--ar-brand);">Ver</a>
                            @else
                                <a href="{{ route('receipts.show', $row) }}" style="color: var(--ar-brand);">Ver</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="ar-muted py-6 text-center">Sin resultados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
