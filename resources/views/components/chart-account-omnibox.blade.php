@props([
    'concepts' => [],
    'recent' => [],
    'frequent' => [],
    'inputName' => 'chart_account_id',
    'required' => false,
    'typeModel' => 'type',
    'initialId' => '',
    'initialQuery' => '',
    'limitHint' => 40,
])

@php
    $omniboxId = 'chart-omnibox-'.uniqid();
@endphp

<div
    class="space-y-1"
    x-data="chartAccountOmnibox({
        concepts: {{ \Illuminate\Support\Js::from($concepts) }},
        recent: {{ \Illuminate\Support\Js::from($recent) }},
        frequent: {{ \Illuminate\Support\Js::from($frequent) }},
        chartAccountId: @js((string) $initialId),
        conceptQuery: @js((string) $initialQuery),
        typeModelName: @js($typeModel),
        limitHint: {{ (int) $limitHint }},
        required: {{ $required ? 'true' : 'false' }},
    })"
    @chart-account-selected.window="onExternalSelect($event.detail)"
>
    <label class="ar-label" :for="'{{ $omniboxId }}-input'">Cuenta contable</label>
    <div class="relative">
        <input type="hidden" name="{{ $inputName }}" x-model="chartAccountId" @if($required) :required="isMandatory" @endif>
        <input
            id="{{ $omniboxId }}-input"
            type="text"
            class="ar-input"
            role="combobox"
            aria-autocomplete="list"
            :aria-expanded="open.toString()"
            aria-controls="{{ $omniboxId }}-list"
            :aria-activedescendant="activeId"
            autocomplete="off"
            placeholder="Buscar por nombre, ruta o código…"
            x-model="conceptQuery"
            @focus="open = true; ensureGroups()"
            @input="onQueryInput()"
            @keydown.arrow-down.prevent="move(1)"
            @keydown.arrow-up.prevent="move(-1)"
            @keydown.enter.prevent="selectActive()"
            @keydown.escape.prevent="close()"
            @keydown.tab="close()"
            @click.outside="close()"
        >
        <ul
            id="{{ $omniboxId }}-list"
            role="listbox"
            class="absolute z-30 mt-1 max-h-64 w-full overflow-auto rounded border text-sm"
            style="background: var(--ar-surface, #fff); border-color: var(--ar-border);"
            x-show="open && flatOptions.length"
            x-cloak
        >
            <template x-for="(opt, idx) in flatOptions" :key="opt.key">
                <li
                    :id="'{{ $omniboxId }}-opt-' + idx"
                    role="option"
                    :aria-selected="(idx === activeIndex).toString()"
                    class="cursor-pointer px-3 py-2"
                    :style="idx === activeIndex ? 'background: var(--ar-surface-2, #f3f4f6);' : ''"
                    @mousedown.prevent="selectOption(opt)"
                    @mouseenter="activeIndex = idx"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span x-text="opt.path || opt.name"></span>
                        <span class="ar-muted text-xs" x-show="opt.badge" x-text="opt.badge"></span>
                    </div>
                    <div class="ar-muted text-xs" x-text="opt.code"></div>
                </li>
            </template>
        </ul>
    </div>
    <p class="ar-muted text-xs" x-show="selectedConcept" x-text="'Seleccionada: ' + (selectedConcept?.path || '')"></p>
    <p class="ar-muted text-xs" x-show="truncated" x-text="'Mostrando ' + limitHint + ' de ' + matchCount + ' resultados. Seguí tipando para acotar.'"></p>
    <p class="text-xs" style="color: var(--ar-danger);" x-show="showRequiredError" x-text="requiredError"></p>
</div>

