{{-- Command Palette + búsqueda global (GET /buscar + GlobalSearchService) --}}
<div
    class="ar-topbar-search"
    x-data="commandPalette({
        endpoint: @js(route('search')),
        groupOrder: ['navigation','actions','clients','suppliers','products','equipment','work_orders','quotations','sales'],
        labels: {
            navigation: 'NAVEGACIÓN',
            actions: 'ACCIONES',
            clients: 'CLIENTES',
            suppliers: 'PROVEEDORES',
            products: 'PRODUCTOS',
            equipment: 'EQUIPOS',
            work_orders: 'ÓRDENES DE TRABAJO',
            quotations: 'PRESUPUESTOS',
            sales: 'VENTAS'
        }
    })"
    @keydown.escape.window="onEscape()"
    @keydown.window="onGlobalKey($event)"
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
                placeholder="Buscar o ir a… (Ctrl+K)"
                autocomplete="off"
                x-ref="desktopInput"
                x-model="q"
                @input="onInput()"
                @keydown.down.prevent="move(1)"
                @keydown.up.prevent="move(-1)"
                @keydown.enter.prevent="activateSelected()"
                @keydown.escape.prevent="close()"
                @focus="if (results) open = true"
                aria-label="Command palette"
                role="combobox"
                :aria-expanded="open.toString()"
            >
            <span class="ar-topbar-search-hint" x-show="loading" x-cloak>…</span>
        </div>

        <div class="ar-topbar-search-dropdown" x-show="open" x-cloak role="listbox">
            <template x-if="loading">
                <p class="ar-muted px-3 py-2 text-sm">Buscando…</p>
            </template>
            <template x-if="!loading && results && !hasAny()">
                <p class="ar-muted px-3 py-2 text-sm">No se encontraron resultados</p>
            </template>
            <template x-if="!loading && results && hasAny()">
                <div>
                    <template x-for="key in visibleGroups()" :key="key">
                        <div>
                            <div class="ar-topbar-search-group" x-text="labels[key] || key"></div>
                            <template x-for="(item, idx) in results[key]" :key="item.url + '-' + idx">
                                <a
                                    :href="item.url"
                                    class="ar-topbar-search-item"
                                    :class="{ 'is-active': isActive(key, idx) }"
                                    @mouseenter="setActive(key, idx)"
                                    @click="close()"
                                    role="option"
                                >
                                    <span class="font-medium" x-text="item.label"></span>
                                    <span class="ar-muted block text-xs" x-show="item.subtitle" x-text="item.subtitle"></span>
                                </a>
                            </template>
                            <button
                                type="button"
                                class="ar-topbar-search-more"
                                x-show="groupHasMore(key)"
                                @click="goFull(key)"
                                x-text="groupMoreLabel(key)"
                            ></button>
                        </div>
                    </template>
                    <button
                        type="button"
                        class="ar-topbar-search-more"
                        x-show="globalHasMore()"
                        @click="goFull()"
                        x-text="globalMoreLabel()"
                    ></button>
                </div>
            </template>
        </div>
    </div>

    {{-- Móvil: icono + panel compacto --}}
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
                        placeholder="Buscar o ir a…"
                        autocomplete="off"
                        x-ref="mobileInput"
                        x-model="q"
                        @input="onInput()"
                        @keydown.down.prevent="move(1)"
                        @keydown.up.prevent="move(-1)"
                        @keydown.enter.prevent="activateSelected()"
                        @keydown.escape.prevent="close()"
                    >
                </div>
                <button type="button" class="ar-btn ar-btn-secondary text-xs" @click="close()">Cerrar</button>
            </div>
            <div class="ar-topbar-search-mobile-results" x-show="open" x-cloak>
                <template x-if="loading">
                    <p class="ar-muted px-3 py-2 text-sm">Buscando…</p>
                </template>
                <template x-if="!loading && results && !hasAny()">
                    <p class="ar-muted px-3 py-2 text-sm">No se encontraron resultados</p>
                </template>
                <template x-if="!loading && results && hasAny()">
                    <div>
                        <template x-for="key in visibleGroups()" :key="'m-'+key">
                            <div>
                                <div class="ar-topbar-search-group" x-text="labels[key] || key"></div>
                                <template x-for="(item, idx) in results[key]" :key="'m-'+item.url+'-'+idx">
                                    <a
                                        :href="item.url"
                                        class="ar-topbar-search-item"
                                        :class="{ 'is-active': isActive(key, idx) }"
                                        @mouseenter="setActive(key, idx)"
                                        @click="close()"
                                    >
                                        <span class="font-medium" x-text="item.label"></span>
                                        <span class="ar-muted block text-xs" x-show="item.subtitle" x-text="item.subtitle"></span>
                                    </a>
                                </template>
                                <button
                                    type="button"
                                    class="ar-topbar-search-more"
                                    x-show="groupHasMore(key)"
                                    @click="goFull(key)"
                                    x-text="groupMoreLabel(key)"
                                ></button>
                            </div>
                        </template>
                        <button
                            type="button"
                            class="ar-topbar-search-more"
                            x-show="globalHasMore()"
                            @click="goFull()"
                            x-text="globalMoreLabel()"
                        ></button>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>

