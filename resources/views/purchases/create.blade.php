<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">Nueva compra</h1>
            <a href="{{ route('purchases.index') }}" class="ar-btn ar-btn-secondary">Cancelar</a>
        </div>
    </x-slot>

    <form method="POST" action="{{ route('purchases.store') }}" class="ar-card mx-auto max-w-4xl space-y-4 p-6"
          x-data="{ mode: '{{ old('payment_mode', 'cash') }}', lines: {{ old('items') ? count(old('items')) : 1 }} }">
        @csrf

        @if ($errors->any())
            <div class="rounded-lg p-3 text-sm" style="background: var(--ar-danger-soft, #fee); color: var(--ar-danger, #b91c1c);">
                {{ $errors->first() }}
            </div>
        @endif

        <p class="ar-muted text-sm">
            Contado personal/ocasional: proveedor opcional (no inventar proveedores). Crédito: proveedor obligatorio.
            Alternativa diaria: <a href="{{ route('movements.quick') }}" class="underline">Carga rápida</a>.
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="ar-label">Proveedor</label>
                <select name="supplier_id" class="ar-input" :required="mode === 'credit'">
                    <option value="">— Sin proveedor / ocasional —</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected((int) old('supplier_id', $preselectedSupplier) === $supplier->id)>{{ $supplier->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ar-label">Contraparte (opcional, sin proveedor)</label>
                <input type="text" name="counterparty_name" class="ar-input" value="{{ old('counterparty_name') }}" placeholder="Ej. Super, kiosco, restaurant">
            </div>
            <div>
                <label class="ar-label">Fecha</label>
                <input type="date" name="purchase_date" class="ar-input" value="{{ old('purchase_date', now()->toDateString()) }}" required>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="ar-label">Tipo comprobante</label>
                <select name="voucher_type" class="ar-input">
                    @foreach (['factura' => 'Factura', 'remito' => 'Remito', 'ticket' => 'Ticket', 'presupuesto' => 'Presupuesto', 'otro' => 'Otro'] as $val => $label)
                        <option value="{{ $val }}" @selected(old('voucher_type') === $val)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ar-label">Letra</label>
                <input type="text" name="voucher_letter" class="ar-input" maxlength="4" value="{{ old('voucher_letter') }}" placeholder="A / B / C">
            </div>
            <div>
                <label class="ar-label">Número</label>
                <input type="text" name="voucher_number" class="ar-input" value="{{ old('voucher_number') }}">
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="ar-label">Moneda</label>
                <select name="currency_code" class="ar-input" required>
                    <option value="ARS" @selected(old('currency_code') === 'ARS')>ARS</option>
                    <option value="USD" @selected(old('currency_code', 'USD') === 'USD')>USD</option>
                </select>
            </div>
            <div>
                <label class="ar-label">Modo de pago</label>
                <select name="payment_mode" class="ar-input" x-model="mode" required>
                    <option value="cash">Contado</option>
                    <option value="credit">Crédito (CC proveedor)</option>
                </select>
            </div>
            <div x-show="mode === 'cash'">
                <label class="ar-label">Cuenta de pago</label>
                <select name="financial_account_id" class="ar-input" :required="mode === 'cash'">
                    <option value="">—</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}" @selected((int) old('financial_account_id') === $account->id)>
                            {{ $account->name }} ({{ $account->currency->code }})
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="ar-label">IVA / impuestos (cabecera)</label>
                <input type="number" step="0.01" min="0" name="tax_amount" class="ar-input" value="{{ old('tax_amount', '0') }}">
            </div>
            <div>
                <label class="ar-label">Otros impuestos</label>
                <input type="number" step="0.01" min="0" name="other_taxes" class="ar-input" value="{{ old('other_taxes', '0') }}">
            </div>
            <div>
                <label class="ar-label">Descuento cabecera</label>
                <input type="number" step="0.01" min="0" name="discount_amount" class="ar-input" value="{{ old('discount_amount', '0') }}">
            </div>
        </div>

        <div>
            <div class="mb-2 flex items-center justify-between">
                <h2 class="font-semibold">Líneas</h2>
                <button type="button" class="ar-btn ar-btn-secondary text-sm" @click="lines++">+ Línea</button>
            </div>
            <template x-for="i in lines" :key="i">
                <div class="mb-3 grid gap-2 rounded-lg border p-3 sm:grid-cols-6" style="border-color: var(--ar-border);">
                    <div class="sm:col-span-2">
                        <label class="ar-label">Producto (stock)</label>
                        <select :name="'items['+(i-1)+'][product_id]'" class="ar-input">
                            <option value="">— Sin stock —</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}">{{ $product->sku }} — {{ $product->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="ar-label">Descripción</label>
                        <input type="text" :name="'items['+(i-1)+'][description]'" class="ar-input" required>
                    </div>
                    <div>
                        <label class="ar-label">Cant.</label>
                        <input type="number" step="0.0001" min="0.0001" :name="'items['+(i-1)+'][quantity]'" class="ar-input" value="1" required>
                    </div>
                    <div>
                        <label class="ar-label">P. unit.</label>
                        <input type="number" step="0.000001" min="0.000001" :name="'items['+(i-1)+'][unit_price]'" class="ar-input" required>
                    </div>
                    <div>
                        <label class="ar-label">Unidad</label>
                        <input type="text" :name="'items['+(i-1)+'][unit]'" class="ar-input" value="u">
                    </div>
                    <div>
                        <label class="ar-label">SKU línea</label>
                        <input type="text" :name="'items['+(i-1)+'][sku]'" class="ar-input">
                    </div>
                </div>
            </template>
        </div>

        <div>
            <label class="ar-label">Observaciones</label>
            <textarea name="notes" class="ar-input" rows="2">{{ old('notes') }}</textarea>
        </div>

        <div class="flex justify-end gap-2">
            <a href="{{ route('purchases.index') }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button class="ar-btn ar-btn-primary">Registrar compra</button>
        </div>
    </form>
</x-app-layout>