@once
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('chartAccountOmnibox', (cfg) => ({
        concepts: cfg.concepts || [],
        recent: cfg.recent || [],
        frequent: cfg.frequent || [],
        chartAccountId: cfg.chartAccountId || '',
        conceptQuery: cfg.conceptQuery || '',
        typeModelName: cfg.typeModelName || 'type',
        limitHint: cfg.limitHint || 40,
        required: !!cfg.required,
        open: false,
        activeIndex: 0,
        truncated: false,
        matchCount: 0,
        showRequiredError: false,
        requiredError: 'La cuenta contable es obligatoria.',
        get type() {
            try {
                return Alpine.$data(this.$root.closest('[x-data]') || this.$el)?.[this.typeModelName]
                    ?? this.$root?.[this.typeModelName]
                    ?? 'expense'
            } catch (e) {
                return 'expense'
            }
        },
        get movementType() {
            // Busca el x-model type en el formulario padre Alpine
            let el = this.$el.parentElement
            while (el) {
                if (el._x_dataStack) {
                    for (const d of el._x_dataStack) {
                        if (d && typeof d[this.typeModelName] !== 'undefined') {
                            return d[this.typeModelName]
                        }
                    }
                }
                el = el.parentElement
            }
            return 'expense'
        },
        get isMandatory() {
            const t = this.movementType
            return this.required && t !== 'transfer'
        },
        get selectedConcept() {
            return this.concepts.find(c => String(c.id) === String(this.chartAccountId)) || null
        },
        get activeId() {
            return this.open && this.flatOptions.length
                ? `{{ $omniboxId }}-opt-${this.activeIndex}`
                : null
        },
        filteredByType(list) {
            const t = this.movementType === 'income' ? 'income' : 'expense'
            return (list || []).filter(c => c.type === t || this.movementType === 'transfer')
        },
        get matches() {
            const t = this.movementType === 'income' ? 'income' : 'expense'
            const q = (this.conceptQuery || '').toLowerCase().trim()
            return this.concepts
                .filter(c => this.movementType === 'transfer' ? true : c.type === t)
                .filter(c => {
                    if (!q) return true
                    return (c.path || '').toLowerCase().includes(q)
                        || (c.name || '').toLowerCase().includes(q)
                        || String(c.code || '').toLowerCase().includes(q)
                })
        },
        get flatOptions() {
            const t = this.movementType === 'income' ? 'income' : 'expense'
            const q = (this.conceptQuery || '').toLowerCase().trim()
            const opts = []
            const seen = new Set()
            const pushGroup = (items, badge) => {
                for (const c of items) {
                    if (this.movementType !== 'transfer' && c.type !== t) continue
                    if (q && !(
                        (c.path || '').toLowerCase().includes(q)
                        || (c.name || '').toLowerCase().includes(q)
                        || String(c.code || '').toLowerCase().includes(q)
                    )) continue
                    const id = String(c.id)
                    if (seen.has(id)) continue
                    seen.add(id)
                    opts.push({ ...c, badge, key: badge + '-' + id })
                }
            }
            if (!q) {
                pushGroup(this.recent, 'Reciente')
                pushGroup(this.frequent, 'Frecuente')
            }
            const all = this.matches
            this.matchCount = all.length
            const limited = all.slice(0, this.limitHint)
            this.truncated = all.length > this.limitHint
            pushGroup(limited, q ? 'Resultado' : 'Catálogo')
            return opts
        },
        ensureGroups() {
            if (this.selectedConcept && !this.conceptQuery) {
                this.conceptQuery = this.selectedConcept.path || this.selectedConcept.name || ''
            }
        },
        onQueryInput() {
            this.open = true
            this.activeIndex = 0
            if (this.selectedConcept && this.conceptQuery !== this.selectedConcept.path) {
                // tipando de nuevo: no borrar id hasta elegir otra
            }
        },
        move(delta) {
            if (!this.flatOptions.length) return
            this.open = true
            this.activeIndex = (this.activeIndex + delta + this.flatOptions.length) % this.flatOptions.length
        },
        selectActive() {
            const opt = this.flatOptions[this.activeIndex]
            if (opt) this.selectOption(opt)
        },
        selectOption(opt) {
            this.chartAccountId = String(opt.id)
            this.conceptQuery = opt.path || opt.name || ''
            this.open = false
            this.showRequiredError = false
            this.$dispatch('chart-account-picked', { id: opt.id, concept: opt })
        },
        onExternalSelect(detail) {
            if (!detail) return
            if (detail.id) {
                this.chartAccountId = String(detail.id)
                const c = this.concepts.find(x => String(x.id) === String(detail.id))
                if (c) this.conceptQuery = c.path || c.name || ''
            }
        },
        close() {
            this.open = false
            if (this.isMandatory && !this.chartAccountId) {
                this.showRequiredError = true
            }
        },
        validate() {
            if (this.isMandatory && !this.chartAccountId) {
                this.showRequiredError = true
                return false
            }
            return true
        }
    }))
})
</script>
@endonce
