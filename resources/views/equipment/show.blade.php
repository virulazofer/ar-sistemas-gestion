<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">{{ $equipment->code }}</h1>
                <p class="ar-muted text-sm">{{ $equipment->name }} · {{ $equipment->type->name }} · {{ $equipment->status->label() }}</p>
            </div>
            <a href="{{ route('equipment.index') }}" class="ar-btn ar-btn-secondary">Listado</a>
        </div>
    </x-slot>

    <div class="mb-4 grid gap-4 sm:grid-cols-3">
        <div class="ar-card p-4">
            <p class="ar-muted text-sm">Costo USD (FIFO)</p>
            <p class="text-2xl font-bold">{{ number_format((float) $equipment->total_cost_usd, 2, ',', '.') }}</p>
            <p class="ar-muted text-xs">ARS {{ number_format((float) $equipment->total_cost_ars, 2, ',', '.') }}</p>
        </div>
        <div class="ar-card space-y-1 p-4 text-sm">
            <p><span class="ar-muted">Armado:</span> {{ $equipment->assembled_at?->format('d/m/Y H:i') }}</p>
            <p><span class="ar-muted">Ubicación:</span> {{ $equipment->location?->name ?: '—' }}</p>
            <p><span class="ar-muted">Usuario:</span> {{ $equipment->user?->name }}</p>
            @if ($equipment->notes)<p class="ar-muted">{{ $equipment->notes }}</p>@endif
        </div>
        <div class="space-y-3">
            @can('equipment.change_status')
                @if ($equipment->status->value !== 'disassembled')
                    <form method="POST" action="{{ route('equipment.status', $equipment) }}" class="ar-card space-y-2 p-4">
                        @csrf
                        <h2 class="font-semibold text-sm">Cambiar estado</h2>
                        <select name="status" class="ar-input">
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected($equipment->status === $status)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                        <input name="reason" class="ar-input" placeholder="Motivo (opcional)">
                        <button class="ar-btn ar-btn-secondary w-full">Actualizar</button>
                    </form>
                @endif
            @endcan
            @can('equipment.disassemble')
                @if ($equipment->status->allowsDisassembly())
                    <form method="POST" action="{{ route('equipment.disassemble', $equipment) }}" class="ar-card space-y-2 p-4">
                        @csrf
                        <h2 class="font-semibold text-sm">Desarmar</h2>
                        <input name="reason" class="ar-input" placeholder="Motivo" required>
                        <button class="ar-btn ar-btn-secondary w-full">Desarmar y recuperar stock</button>
                    </form>
                @endif
            @endcan
        </div>
    </div>

    <div class="ar-card mb-4 overflow-x-auto">
        <h2 class="border-b px-4 py-3 font-semibold" style="border-color: var(--ar-border);">Componentes</h2>
        <table class="ar-table">
            <thead>
                <tr>
                    <th>Cat.</th><th>Producto</th><th>Serial</th><th>Lote</th>
                    <th class="text-right">Cant.</th><th class="text-right">Costo u.</th><th class="text-right">Total USD</th><th>Estado</th><th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($equipment->components as $component)
                    <tr>
                        <td>{{ $component->category?->name ?: '—' }}</td>
                        <td>{{ $component->product->sku }} — {{ $component->product->name }}</td>
                        <td>{{ $component->serial?->serial_number ?: '—' }}</td>
                        <td>#{{ $component->inventory_lot_id }}</td>
                        <td class="text-right">{{ number_format((float) $component->quantity, 4, ',', '.') }}</td>
                        <td class="text-right">{{ $component->currency?->code }} {{ number_format((float) $component->unit_cost, 6, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float) $component->total_cost_usd, 2, ',', '.') }}</td>
                        <td>{{ $component->status->label() }}</td>
                        <td>
                            @can('equipment.change_component')
                                @if ($component->status->value === 'installed' && $equipment->status->value !== 'disassembled')
                                    <form method="POST" action="{{ route('equipment.component.replace', [$equipment, $component]) }}" class="flex flex-col gap-1">
                                        @csrf
                                        <select name="product_id" class="ar-input text-xs" required>
                                            @foreach ($products as $product)
                                                <option value="{{ $product->id }}">{{ $product->sku }}</option>
                                            @endforeach
                                        </select>
                                        <input name="serial_number" class="ar-input text-xs" placeholder="Serial si aplica">
                                        <input name="reason" class="ar-input text-xs" placeholder="Motivo" required>
                                        <button class="ar-btn ar-btn-secondary text-xs">Reemplazar</button>
                                    </form>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="ar-card overflow-x-auto">
        <h2 class="border-b px-4 py-3 font-semibold" style="border-color: var(--ar-border);">Historial de estados</h2>
        <table class="ar-table">
            <thead><tr><th>Fecha</th><th>De</th><th>A</th><th>Usuario</th><th>Motivo</th></tr></thead>
            <tbody>
                @forelse ($equipment->statusLogs as $log)
                    <tr>
                        <td>{{ $log->created_at?->format('d/m/Y H:i') }}</td>
                        <td>{{ $log->from_status ?: '—' }}</td>
                        <td>{{ $log->to_status }}</td>
                        <td>{{ $log->user?->name }}</td>
                        <td>{{ $log->reason ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="ar-muted py-4 text-center">Sin historial.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
