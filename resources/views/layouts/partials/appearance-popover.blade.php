@php
    use App\Support\Appearance;
    $mode = Appearance::normalizeMode(auth()->user()->theme);
    $palette = auth()->user()->appearancePalette();
    $appearanceInit = [
        'mode' => $mode,
        'palette' => $palette,
        'palettes' => Appearance::palettes(),
    ];
@endphp
{{-- Atributo con comillas simples: @js() usa " y no rompe el HTML/Alpine. open siempre false (no se persiste). --}}
<div
    class="relative"
    x-data='appearancePopover(@js($appearanceInit))'
    @keydown.escape.window="close()"
    @click.outside="close()"
>
    <button
        type="button"
        class="ar-btn ar-btn-secondary text-xs"
        @click="toggle()"
        :aria-expanded="open.toString()"
        aria-haspopup="dialog"
    >
        Apariencia
    </button>

    <div
        x-show="open"
        x-cloak
        style="display: none;"
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
