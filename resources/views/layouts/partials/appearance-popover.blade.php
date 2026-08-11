@php
    use App\Support\Appearance;
    $mode = Appearance::normalizeMode(auth()->user()->theme);
    $palette = auth()->user()->appearancePalette();
@endphp
<div
    class="relative"
    x-data="{
        open: false,
        mode: @js($mode),
        palette: @js($palette),
        applyPreview() {
            const root = document.documentElement;
            root.classList.toggle('dark', this.mode === 'dark');
            root.setAttribute('data-palette', this.palette);
            this.$refs.themeForm?.querySelectorAll('[data-appearance-choice]').forEach((el) => {
                const input = el.querySelector('input[type=radio]');
                if (! input) return;
                const selected = input.checked;
                el.classList.toggle('is-selected', selected);
                el.setAttribute('aria-checked', selected ? 'true' : 'false');
            });
        },
        selectMode(value) {
            this.mode = value;
            this.$nextTick(() => { this.applyPreview(); this.$refs.themeForm?.requestSubmit(); });
        },
        selectPalette(value) {
            this.palette = value;
            this.$nextTick(() => { this.applyPreview(); this.$refs.themeForm?.requestSubmit(); });
        },
        onChoiceKey(e, group, value) {
            const order = group === 'mode' ? ['light', 'dark'] : @js(Appearance::palettes());
            const idx = order.indexOf(value);
            if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                e.preventDefault();
                const next = order[(idx + 1) % order.length];
                group === 'mode' ? this.selectMode(next) : this.selectPalette(next);
            } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                e.preventDefault();
                const prev = order[(idx - 1 + order.length) % order.length];
                group === 'mode' ? this.selectMode(prev) : this.selectPalette(prev);
            } else if (e.key === ' ' || e.key === 'Enter') {
                e.preventDefault();
                group === 'mode' ? this.selectMode(value) : this.selectPalette(value);
            }
        }
    }"
    @keydown.escape.window="open = false"
    @click.outside="open = false"
>
    <button
        type="button"
        class="ar-btn ar-btn-secondary text-xs"
        @click="open = !open"
        :aria-expanded="open.toString()"
        aria-haspopup="dialog"
    >
        Apariencia
    </button>

    <div
        x-show="open"
        x-cloak
        class="ar-appearance-popover absolute right-0 z-50 mt-2 w-72 p-3"
        role="dialog"
        aria-label="Preferencias de apariencia"
    >
        <form
            method="POST"
            action="{{ route('theme.update') }}"
            class="space-y-3"
            x-ref="themeForm"
            @submit.prevent="
                fetch($el.action, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': $el.querySelector('[name=_token]').value
                    },
                    body: new FormData($el)
                }).then(() => {}).catch(() => $el.submit());
            "
        >
            @csrf
            <div>
                <p class="ar-label mb-2" id="appearance-mode-label">Modo</p>
                <div class="flex gap-2" role="radiogroup" aria-labelledby="appearance-mode-label">
                    <label
                        data-appearance-choice
                        class="ar-appearance-choice"
                        :class="{ 'is-selected': mode === 'light' }"
                        role="radio"
                        :aria-checked="(mode === 'light').toString()"
                        tabindex="0"
                        @keydown="onChoiceKey($event, 'mode', 'light')"
                        @click.prevent="selectMode('light')"
                    >
                        <input type="radio" name="theme" value="{{ Appearance::MODE_LIGHT }}" class="sr-only" x-model="mode" :checked="mode === 'light'">
                        Claro
                    </label>
                    <label
                        data-appearance-choice
                        class="ar-appearance-choice"
                        :class="{ 'is-selected': mode === 'dark' }"
                        role="radio"
                        :aria-checked="(mode === 'dark').toString()"
                        tabindex="0"
                        @keydown="onChoiceKey($event, 'mode', 'dark')"
                        @click.prevent="selectMode('dark')"
                    >
                        <input type="radio" name="theme" value="{{ Appearance::MODE_DARK }}" class="sr-only" x-model="mode" :checked="mode === 'dark'">
                        Oscuro
                    </label>
                </div>
            </div>

            <div>
                <p class="ar-label mb-2" id="appearance-palette-label">Paleta</p>
                <div class="grid grid-cols-2 gap-2" role="radiogroup" aria-labelledby="appearance-palette-label">
                    @foreach (Appearance::palettes() as $key)
                        <label
                            data-appearance-choice
                            class="ar-appearance-choice"
                            :class="{ 'is-selected': palette === @js($key) }"
                            role="radio"
                            :aria-checked="(palette === @js($key)).toString()"
                            tabindex="0"
                            @keydown="onChoiceKey($event, 'palette', @js($key))"
                            @click.prevent="selectPalette(@js($key))"
                        >
                            <input type="radio" name="palette" value="{{ $key }}" class="sr-only" x-model="palette" :checked="palette === @js($key)">
                            {{ Appearance::paletteLabel($key) }}
                        </label>
                    @endforeach
                </div>
            </div>

            <p class="ar-muted text-xs">La vista previa se aplica al instante y se guarda al elegir.</p>
        </form>
    </div>
</div>
