@php
    use App\Support\Appearance;
    $mode = Appearance::normalizeMode(auth()->user()->theme);
    $palette = auth()->user()->appearancePalette();
@endphp
<div
    class="relative"
    x-data="{ open: false }"
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
        <form method="POST" action="{{ route('theme.update') }}" class="space-y-3">
            @csrf
            <div>
                <p class="ar-label mb-2">Modo</p>
                <div class="flex gap-2">
                    <label class="ar-appearance-choice {{ $mode === Appearance::MODE_LIGHT ? 'is-selected' : '' }}">
                        <input type="radio" name="theme" value="{{ Appearance::MODE_LIGHT }}" class="sr-only" @checked($mode === Appearance::MODE_LIGHT)>
                        Claro
                    </label>
                    <label class="ar-appearance-choice {{ $mode === Appearance::MODE_DARK ? 'is-selected' : '' }}">
                        <input type="radio" name="theme" value="{{ Appearance::MODE_DARK }}" class="sr-only" @checked($mode === Appearance::MODE_DARK)>
                        Oscuro
                    </label>
                </div>
            </div>

            <div>
                <p class="ar-label mb-2">Paleta</p>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (Appearance::palettes() as $key)
                        <label class="ar-appearance-choice {{ $palette === $key ? 'is-selected' : '' }}">
                            <input type="radio" name="palette" value="{{ $key }}" class="sr-only" @checked($palette === $key)>
                            {{ Appearance::paletteLabel($key) }}
                        </label>
                    @endforeach
                </div>
            </div>

            <button type="submit" class="ar-btn ar-btn-primary w-full text-xs">Aplicar</button>
        </form>
    </div>
</div>
