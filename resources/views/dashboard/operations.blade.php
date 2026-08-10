<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Dashboard operativo</h1>
                <p class="ar-muted text-sm">Información accionable · monedas separadas · {{ $data['generated_at'] }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-page-help topic="dashboard" />
                <a href="{{ route('dashboard.operations', ['scope' => 'personal']) }}" class="ar-btn ar-btn-secondary {{ ($data['scope'] ?? '') === 'personal' ? 'font-semibold' : '' }}">Personal</a>
                <a href="{{ route('dashboard.operations', ['scope' => 'professional']) }}" class="ar-btn ar-btn-secondary {{ ($data['scope'] ?? '') === 'professional' ? 'font-semibold' : '' }}">Profesional</a>
                <a href="{{ route('dashboard.operations', ['scope' => 'all']) }}" class="ar-btn ar-btn-secondary {{ ($data['scope'] ?? '') === 'all' ? 'font-semibold' : '' }}">Consolidado</a>
                @can('exchange_rates.create')
                    <form method="POST" action="{{ route('dashboard.refresh-rate') }}">@csrf<button class="ar-btn ar-btn-primary">Actualizar dólar</button></form>
                @endcan
            </div>
        </div>
    </x-slot>

    @if (!empty($data['alerts']))
        <div class="mb-4 grid gap-2">
            @foreach ($data['alerts'] as $alert)
                <a href="{{ $alert['url'] }}" class="ar-card block px-4 py-3 text-sm" style="border-left: 3px solid {{ $alert['level'] === 'danger' ? 'var(--ar-danger)' : ($alert['level'] === 'warn' ? '#c47b2b' : 'var(--ar-brand)') }};">
                    {{ $alert['text'] }}
                </a>
            @endforeach
        </div>
    @endif

    <div class="mb-4 grid gap-4 lg:grid-cols-3">
        <div class="ar-card p-4">
            <h2 class="mb-2 font-semibold">Líquido ARS</h2>
            <p class="text-2xl font-bold">$ {{ number_format((float) $data['liquid']['ARS']['total'], 2, ',', '.') }}</p>
            <ul class="ar-muted mt-2 space-y-1 text-sm">
                <li>Efectivo: {{ number_format((float) $data['liquid']['ARS']['cash'], 2, ',', '.') }}</li>
                <li>Bancos: {{ number_format((float) $data['liquid']['ARS']['bank'], 2, ',', '.') }}</li>
                <li>Billeteras: {{ number_format((float) $data['liquid']['ARS']['wallet'], 2, ',', '.') }}</li>
                <li>Otras: {{ number_format((float) $data['liquid']['ARS']['other'], 2, ',', '.') }}</li>
            </ul>
        </div>
        <div class="ar-card p-4">
            <h2 class="mb-2 font-semibold">Líquido USD</h2>
            <p class="text-2xl font-bold">U$S {{ number_format((float) $data['liquid']['USD']['total'], 2, ',', '.') }}</p>
            <ul class="ar-muted mt-2 space-y-1 text-sm">
                <li>Efectivo: {{ number_format((float) $data['liquid']['USD']['cash'], 2, ',', '.') }}</li>
                <li>Bancos: {{ number_format((float) $data['liquid']['USD']['bank'], 2, ',', '.') }}</li>
                <li>Billeteras: {{ number_format((float) $data['liquid']['USD']['wallet'], 2, ',', '.') }}</li>
                <li>Otras: {{ number_format((float) $data['liquid']['USD']['other'], 2, ',', '.') }}</li>
            </ul>
        </div>
        <div class="ar-card p-4">
            <h2 class="mb-2 font-semibold">Dólar oficial</h2>
            @if (!empty($data['rate']['rate']))
                <p class="text-2xl font-bold">{{ number_format((float) $data['rate']['rate'], 2, ',', '.') }}</p>
                <p class="ar-muted text-sm">{{ $data['rate_label'] }}@if (!empty($data['rate']['rate_at_label'])) · {{ $data['rate']['rate_at_label'] }}@endif</p>
            @else
                <p class="ar-muted">Sin cotización cargada.</p>
            @endif
            <p class="ar-muted mt-3 text-xs">No se suman ARS + USD. Cuentas compartidas personal/profesional.</p>
        </div>
    </div>

    <div class="mb-4 grid gap-4 lg:grid-cols-2">
        <div class="ar-card p-4">
            <h2 class="mb-2 font-semibold">Actividad del mes ({{ $data['activity']['filter'] }})</h2>
            <p class="text-sm">Ingresos ARS: <strong>{{ number_format((float) $data['activity']['income_ars'], 2, ',', '.') }}</strong></p>
            <p class="text-sm">Egresos ARS: <strong>{{ number_format((float) $data['activity']['expense_ars'], 2, ',', '.') }}</strong></p>
            <p class="text-lg font-semibold">Resultado: {{ number_format((float) $data['activity']['result_ars'], 2, ',', '.') }}</p>
            <p class="ar-muted mt-2 text-xs">{{ $data['activity']['note'] }}</p>
        </div>
        <div class="ar-card p-4">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="font-semibold">Clientes / Proveedores</h2>
                <a href="{{ route('clients.current-accounts', ['filter' => 'owing']) }}" class="text-sm" style="color: var(--ar-brand);">Ver CC</a>
            </div>
            <p class="text-sm">CxC ARS: <strong>{{ number_format((float) $data['clients']['receivable_ars'], 2, ',', '.') }}</strong> · USD: <strong>{{ number_format((float) $data['clients']['receivable_usd'], 2, ',', '.') }}</strong></p>
            <p class="text-sm">Deudores: {{ $data['clients']['debtors_count'] }}</p>
            <p class="mt-2 text-sm">CxP ARS: <strong>{{ number_format((float) $data['suppliers']['payable_ars'], 2, ',', '.') }}</strong> · USD: <strong>{{ number_format((float) $data['suppliers']['payable_usd'], 2, ',', '.') }}</strong></p>
        </div>
    </div>

    <div class="mb-4 grid gap-4 lg:grid-cols-4">
        <div class="ar-card p-4">
            <div class="mb-2 flex justify-between"><h2 class="font-semibold">Stock</h2><a href="{{ route('stock.index') }}" class="text-sm" style="color: var(--ar-brand);">Abrir</a></div>
            <p class="text-sm">Productos: {{ $data['stock']['products_count'] }}</p>
            <p class="text-sm">Sin stock: {{ $data['stock']['out_of_stock'] }} · Bajo mín.: {{ $data['stock']['below_min'] }}</p>
            <p class="text-sm">Valor FIFO USD: {{ number_format((float) $data['stock']['value_usd'], 2, ',', '.') }}</p>
            <p class="text-sm">Valor FIFO ARS: {{ number_format((float) $data['stock']['value_ars'], 2, ',', '.') }}</p>
        </div>
        <div class="ar-card p-4">
            <div class="mb-2 flex justify-between"><h2 class="font-semibold">Equipos</h2><a href="{{ route('equipment.index') }}" class="text-sm" style="color: var(--ar-brand);">Abrir</a></div>
            <p class="text-sm">Disponibles: {{ $data['equipment']['available'] ?? 0 }}</p>
            <p class="text-sm">Vendidos: {{ $data['equipment']['sold'] ?? 0 }}</p>
            <p class="text-sm">Reparación: {{ $data['equipment']['in_repair'] ?? 0 }}</p>
            <p class="text-sm">Reservados: {{ $data['equipment']['reserved'] ?? 0 }}</p>
        </div>
        <div class="ar-card p-4">
            <div class="mb-2 flex justify-between"><h2 class="font-semibold">OT</h2><a href="{{ route('work-orders.index') }}" class="text-sm" style="color: var(--ar-brand);">Abrir</a></div>
            <p class="text-sm">Abiertas: {{ $data['work_orders']['open'] ?? 0 }}</p>
            <p class="text-sm">Diagnóstico: {{ $data['work_orders']['diagnosing'] ?? 0 }}</p>
            <p class="text-sm">En reparación: {{ $data['work_orders']['in_repair'] ?? 0 }}</p>
            <p class="text-sm">Atrasadas: {{ $data['work_orders']['overdue'] ?? 0 }}</p>
        </div>
        <div class="ar-card p-4">
            <div class="mb-2 flex justify-between"><h2 class="font-semibold">Abonos</h2><a href="{{ route('subscriptions.index') }}" class="text-sm" style="color: var(--ar-brand);">Abrir</a></div>
            <p class="text-sm">Activos: {{ $data['subscriptions']['active'] }}</p>
            <p class="text-sm">Próximos: {{ $data['subscriptions']['dueSoon'] }}</p>
            <p class="text-sm">Pausados: {{ $data['subscriptions']['paused'] }}</p>
            <p class="text-sm">Cargos 30d: {{ $data['subscriptions']['recentCharges'] }}</p>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="ar-card p-4">
            <div class="mb-2 flex justify-between"><h2 class="font-semibold">Ventas del mes</h2><a href="{{ route('sales.index') }}" class="text-sm" style="color: var(--ar-brand);">Abrir</a></div>
            <p class="text-sm">Cantidad: {{ $data['sales']['count'] }} · Pend. cobro: {{ $data['sales']['pending_collection'] }}</p>
            <p class="text-sm">ARS: {{ number_format((float) $data['sales']['total_ars'], 2, ',', '.') }} · margen {{ number_format((float) $data['sales']['margin_ars'], 2, ',', '.') }}</p>
            <p class="text-sm">USD: {{ number_format((float) $data['sales']['total_usd'], 2, ',', '.') }} · margen {{ number_format((float) $data['sales']['margin_usd'], 2, ',', '.') }}</p>
            <p class="ar-muted mt-2 text-xs">{{ $data['sales']['note'] }}</p>
        </div>
        <div class="ar-card p-4">
            <div class="mb-2 flex justify-between"><h2 class="font-semibold">Presupuestos</h2><a href="{{ route('quotations.index') }}" class="text-sm" style="color: var(--ar-brand);">Abrir</a></div>
            <p class="text-sm">Borrador: {{ $data['quotations']['draft'] ?? 0 }} · Enviados: {{ $data['quotations']['sent'] ?? 0 }}</p>
            <p class="text-sm">Aceptados: {{ $data['quotations']['accepted'] ?? 0 }} · Vencidos: {{ $data['quotations']['expired'] ?? 0 }}</p>
            <p class="text-sm">Convertidos: {{ $data['quotations']['converted'] ?? 0 }}</p>
        </div>
    </div>
</x-app-layout>