<script>
    function commandPalette(cfg) {
        return {
            open: false,
            mobileOpen: false,
            q: '',
            loading: false,
            results: null,
            meta: null,
            timer: null,
            activeIndex: -1,
            flat: [],
            endpoint: cfg.endpoint,
            groupOrder: cfg.groupOrder,
            labels: cfg.labels,
            previewLimit: 10,
            hasAny() {
                if (!this.results) return false;
                return this.groupOrder.some(k => this.results[k] && this.results[k].length);
            },
            visibleGroups() {
                if (!this.results) return [];
                return this.groupOrder.filter(k => this.results[k] && this.results[k].length);
            },
            groupTotal(key) {
                const t = this.meta?.totals?.[key];
                return typeof t === 'number' ? t : (this.results?.[key]?.length || 0);
            },
            groupHasMore(key) {
                if (this.meta?.has_more?.[key]) return true;
                const shown = this.results?.[key]?.length || 0;
                return this.groupTotal(key) > shown;
            },
            groupMoreLabel(key) {
                const total = this.groupTotal(key);
                return 'Ver todos los ' + total + ' resultados →';
            },
            globalTotal() {
                if (typeof this.meta?.total === 'number') return this.meta.total;
                if (!this.results) return 0;
                return this.groupOrder.reduce((sum, k) => sum + this.groupTotal(k), 0);
            },
            shownCount() {
                if (!this.results) return 0;
                return this.groupOrder.reduce((sum, k) => sum + (this.results[k]?.length || 0), 0);
            },
            globalHasMore() {
                return this.globalTotal() > this.shownCount();
            },
            globalMoreLabel() {
                const total = this.globalTotal();
                return 'Ver todos los ' + total + ' resultados →';
            },
            rebuildFlat() {
                this.flat = [];
                this.visibleGroups().forEach(key => {
                    (this.results[key] || []).forEach((item, idx) => {
                        this.flat.push({ key, idx, url: item.url });
                    });
                });
                if (this.activeIndex >= this.flat.length) this.activeIndex = this.flat.length - 1;
            },
            isActive(key, idx) {
                const cur = this.flat[this.activeIndex];
                return cur && cur.key === key && cur.idx === idx;
            },
            setActive(key, idx) {
                this.activeIndex = this.flat.findIndex(f => f.key === key && f.idx === idx);
            },
            move(delta) {
                if (!this.flat.length) return;
                if (this.activeIndex < 0) this.activeIndex = 0;
                else this.activeIndex = (this.activeIndex + delta + this.flat.length) % this.flat.length;
                this.open = true;
            },
            activateSelected() {
                if (this.activeIndex >= 0 && this.flat[this.activeIndex]) {
                    window.location.href = this.flat[this.activeIndex].url;
                    return;
                }
                this.goFull();
            },
            onInput() {
                clearTimeout(this.timer);
                const value = this.q.trim();
                if (value.length < 1) {
                    this.results = null;
                    this.meta = null;
                    this.open = false;
                    this.loading = false;
                    this.activeIndex = -1;
                    this.flat = [];
                    return;
                }
                this.timer = setTimeout(() => this.fetchResults(), 220);
            },
            async fetchResults() {
                const value = this.q.trim();
                if (value.length < 1) return;
                this.loading = true;
                try {
                    const url = this.endpoint + '?q=' + encodeURIComponent(value) + '&limit=' + this.previewLimit;
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
                    this.meta = data.meta || null;
                    this.open = true;
                    this.activeIndex = 0;
                    this.rebuildFlat();
                } catch (e) {
                    this.results = null;
                    this.meta = null;
                    this.open = false;
                } finally {
                    this.loading = false;
                }
            },
            goFull(type) {
                const value = this.q.trim();
                if (!value) return;
                let url = this.endpoint + '?q=' + encodeURIComponent(value);
                if (type && type !== 'all') {
                    url += '&type=' + encodeURIComponent(type);
                }
                window.location.href = url;
            },
            close() {
                this.open = false;
                this.mobileOpen = false;
            },
            onEscape() {
                if (this.open || this.mobileOpen) this.close();
            },
            openMobile() {
                this.mobileOpen = true;
                this.$nextTick(() => this.$refs.mobileInput?.focus());
            },
            focusPalette() {
                if (window.innerWidth < 1024) {
                    this.openMobile();
                    return;
                }
                this.$refs.desktopInput?.focus();
                if (this.results) this.open = true;
            },
            onGlobalKey(e) {
                if (!(e.ctrlKey || e.metaKey) || (e.key !== 'k' && e.key !== 'K')) return;
                const tag = (e.target && e.target.tagName || '').toLowerCase();
                const editable = e.target && (e.target.isContentEditable || tag === 'input' || tag === 'textarea' || tag === 'select');
                // No robar foco si ya se escribe en un formulario (salvo el propio palette).
                if (editable && !e.target.classList?.contains('ar-topbar-search-input')) return;
                e.preventDefault();
                this.focusPalette();
            }
        };
    }
</script>
