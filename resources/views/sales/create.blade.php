<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-2">
            <h1 class="text-xl font-semibold">Nueva venta</h1>
            <x-page-help topic="equipment_sale" />
        </div>
    </x-slot>
    @php
        $eqPayload = $equipments->map(fn ($eq) => [
            'id' => $eq->id,
            'code' => $eq->code,
            'cost_usd' => (float) $eq->total_cost_usd,
            'cost_ars' => (float) $eq->total_cost_ars,
            'status' => $eq->status?->value ?? (string) $eq->status,
        ])->values();
    @endphp
    <form method="POST" action="{{ route('sales.store') }}" class="ar-card mx-auto max-w-4xl space-y-4 p-6"
        x-data="{
            currency: 'USD',
            rows: [{item_type:'product', description:'', product_id:'', equipment_id:'', quantity:1, unit_price:0, margin_pct:30}],
            equipments: {{ Js::from($eqPayload) }},
            eqCost(row) {
                const eq = this.equipments.find(e => String(e.id) === String(row.equipment_id));
                if (!eq) return 0;
                return this.currency === 'ARS' ? eq.cost_ars : eq.cost_usd;
            },
            applyMargin(row) {
                if (row.item_type !== 'equipment') return;
                const cost = this.eqCost(row);
                const m = Number(row.margin_pct || 0);
                row.unit_price = Math.round(cost * (1 + m / 100) * 100) / 100;
                const eq = this.equipments.find(e => String(e.id) === String(row.equipment_id));
                if (eq && !row.description) row.description = 'Equipo ' + eq.code;
            }
        }">
        @csrf
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="ar-label">Cliente</label>
                <select name="client_id" class="ar-input" required>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}">{{ $client->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ar-label">Moneda</label>
                <select name="currency_code" class="ar-input" x-model="currency" @change="rows.forEach(r => applyMargin(r))">
                    <option value="USD">USD</option>
                    <option value="ARS">ARS</option>
                </select>
            </div>
        </div>
        <div class="space-y-3">
            <h2 class="font-semibold">Ítems</h2>
            <p class="ar-muted text-xs">Para equipos: margen % sobre costo histórico (no re-consume componentes). Ver docs/venta-de-equipos.md.</p>
            <template x-for="(row, i) in rows" :key="i">
                <div class="grid gap-2 rounded border p-3 sm:grid-cols-8" style="border-color: var(--ar-border);">
                    <select :name="`items[${i}][item_type]`" class="ar-input" x-model="row.item_type">
                        @foreach ($itemTypes as $t)
                            @if (! in_array($t->value, ['build_to_order', 'subscription']))
                                <option value="{{ $t->value }}">{{ $t->label() }}</option>
                            @endif
                        @endforeach
                    </select>
                    <input :name="`items[${i}][description]`" class="ar-input sm:col-span-2" placeholder="Descripción" x-model="row.description" required>
                    <select :name="`items[${i}][product_id]`" class="ar-input" x-show="row.item_type === 'product'">
                        <option value="">Producto</option>
                        @foreach ($products as $p)
                            <option value="{{ $p->id }}">{{ $p->sku }}</option>
                        @endforeach
                    </select>
                    <select :name="`items[${i}][equipment_id]`" class="ar-input" x-show="row.item_type === 'equipment'" x-model="row.equipment_id" @change="applyMargin(row)">
                        <option value="">Equipo</option>
                        @foreach ($equipments as $eq)
                            <option value="{{ $eq->id }}">{{ $eq->code }}</option>
                        @endforeach
                    </select>
                    <input type="number" step="0.01" class="ar-input" x-show="row.item_type === 'equipment'" x-model="row.margin_pct" @change="applyMargin(row)" placeholder="Margen %">
                    <input type="number" step="0.0001" :name="`items[${i}][quantity]`" class="ar-input" x-model="row.quantity">
                    <input type="number" step="0.01" :name="`items[${i}][unit_price]`" class="ar-input" x-model="row.unit_price" placeholder="Precio">
                    <p class="ar-muted text-xs sm:col-span-8" x-show="row.item_type === 'equipment' && row.equipment_id">
                        Costo: <span x-text="eqCost(row).toFixed(2)"></span> · Precio con margen ≈ costo × (1 + margen/100)
                    </p>
                </div>
            </template>
            <button type="button" class="ar-btn ar-btn-secondary" @click="rows.push({item_type:'product', description:'', product_id:'', equipment_id:'', quantity:1, unit_price:0, margin_pct:30})">+ Línea</button>
        </div>
        <div class="flex justify-end gap-2">
            <a href="{{ route('sales.index') }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button class="ar-btn ar-btn-primary">Crear borrador</button>
        </div>
    </form>
</x-app-layout>
