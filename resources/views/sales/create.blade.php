<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Nueva venta</h1></x-slot>
    <form method="POST" action="{{ route('sales.store') }}" class="ar-card mx-auto max-w-3xl space-y-4 p-6" x-data="{ rows: [{item_type:'product', description:'', product_id:'', equipment_id:'', quantity:1, unit_price:0}] }">
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
                <select name="currency_code" class="ar-input"><option value="USD">USD</option><option value="ARS">ARS</option></select>
            </div>
        </div>
        <div class="space-y-3">
            <h2 class="font-semibold">Ítems</h2>
            <template x-for="(row, i) in rows" :key="i">
                <div class="grid gap-2 rounded border p-3 sm:grid-cols-6" style="border-color: var(--ar-border);">
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
                    <select :name="`items[${i}][equipment_id]`" class="ar-input" x-show="row.item_type === 'equipment'">
                        <option value="">Equipo</option>
                        @foreach ($equipments as $eq)
                            <option value="{{ $eq->id }}">{{ $eq->code }}</option>
                        @endforeach
                    </select>
                    <input type="number" step="0.0001" :name="`items[${i}][quantity]`" class="ar-input" x-model="row.quantity">
                    <input type="number" step="0.01" :name="`items[${i}][unit_price]`" class="ar-input" x-model="row.unit_price">
                </div>
            </template>
            <button type="button" class="ar-btn ar-btn-secondary" @click="rows.push({item_type:'product', description:'', product_id:'', equipment_id:'', quantity:1, unit_price:0})">+ Línea</button>
        </div>
        <div class="flex justify-end gap-2">
            <a href="{{ route('sales.index') }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button class="ar-btn ar-btn-primary">Crear borrador</button>
        </div>
    </form>
</x-app-layout>
