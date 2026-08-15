<!DOCTYPE html>
<html
        lang="es"
        class="{{ auth()->user()?->prefersDarkTheme() ? 'dark' : '' }}"
        data-palette="{{ auth()->user()?->appearancePalette() ?? 'actual' }}"
    >
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#0f4c5c">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="AR Sistemas">
        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="apple-touch-icon" href="/icons/icon-192.png">
        <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png">

        <title>@yield('title', config('app.name', 'AR Sistemas - Gestión'))</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=source-sans-3:400,500,600,700|ibm-plex-sans:500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            body { font-family: 'Source Sans 3', ui-sans-serif, system-ui, sans-serif; }
            .ar-brand { font-family: 'IBM Plex Sans', sans-serif; }
            [x-cloak] { display: none !important; }

            /* Plan de cuentas — layout crítico desktop (independiente del build Vite) */
            .ar-chart-mobile-nav { display: block; }
            .ar-chart-workspace { display: none; }
            .ar-chart-mobile-detail { display: block; }
            .ar-chart-tree-panel,
            .ar-chart-detail-panel { min-width: 0; }
            .ar-chart-caret {
                display: inline-block;
                width: 1rem;
                flex-shrink: 0;
                transition: transform 0.15s ease;
                transform-origin: center;
            }
            .ar-chart-caret.is-open { transform: rotate(90deg); }
            @media (min-width: 1024px) {
                .ar-chart-mobile-nav,
                .ar-chart-mobile-detail { display: none !important; }
                .ar-chart-workspace {
                    display: grid !important;
                    grid-template-columns: minmax(18rem, 34%) minmax(0, 1fr);
                    gap: 1rem;
                    align-items: stretch;
                }
                .ar-chart-tree-panel,
                .ar-chart-detail-panel {
                    max-height: 78vh;
                    overflow: auto;
                }
            }

            .ar-shell-app {
                min-height: 100vh;
                display: flex;
                background:
                    radial-gradient(1200px 400px at 10% -10%, color-mix(in srgb, var(--ar-brand) 12%, transparent), transparent),
                    var(--ar-bg);
            }
            .ar-sidebar {
                position: fixed;
                inset: 0 auto 0 0;
                z-index: 40;
                width: 16.5rem;
                display: flex;
                flex-direction: column;
                background: var(--ar-surface);
                border-right: 1px solid var(--ar-border);
                box-shadow: var(--ar-shadow);
                transition: width .2s ease, transform .2s ease;
            }
            .ar-sidebar.is-collapsed { width: 4.5rem; }
            .ar-sidebar-brand {
                display: flex;
                align-items: center;
                gap: .75rem;
                height: 3.75rem;
                padding: 0 1rem;
                border-bottom: 1px solid var(--ar-border);
                text-decoration: none;
                color: var(--ar-brand);
                font-weight: 700;
                overflow: hidden;
                white-space: nowrap;
            }
            .ar-sidebar-scroll {
                flex: 1;
                overflow-y: auto;
                padding: .75rem .5rem 1rem;
            }
            .ar-side-group { margin-bottom: .5rem; }
            .ar-side-group-btn {
                width: 100%;
                display: flex;
                align-items: center;
                gap: .65rem;
                border: 0;
                background: transparent;
                color: var(--ar-muted);
                border-radius: .5rem;
                padding: .55rem .65rem;
                font-size: .7rem;
                font-weight: 700;
                letter-spacing: .05em;
                text-transform: uppercase;
                cursor: pointer;
            }
            .ar-side-group-btn:hover { background: var(--ar-surface-2); color: var(--ar-text); }
            .ar-side-link {
                display: flex;
                align-items: center;
                gap: .65rem;
                border-radius: .5rem;
                padding: .5rem .65rem;
                margin: .1rem 0;
                font-size: .875rem;
                font-weight: 500;
                color: var(--ar-text);
                text-decoration: none;
                transition: background .15s ease, color .15s ease;
            }
            .ar-side-link:hover { background: var(--ar-surface-2); color: var(--ar-brand); }
            .ar-side-link.is-active {
                background: var(--ar-brand-soft);
                color: var(--ar-brand);
                font-weight: 600;
            }
            .ar-side-ico {
                width: 1.15rem;
                height: 1.15rem;
                flex: 0 0 1.15rem;
                opacity: .9;
            }
            .ar-side-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .ar-side-nested { margin: .15rem 0 .35rem; }
            .ar-side-nested-title {
                margin: .35rem .65rem .15rem;
                font-size: .68rem;
                font-weight: 700;
                letter-spacing: .04em;
                text-transform: uppercase;
                color: var(--ar-muted);
            }
            .ar-side-nested .ar-side-link { padding-left: 1.35rem; }
            .ar-sidebar.is-collapsed .ar-side-nested-title { display: none; }
            .ar-sidebar.is-collapsed .ar-side-label,
            .ar-sidebar.is-collapsed .ar-side-chevron,
            .ar-sidebar.is-collapsed .ar-side-group-btn span:not(.ar-side-ico-wrap) { display: none; }
            .ar-sidebar.is-collapsed .ar-side-group-btn { justify-content: center; padding: .55rem; }
            .ar-sidebar.is-collapsed .ar-side-link { justify-content: center; padding: .6rem; }
            /* Los submenús los controla Alpine (grupo + colapso); no usar display !important. */
            .ar-sidebar-footer {
                border-top: 1px solid var(--ar-border);
                padding: .75rem .5rem;
                display: grid;
                gap: .35rem;
            }
            .ar-main {
                flex: 1;
                min-width: 0;
                margin-left: 16.5rem;
                transition: margin-left .2s ease;
                display: flex;
                flex-direction: column;
                min-height: 100vh;
            }
            .ar-shell-app.is-collapsed .ar-main { margin-left: 4.5rem; }
            .ar-topbar {
                height: 3.75rem;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: .75rem;
                padding: 0 1rem;
                border-bottom: 1px solid var(--ar-border);
                background: color-mix(in srgb, var(--ar-surface) 92%, transparent);
                backdrop-filter: blur(8px);
                position: sticky;
                top: 0;
                z-index: 30;
            }
            .ar-topbar-search {
                display: flex;
                align-items: center;
                min-width: 0;
                flex: 1;
                max-width: 40rem;
                margin: 0 auto;
            }
            .ar-topbar-search-desktop { width: 100%; }
            .ar-topbar-search-field {
                display: flex;
                align-items: center;
                gap: .5rem;
                width: 100%;
                border: 1px solid var(--ar-border);
                background: var(--ar-surface-2);
                border-radius: .65rem;
                padding: .35rem .75rem;
                min-height: 2.35rem;
            }
            .ar-topbar-search-ico {
                width: 1rem;
                height: 1rem;
                flex: 0 0 1rem;
                color: var(--ar-muted);
            }
            .ar-topbar-search-input {
                flex: 1;
                min-width: 0;
                border: 0;
                background: transparent;
                color: var(--ar-text);
                font-size: .875rem;
                outline: none;
            }
            .ar-topbar-search-input::placeholder { color: var(--ar-muted); }
            .ar-topbar-search-hint { color: var(--ar-muted); font-size: .75rem; }
            .ar-topbar-search-dropdown {
                position: absolute;
                left: 0;
                right: 0;
                top: calc(100% + .35rem);
                z-index: 50;
                max-height: 22rem;
                overflow: auto;
                background: var(--ar-surface);
                border: 1px solid var(--ar-border);
                border-radius: .75rem;
                box-shadow: var(--ar-shadow);
                padding: .35rem;
            }
            .ar-topbar-search-group {
                padding: .45rem .75rem .2rem;
                font-size: .65rem;
                font-weight: 700;
                letter-spacing: .05em;
                text-transform: uppercase;
                color: var(--ar-muted);
            }
            .ar-topbar-search-item {
                display: block;
                border-radius: .5rem;
                padding: .5rem .75rem;
                text-decoration: none;
                color: var(--ar-text);
            }
            .ar-topbar-search-item:hover,
            .ar-topbar-search-item.is-active {
                background: var(--ar-surface-2);
                color: var(--ar-brand);
            }
            .ar-appearance-popover {
                background: var(--ar-surface);
                border: 1px solid var(--ar-border);
                border-radius: .75rem;
                box-shadow: var(--ar-shadow);
            }
            .ar-appearance-choice {
                display: flex;
                align-items: center;
                justify-content: center;
                border: 1px solid var(--ar-border);
                background: var(--ar-surface-2);
                border-radius: .5rem;
                padding: .45rem .5rem;
                font-size: .75rem;
                font-weight: 600;
                cursor: pointer;
                color: var(--ar-text);
                transition: border-color .15s ease, background .15s ease, color .15s ease, box-shadow .15s ease;
            }
            .ar-appearance-choice:hover {
                border-color: var(--ar-brand);
                background: var(--ar-surface);
                color: var(--ar-brand);
            }
            .ar-appearance-choice:focus,
            .ar-appearance-choice:focus-visible {
                outline: 2px solid var(--ar-brand);
                outline-offset: 2px;
            }
            .ar-appearance-choice:not(.is-selected) {
                opacity: .92;
            }
            .ar-appearance-choice.is-selected {
                border-color: var(--ar-brand);
                background: var(--ar-brand-soft);
                color: var(--ar-brand);
                opacity: 1;
                box-shadow: inset 0 0 0 1px var(--ar-brand);
            }
            .ar-appearance-choice.is-selected:hover {
                background: var(--ar-brand-soft);
            }
            .ar-topbar-search-more {
                width: 100%;
                border: 0;
                background: transparent;
                color: var(--ar-brand);
                font-size: .8rem;
                font-weight: 600;
                padding: .55rem .75rem;
                text-align: left;
                cursor: pointer;
                border-radius: .5rem;
            }
            .ar-topbar-search-more:hover { background: var(--ar-brand-soft); }
            .ar-topbar-search-mobile {
                position: fixed;
                inset: 0 0 auto 0;
                z-index: 60;
                background: var(--ar-surface);
                border-bottom: 1px solid var(--ar-border);
                box-shadow: var(--ar-shadow);
                padding: .75rem 1rem 1rem;
            }
            .ar-topbar-search-mobile-bar {
                display: flex;
                align-items: center;
                gap: .5rem;
            }
            .ar-topbar-search-mobile-results {
                margin-top: .65rem;
                max-height: min(60vh, 22rem);
                overflow: auto;
                border: 1px solid var(--ar-border);
                border-radius: .75rem;
                background: var(--ar-surface-2);
                padding: .35rem;
            }
            .ar-drawer-overlay {
                position: fixed;
                inset: 0;
                z-index: 35;
                background: rgb(15 23 42 / 45%);
            }
            @media (max-width: 1023px) {
                .ar-sidebar {
                    transform: translateX(-105%);
                    width: 17rem !important;
                }
                .ar-sidebar.is-open { transform: translateX(0); }
                /* En móvil el drawer siempre muestra etiquetas; el colapso de iconos es solo desktop. */
                .ar-sidebar.is-collapsed { width: 17rem !important; }
                .ar-sidebar.is-collapsed .ar-side-label,
                .ar-sidebar.is-collapsed .ar-side-chevron,
                .ar-sidebar.is-collapsed .ar-side-group-btn span:not(.ar-side-ico-wrap) { display: initial; }
                .ar-sidebar.is-collapsed .ar-side-group-btn { justify-content: flex-start; padding: .55rem .65rem; }
                .ar-sidebar.is-collapsed .ar-side-link { justify-content: flex-start; padding: .5rem .65rem; }
                .ar-main { margin-left: 0 !important; }
                .ar-shell-app.is-collapsed .ar-main { margin-left: 0 !important; }
            }
        </style>
    </head>
    <body class="antialiased">
        <div
            id="ar-offline-banner"
            hidden
            aria-hidden="true"
            class="fixed inset-x-0 top-0 z-[70] px-3 py-2 text-center text-sm font-medium"
            style="background: color-mix(in srgb, var(--ar-warning, #b45309) 90%, #000); color: #fff;"
        >
            Sin conexión. Las operaciones que modifican datos requieren conexión.
        </div>
        @php
            $sidebarActiveGroup = match (true) {
                request()->routeIs('movements.quick*')
                    || request()->routeIs('dashboard.operations*')
                    || request()->routeIs('dashboard')
                    || request()->routeIs('documents.*') => 'inicio',
                request()->routeIs('clients.*')
                    || request()->routeIs('suppliers.*')
                    || request()->routeIs('products.*')
                    || request()->routeIs('categories.*')
                    || request()->routeIs('subcategories.*')
                    || request()->routeIs('equipment.types.*')
                    || (request()->routeIs('reports.show') && request()->route('type') === 'chart-accounts') => 'mae',
                request()->routeIs('accounts.*')
                    || request()->routeIs('movements.index')
                    || request()->routeIs('movements.show')
                    || request()->routeIs('exchange-rates.*') => 'fin',
                request()->routeIs('quotations.*')
                    || request()->routeIs('sales.*')
                    || request()->routeIs('subscriptions.*') => 'com',
                request()->routeIs('work-orders.*')
                    || request()->routeIs('equipment.index')
                    || request()->routeIs('equipment.create')
                    || request()->routeIs('equipment.show')
                    || request()->routeIs('equipment.edit')
                    || request()->routeIs('equipment.*') => 'ops',
                request()->routeIs('stock.*')
                    || request()->routeIs('purchases.*') => 'inv',
                request()->routeIs('reports.*')
                    || request()->routeIs('imports.*') => 'rep',
                request()->routeIs('users.*')
                    || request()->routeIs('permissions.*')
                    || request()->routeIs('settings.*')
                    || request()->routeIs('audit.*') => 'adm',
                default => null,
            };

            $sidebarGroups = [
                'inicio' => $sidebarActiveGroup === 'inicio',
                'mae' => $sidebarActiveGroup === 'mae',
                'fin' => $sidebarActiveGroup === 'fin',
                'com' => $sidebarActiveGroup === 'com',
                'ops' => $sidebarActiveGroup === 'ops',
                'inv' => $sidebarActiveGroup === 'inv',
                'rep' => $sidebarActiveGroup === 'rep',
                'adm' => $sidebarActiveGroup === 'adm',
            ];
        @endphp
        {{-- x-data con comillas dobles: @js() emite JSON.parse('...') y no debe romper el atributo HTML. --}}
        <div
            class="ar-shell-app"
            data-sidebar-active="{{ $sidebarActiveGroup ?? '' }}"
            x-data="{
                collapsed: localStorage.getItem('ar-sidebar-collapsed') === '1',
                drawer: false,
                groups: @js($sidebarGroups),
                toggleCollapse() {
                    this.collapsed = !this.collapsed;
                    localStorage.setItem('ar-sidebar-collapsed', this.collapsed ? '1' : '0');
                },
                toggleGroup(key) {
                    if (this.collapsed && window.innerWidth >= 1024) {
                        this.collapsed = false;
                        localStorage.setItem('ar-sidebar-collapsed', '0');
                    }
                    this.groups = Object.assign({}, this.groups, { [key]: !this.groups[key] });
                },
                closeDrawer() { this.drawer = false; }
            }"
            :class="{ 'is-collapsed': collapsed }"
        >
            @include('layouts.navigation')

            <div class="ar-main">
                <div class="ar-topbar">
                    <div class="flex shrink-0 items-center gap-2">
                        <button type="button" class="ar-btn ar-btn-secondary text-xs lg:hidden" @click="drawer = true">Menú</button>
                        <button type="button" class="ar-btn ar-btn-secondary text-xs hidden lg:inline-flex" @click="toggleCollapse()" x-text="collapsed ? 'Expandir' : 'Contraer'"></button>
                    </div>

                    @include('layouts.partials.topbar-search')

                    <div class="flex shrink-0 items-center gap-2">
                        @include('layouts.partials.appearance-popover')
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="ar-btn ar-btn-secondary text-xs">Salir</button>
                        </form>
                    </div>
                </div>

                @isset($header)
                    <header class="border-b" style="border-color: var(--ar-border); background: var(--ar-surface);">
                        <div class="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endisset

                <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6 lg:px-8">
                    @if (session('status'))
                        <div class="mb-4 rounded-lg px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ar-success) 16%, transparent); color: var(--ar-success);">
                            {{ session('status') }}
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="mb-4 rounded-lg px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ar-danger) 14%, transparent); color: var(--ar-danger);">
                            <ul class="list-disc ps-4">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    {{ $slot }}
                </main>
            </div>

            <div class="ar-drawer-overlay lg:hidden" x-show="drawer" x-cloak style="display: none;" @click="closeDrawer()"></div>
        </div>
    </body>
</html>
