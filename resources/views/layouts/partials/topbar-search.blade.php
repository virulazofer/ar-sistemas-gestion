{{-- Búsqueda global en topbar (reutiliza GET /buscar + GlobalSearchService) --}}
<div
    class="ar-topbar-search"
    x-data="{
        open: false,
        mobileOpen: false,
        q: '',
        loading: false,
        results: null,
        timer: null,
        endpoint: @js(route('search')),
        labels: {
            clients: 'Clientes',
            suppliers: 'Proveedores',
            products: 'Productos',
            equipment: 'Equipos',
            work_orders: 'Órdenes de trabajo',
            quotations: 'Presupuestos',
            sales: 'Ventas'
        },
        hasAny() {
            if (!this.results) return false;
            return Object.values(this.results).some(list => list && list.length);
        },
        onInput() {
            clearTimeout(this.timer);
            const value = this.q.trim();
            if (value.length < 2) {
                this.results = null;
                this.open = false;
                this.loading = false;
                return;
            }
            this.timer = setTimeout(() => this.fetchResults(), 300);
        },
        async fetchResults() {
            const value = this.q.trim();
            if (value.length < 2) return;
            this.loading = true;
            try {
                const url = this.endpoint + '?q=' + encodeURIComponent(value) + '&limit=5';
                const res = await fetch(url, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                });
                if (!res.ok) throw new Error('search failed');
                const data = await res.json();
                this.results = data.groups || data;
                this.open = true;
            } catch (e) {
                this.results = null;
                this.open = false;
            } finally {
                this.loading = false;
            }
        },
        goFull() {
            const value = this.q.trim();
            if (!value) return;
            window.location.href = this.endpoint + '?q=' + encodeURIComponent(value);
        },
        close() {
            this.open = false;
            this.mobileOpen = false;
        },
        openMobile() {
            this.mobileOpen = true;
            this.$nextTick(() => this.$refs.mobileInput?.focus());
        }
    }"
    @keydown.escape.window="if (open || mobileOpen) { open = false; mobileOpen = false; }"
    @click.outside="open = false"
>
    {{-- Desktop: campo central --}}
    <div class="ar-topbar-search-desktop relative hidden min-w-0 flex-1 lg:block">
        <div class="ar-topbar-search-field">
            <svg class="ar-topbar-search-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <circle cx="11" cy="11" r="6"/><path d="m20 20-3.5-3.5"/>
            </svg>
            <input
                type="search"
                class="ar-topbar-search-input"
                placeholder="Buscar en AR Sistemas..."
                autocomplete="off"
                x-model="q"
                @input="onInput()"
                @keydown.enter.prevent="goFull()"
                @focus="if (results) open = true"
                aria-label="Búsqueda global"
            >
            <span class="ar-topbar-search-hint" x-show="loading" x-cloak>…</span>
        </div>

        <div class="ar-topbar-search-dropdown" x-show="open" x-cloak role="listbox">
            <template x-if="loading">
                <p class="ar-muted px-3 py-2 text-sm">Buscando…</p>
            </template>
            <template x-if="!loading && results && !hasAny()">
                <p class="ar-muted px-3 py-2 text-sm">Sin resultados.</p>
            </template>
            <template x-if="!loading && results && hasAny()">
                <div>
                    <template x-for="(items, key) in results" :key="key">
                        <div x-show="items && items.length">
                            <div class="ar-topbar-search-group" x-text="labels[key] || key"></div>
                            <template x-for="item in items" :key="item.url">
                                <a :href="item.url" class="ar-topbar-search-item" @click="close()">
                                    <span class="font-medium" x-text="item.label"></span>
                                    <span class="ar-muted block text-xs" x-show="item.subtitle" x-text="item.subtitle"></span>
                                </a>
                            </template>
                        </div>
                    </template>
                    <button type="button" class="ar-topbar-search-more" @click="goFull()">Ver todos los resultados</button>
                </div>
            </template>
        </div>
    </div>

    {{-- Móvil: icono + panel --}}
    <div class="lg:hidden">
        <button type="button" class="ar-btn ar-btn-secondary text-xs" @click="openMobile()" aria-label="Abrir búsqueda">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <circle cx="11" cy="11" r="6"/><path d="m20 20-3.5-3.5"/>
            </svg>
        </button>

        <div class="ar-topbar-search-mobile" x-show="mobileOpen" x-cloak>
            <div class="ar-topbar-search-mobile-bar">
                <div class="ar-topbar-search-field flex-1">
                    <svg class="ar-topbar-search-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <circle cx="11" cy="11" r="6"/><path d="m20 20-3.5-3.5"/>
                    </svg>
                    <input
                        type="search"
                        class="ar-topbar-search-input"
                        placeholder="Buscar en AR Sistemas..."
                        autocomplete="off"
                        x-ref="mobileInput"
                        x-model="q"
                        @input="onInput()"
                        @keydown.enter.prevent="goFull()"
                    >
                </div>
                <button type="button" class="ar-btn ar-btn-secondary text-xs" @click="close()">Cerrar</button>
            </div>
            <div class="ar-topbar-search-mobile-results" x-show="open" x-cloak>
                <template x-if="loading">
                    <p class="ar-muted px-3 py-2 text-sm">Buscando…</p>
                </template>
                <template x-if="!loading && results && !hasAny()">
                    <p class="ar-muted px-3 py-2 text-sm">Sin resultados.</p>
                </template>
                <template x-if="!loading && results && hasAny()">
                    <div>
                        <template x-for="(items, key) in results" :key="'m-'+key">
                            <div x-show="items && items.length">
                                <div class="ar-topbar-search-group" x-text="labels[key] || key"></div>
                                <template x-for="item in items" :key="'m-'+item.url">
                                    <a :href="item.url" class="ar-topbar-search-item" @click="close()">
                                        <span class="font-medium" x-text="item.label"></span>
                                        <span class="ar-muted block text-xs" x-show="item.subtitle" x-text="item.subtitle"></span>
                                    </a>
                                </template>
                            </div>
                        </template>
                        <button type="button" class="ar-topbar-search-more" @click="goFull()">Ver todos los resultados</button>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>
