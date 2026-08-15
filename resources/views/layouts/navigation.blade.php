@php
    $homeHref = auth()->user()->can('movements.create') ? route('movements.quick') : route('dashboard');
@endphp

<aside
    class="ar-sidebar"
    :class="{ 'is-collapsed': collapsed, 'is-open': drawer }"
    @keydown.escape.window="closeDrawer()"
>
    <a href="{{ $homeHref }}" class="ar-sidebar-brand" @click="closeDrawer()">
        <svg class="ar-side-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
        <span class="ar-side-label">AR Sistemas <span class="ar-muted font-medium">· Gestión</span></span>
    </a>

    <div class="ar-sidebar-scroll">
        <div class="ar-side-group">
            <button type="button" class="ar-side-group-btn" @click="toggleGroup('inicio')">
                <span class="ar-side-ico-wrap"><svg class="ar-side-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 11.5 12 5l8 6.5V20a1 1 0 0 1-1 1h-5v-5H10v5H5a1 1 0 0 1-1-1v-8.5Z"/></svg></span>
                <span>Inicio</span>
                <svg class="ar-side-chevron ml-auto h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" :class="groups.inicio ? 'rotate-180' : ''"><path d="M5.25 7.5 10 12.25 14.75 7.5"/></svg>
            </button>
            <div class="ar-side-sub" x-show="groups.inicio && (drawer || !collapsed)" x-cloak>
                @can('movements.create')
                    <a href="{{ route('movements.quick') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('movements.quick*') ? 'is-active' : '' }}">
                        <svg class="ar-side-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 5v14M5 12h14"/></svg>
                        <span class="ar-side-label">Cargar movimiento</span>
                    </a>
                @endcan
                {{-- 12A: capturar/listar documentos ocultos de la UI pública hasta certificación (config documents.show_in_ui). --}}
                @if (config('documents.show_in_ui'))
                    @can('documents.create')
                        <a href="{{ route('documents.capture') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('documents.capture') ? 'is-active' : '' }}">
                            <svg class="ar-side-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h4l2-2h4l2 2h4v12H4V7Z"/><circle cx="12" cy="13" r="3.5"/></svg>
                            <span class="ar-side-label">Capturar documento</span>
                        </a>
                    @endcan
                    @can('documents.view')
                        <a href="{{ route('documents.index') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('documents.index') || request()->routeIs('documents.show') ? 'is-active' : '' }}">
                            <svg class="ar-side-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 3h7l5 5v13H7V3Z"/><path d="M14 3v5h5"/></svg>
                            <span class="ar-side-label">Documentos</span>
                        </a>
                    @endcan
                @endif
                @can('dashboard.view')
                    <a href="{{ route('dashboard.operations') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('dashboard.operations*') ? 'is-active' : '' }}">
                        <svg class="ar-side-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19V9M10 19V5M16 19v-7M22 19H2"/></svg>
                        <span class="ar-side-label">Tablero</span>
                    </a>
                    <a href="{{ route('dashboard.management') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('dashboard.management') ? 'is-active' : '' }}">
                        <svg class="ar-side-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19V5h4v14H4Zm6 0V9h4v10h-4Zm6 0v-7h4v7h-4Z"/></svg>
                        <span class="ar-side-label">Tablero de gestión</span>
                    </a>
                @endcan
            </div>
        </div>

        @canany(['clients.view', 'suppliers.view', 'categories.view'])
            <div class="ar-side-group">
                <button type="button" class="ar-side-group-btn" @click="toggleGroup('mae')">
                    <span class="ar-side-ico-wrap"><svg class="ar-side-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h16M4 18h10"/></svg></span>
                    <span>Maestros</span>
                    <svg class="ar-side-chevron ml-auto h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" :class="groups.mae ? 'rotate-180' : ''"><path d="M5.25 7.5 10 12.25 14.75 7.5"/></svg>
                </button>
                <div class="ar-side-sub" x-show="groups.mae && (drawer || !collapsed)" x-cloak>
                    @can('clients.view')<a href="{{ route('clients.index') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('clients.index') || request()->routeIs('clients.create') || request()->routeIs('clients.show') || request()->routeIs('clients.edit') || request()->routeIs('clients.current-accounts') ? 'is-active' : '' }}"><span class="ar-side-label">Clientes</span></a>@endcan
                    @can('suppliers.view')<a href="{{ route('suppliers.index') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('suppliers.*') ? 'is-active' : '' }}"><span class="ar-side-label">Proveedores</span></a>@endcan
                    @php
                        $pendingClassify = 0;
                        try {
                            if (auth()->user()?->can('categories.view')) {
                                $pendingClassify = app(\App\Services\Finance\ChartAccountMappingService::class)->classificationProgress()['pending'] ?? 0;
                            }
                        } catch (\Throwable) { $pendingClassify = 0; }
                    @endphp
                    @can('categories.view')
                        <a href="{{ route('chart-accounts.index') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('chart-accounts.*') || request()->routeIs('remembered-classifications.*') || request()->routeIs('imputation-rules.*') ? 'is-active' : '' }}">
                            <span class="ar-side-label">Plan de cuentas</span>
                        </a>
                        @if ($pendingClassify > 0)
                            <a href="{{ route('chart-accounts.classify') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('chart-accounts.classify') || request()->routeIs('chart-accounts.unclassified') ? 'is-active' : '' }}">
                                <span class="ar-side-label" style="color: var(--ar-danger);">
                                    {{ $pendingClassify }} pendientes de clasificación
                                </span>
                            </a>
                        @endif
                    @endcan
                </div>
            </div>
        @endcanany

        @canany(['accounts.view', 'movements.view', 'exchange_rates.view'])
            <div class="ar-side-group">
                <button type="button" class="ar-side-group-btn" @click="toggleGroup('fin')">
                    <span class="ar-side-ico-wrap"><svg class="ar-side-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18"/></svg></span>
                    <span>Finanzas</span>
                    <svg class="ar-side-chevron ml-auto h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" :class="groups.fin ? 'rotate-180' : ''"><path d="M5.25 7.5 10 12.25 14.75 7.5"/></svg>
                </button>
                <div class="ar-side-sub" x-show="groups.fin && (drawer || !collapsed)" x-cloak>
                    @can('accounts.view')<a href="{{ route('accounts.index') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('accounts.*') ? 'is-active' : '' }}"><span class="ar-side-label">Cuentas financieras</span></a>@endcan
                    @can('movements.view')<a href="{{ route('movements.index') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('movements.index') || request()->routeIs('movements.show') ? 'is-active' : '' }}"><span class="ar-side-label">Movimientos</span></a>@endcan
                    @can('exchange_rates.view')<a href="{{ route('exchange-rates.index') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('exchange-rates.*') ? 'is-active' : '' }}"><span class="ar-side-label">Cotizaciones</span></a>@endcan
                </div>
            </div>
        @endcanany

        @canany(['quotations.view', 'sales.view', 'subscriptions.view', 'charges.view', 'receipts.view'])
            <div class="ar-side-group">
                <button type="button" class="ar-side-group-btn" @click="toggleGroup('com')">
                    <span class="ar-side-ico-wrap"><svg class="ar-side-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 7h10v4H7zM5 11h14v8H5z"/></svg></span>
                    <span>Comercial</span>
                    <svg class="ar-side-chevron ml-auto h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" :class="groups.com ? 'rotate-180' : ''"><path d="M5.25 7.5 10 12.25 14.75 7.5"/></svg>
                </button>
                <div class="ar-side-sub" x-show="groups.com && (drawer || !collapsed)" x-cloak>
                    @can('quotations.view')<a href="{{ route('quotations.index') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('quotations.*') ? 'is-active' : '' }}"><span class="ar-side-label">Presupuestos</span></a>@endcan
                    @can('sales.view')<a href="{{ route('sales.index') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('sales.*') ? 'is-active' : '' }}"><span class="ar-side-label">Ventas</span></a>@endcan
                    @can('subscriptions.view')<a href="{{ route('subscriptions.index') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('subscriptions.*') ? 'is-active' : '' }}"><span class="ar-side-label">Abonos</span></a>@endcan
                    @can('charges.view')<a href="{{ route('charges.index') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('charges.*') ? 'is-active' : '' }}"><span class="ar-side-label">Cargos</span></a>@endcan
                    @can('receipts.view')<a href="{{ route('receipts.index') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('receipts.*') ? 'is-active' : '' }}"><span class="ar-side-label">Cobros</span></a>@endcan
                    @can('charges.view')<a href="{{ route('documental.pending') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('documental.*') ? 'is-active' : '' }}"><span class="ar-side-label">Sin comprobante</span></a>@endcan
                </div>
            </div>
        @endcanany

        @can('work_orders.view')
            <div class="ar-side-group">
                <button type="button" class="ar-side-group-btn" @click="toggleGroup('ops')">
                    <span class="ar-side-ico-wrap"><svg class="ar-side-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 7h6v6M20 7l-8.5 8.5L8 12"/></svg></span>
                    <span>Operaciones</span>
                    <svg class="ar-side-chevron ml-auto h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" :class="groups.ops ? 'rotate-180' : ''"><path d="M5.25 7.5 10 12.25 14.75 7.5"/></svg>
                </button>
                <div class="ar-side-sub" x-show="groups.ops && (drawer || !collapsed)" x-cloak>
                    <a href="{{ route('work-orders.index') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('work-orders.*') ? 'is-active' : '' }}"><span class="ar-side-label">Órdenes de trabajo</span></a>
                </div>
            </div>
        @endcan

        @canany(['stock.view', 'purchases.view', 'products.view', 'equipment.view'])
            <div class="ar-side-group">
                <button type="button" class="ar-side-group-btn" @click="toggleGroup('inv')">
                    <span class="ar-side-ico-wrap"><svg class="ar-side-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 8.5 12 3 3 8.5v7L12 21l9-5.5v-7Z"/><path d="M12 12v9M3 8.5l9 3.5 9-3.5"/></svg></span>
                    <span>Inventario</span>
                    <svg class="ar-side-chevron ml-auto h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" :class="groups.inv ? 'rotate-180' : ''"><path d="M5.25 7.5 10 12.25 14.75 7.5"/></svg>
                </button>
                <div class="ar-side-sub" x-show="groups.inv && (drawer || !collapsed)" x-cloak>
                    @can('products.view')<a href="{{ route('products.index') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('products.*') ? 'is-active' : '' }}"><span class="ar-side-label">Productos</span></a>@endcan
                    @can('equipment.view')<a href="{{ route('equipment.index') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('equipment.index') || request()->routeIs('equipment.create') || request()->routeIs('equipment.show') || request()->routeIs('equipment.edit') ? 'is-active' : '' }}"><span class="ar-side-label">Equipos</span></a>@endcan
                    @can('equipment.view')<a href="{{ route('equipment.types.index') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('equipment.types.*') ? 'is-active' : '' }}"><span class="ar-side-label">Tipos de equipo</span></a>@endcan
                    @can('purchases.view')<a href="{{ route('purchases.index') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('purchases.*') ? 'is-active' : '' }}"><span class="ar-side-label">Compras</span></a>@endcan
                </div>
            </div>
        @endcanany

        @canany(['reports.view', 'imports.view'])
            <div class="ar-side-group">
                <button type="button" class="ar-side-group-btn" @click="toggleGroup('rep')">
                    <span class="ar-side-ico-wrap"><svg class="ar-side-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 19V9M10 19V5M15 19v-6M20 19V8"/></svg></span>
                    <span>Reportes</span>
                    <svg class="ar-side-chevron ml-auto h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" :class="groups.rep ? 'rotate-180' : ''"><path d="M5.25 7.5 10 12.25 14.75 7.5"/></svg>
                </button>
                <div class="ar-side-sub" x-show="groups.rep && (drawer || !collapsed)" x-cloak>
                    @can('reports.view')<a href="{{ route('reports.index') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('reports.index') || (request()->routeIs('reports.show') && request()->route('type') !== 'chart-accounts') ? 'is-active' : '' }}"><span class="ar-side-label">Reportes</span></a>@endcan
                    @can('imports.view')<a href="{{ route('imports.index') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('imports.*') ? 'is-active' : '' }}"><span class="ar-side-label">Importaciones</span></a>@endcan
                </div>
            </div>
        @endcanany

        @canany(['users.view', 'permissions.view', 'settings.view', 'audit.view'])
            <div class="ar-side-group">
                <button type="button" class="ar-side-group-btn" @click="toggleGroup('adm')">
                    <span class="ar-side-ico-wrap"><svg class="ar-side-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="3"/><path d="M5 19a7 7 0 0 1 14 0"/></svg></span>
                    <span>Administración</span>
                    <svg class="ar-side-chevron ml-auto h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" :class="groups.adm ? 'rotate-180' : ''"><path d="M5.25 7.5 10 12.25 14.75 7.5"/></svg>
                </button>
                <div class="ar-side-sub" x-show="groups.adm && (drawer || !collapsed)" x-cloak>
                    @can('users.view')<a href="{{ route('users.index') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('users.*') ? 'is-active' : '' }}"><span class="ar-side-label">Usuarios</span></a>@endcan
                    @can('permissions.view')<a href="{{ route('permissions.index') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('permissions.*') ? 'is-active' : '' }}"><span class="ar-side-label">Permisos</span></a>@endcan
                    @can('settings.view')<a href="{{ route('settings.edit') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('settings.*') ? 'is-active' : '' }}"><span class="ar-side-label">Configuración</span></a>@endcan
                    @can('audit.view')<a href="{{ route('audit.index') }}" @click="closeDrawer()" class="ar-side-link {{ request()->routeIs('audit.*') ? 'is-active' : '' }}"><span class="ar-side-label">Auditoría</span></a>@endcan
                </div>
            </div>
        @endcanany
    </div>
</aside>
