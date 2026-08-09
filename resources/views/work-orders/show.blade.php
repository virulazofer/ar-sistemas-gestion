<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">{{ $workOrder->number }}</h1>
                <p class="ar-muted text-sm">{{ $workOrder->title }} · {{ $workOrder->client->name }} · {{ $workOrder->status->label() }}</p>
            </div>
            <a href="{{ route('work-orders.index') }}" class="ar-btn ar-btn-secondary">Listado</a>
        </div>
    </x-slot>

    <div class="mb-4 grid gap-4 sm:grid-cols-4">
        <div class="ar-card p-4"><p class="ar-muted text-sm">Costo USD</p><p class="text-xl font-bold">{{ number_format((float) $workOrder->total_cost_usd, 2, ',', '.') }}</p></div>
        <div class="ar-card p-4"><p class="ar-muted text-sm">Precio USD</p><p class="text-xl font-bold">{{ number_format((float) $workOrder->total_price_usd, 2, ',', '.') }}</p></div>
        <div class="ar-card p-4"><p class="ar-muted text-sm">Margen USD</p><p class="text-xl font-bold">{{ number_format((float) $workOrder->total_price_usd - (float) $workOrder->total_cost_usd, 2, ',', '.') }}</p></div>
        <div class="ar-card p-4 text-sm">
            <p><span class="ar-muted">Tipo:</span> {{ $workOrder->type->name }}</p>
            <p><span class="ar-muted">Técnico:</span> {{ $workOrder->assignee?->name ?: '—' }}</p>
            @if ($workOrder->ledgerEntry)
                <p><span class="ar-muted">Cargo CC:</span> #{{ $workOrder->client_ledger_entry_id }}</p>
            @endif
        </div>
    </div>

    @if ($workOrder->assets->isNotEmpty())
        <div class="ar-card mb-4 p-4 text-sm">
            <h2 class="mb-2 font-semibold">Equipos asociados</h2>
            @foreach ($workOrder->assets as $asset)
                @if ($asset->equipment)
                    <p>{{ $asset->equipment->code }} — {{ $asset->equipment->name }} ({{ $asset->equipment->status->label() }})</p>
                @else
                    <p>{{ $asset->external_label ?: 'Externo' }} · {{ $asset->external_manufacturer }} {{ $asset->external_model }} · S/N {{ $asset->external_serial ?: '—' }}</p>
                @endif
            @endforeach
        </div>
    @endif

    @if ($workOrder->isEditable())
        <div class="mb-4 grid gap-4 lg:grid-cols-3">
            @can('work_orders.edit')
                <form method="POST" action="{{ route('work-orders.diagnosis.store', $workOrder) }}" class="ar-card space-y-2 p-4">
                    @csrf
                    <h2 class="font-semibold">Diagnóstico</h2>
                    <textarea name="client_reported_issue" class="ar-input" placeholder="Problema informado" rows="2"></textarea>
                    <textarea name="technical_diagnosis" class="ar-input" placeholder="Diagnóstico técnico" rows="2" required></textarea>
                    <button class="ar-btn ar-btn-secondary w-full">Registrar</button>
                </form>
                <form method="POST" action="{{ route('work-orders.tasks.store', $workOrder) }}" class="ar-card space-y-2 p-4">
                    @csrf
                    <h2 class="font-semibold">Tarea / mano de obra</h2>
                    <input name="description" class="ar-input" placeholder="Descripción" required>
                    <input type="number" step="0.01" name="hours" class="ar-input" placeholder="Horas">
                    <input type="number" step="0.01" name="cost_amount" class="ar-input" placeholder="Costo" value="0">
                    <input type="number" step="0.01" name="price_amount" class="ar-input" placeholder="Precio" required>
                    <select name="currency_code" class="ar-input"><option value="USD">USD</option><option value="ARS">ARS</option></select>
                    <button class="ar-btn ar-btn-secondary w-full">Agregar</button>
                </form>
            @endcan
            @can('work_orders.consume_stock')
                <form method="POST" action="{{ route('work-orders.materials.store', $workOrder) }}" class="ar-card space-y-2 p-4">
                    @csrf
                    <h2 class="font-semibold">Material</h2>
                    <select name="product_id" class="ar-input" required>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}">{{ $product->sku }} — {{ $product->name }}</option>
                        @endforeach
                    </select>
                    <input type="number" step="0.0001" name="quantity" class="ar-input" value="1" required>
                    <input type="number" step="0.01" name="price_unit" class="ar-input" placeholder="Precio unitario" required>
                    <select name="currency_code" class="ar-input"><option value="USD">USD</option><option value="ARS">ARS</option></select>
                    <button class="ar-btn ar-btn-secondary w-full">Agregar</button>
                </form>
            @endcan
        </div>
    @endif

    <div class="mb-4 grid gap-4 lg:grid-cols-2">
        <div class="ar-card overflow-x-auto">
            <h2 class="border-b px-4 py-3 font-semibold" style="border-color: var(--ar-border);">Diagnósticos</h2>
            <ul class="space-y-2 p-4 text-sm">
                @forelse ($workOrder->diagnoses as $d)
                    <li><span class="ar-muted">{{ $d->diagnosed_at?->format('d/m/Y H:i') }} · {{ $d->user->name }}</span><br>{{ $d->technical_diagnosis }}</li>
                @empty
                    <li class="ar-muted">Sin diagnósticos.</li>
                @endforelse
            </ul>
        </div>
        <div class="ar-card overflow-x-auto">
            <h2 class="border-b px-4 py-3 font-semibold" style="border-color: var(--ar-border);">Tareas</h2>
            <table class="ar-table">
                <thead><tr><th>Desc.</th><th class="text-right">Precio</th><th class="text-right">Costo</th></tr></thead>
                <tbody>
                    @foreach ($workOrder->tasks as $task)
                        <tr>
                            <td>{{ $task->description }}</td>
                            <td class="text-right">{{ $task->currency_code }} {{ number_format((float) $task->price_amount, 2, ',', '.') }}</td>
                            <td class="text-right">{{ number_format((float) $task->cost_amount, 2, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="ar-card mb-4 overflow-x-auto">
        <h2 class="border-b px-4 py-3 font-semibold" style="border-color: var(--ar-border);">Materiales</h2>
        <table class="ar-table">
            <thead><tr><th>Producto</th><th class="text-right">Cant.</th><th class="text-right">Precio</th><th class="text-right">Costo FIFO</th><th>Estado</th><th>Lote</th></tr></thead>
            <tbody>
                @foreach ($workOrder->materials as $m)
                    <tr>
                        <td>{{ $m->product->sku }}</td>
                        <td class="text-right">{{ number_format((float) $m->quantity, 4, ',', '.') }}</td>
                        <td class="text-right">USD {{ number_format((float) $m->price_usd, 2, ',', '.') }}</td>
                        <td class="text-right">USD {{ number_format((float) $m->cost_usd, 2, ',', '.') }}</td>
                        <td>{{ $m->status }}</td>
                        <td>{{ $m->inventory_lot_id ? '#'.$m->inventory_lot_id : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($workOrder->isEditable())
        <div class="flex flex-wrap gap-3">
            @can('work_orders.close')
                <form method="POST" action="{{ route('work-orders.close', $workOrder) }}" class="ar-card flex flex-wrap items-end gap-2 p-4">
                    @csrf
                    <div><label class="ar-label">Solución</label><input name="solution" class="ar-input" value="{{ $workOrder->solution }}"></div>
                    <button class="ar-btn ar-btn-primary">Cerrar OT (consumo + cargo CC)</button>
                </form>
            @endcan
            @can('work_orders.cancel')
                <form method="POST" action="{{ route('work-orders.cancel', $workOrder) }}" class="ar-card flex flex-wrap items-end gap-2 p-4">
                    @csrf
                    <input name="reason" class="ar-input" placeholder="Motivo cancelación" required>
                    <button class="ar-btn ar-btn-secondary">Cancelar OT</button>
                </form>
            @endcan
        </div>
    @endif
</x-app-layout>
