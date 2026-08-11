@props(['topic', 'placement' => 'modal'])

@php
    $help = config('help.'.$topic);
    $side = $placement === 'side';
@endphp

@if (is_array($help))
    <div
        class="inline-flex"
        x-data="{ open: false }"
        @keydown.escape.window="open = false"
    >
        <button
            type="button"
            class="ar-btn ar-btn-secondary text-xs"
            @click="open = true"
            aria-haspopup="dialog"
            :aria-expanded="open.toString()"
        >
            ? Ayuda
        </button>

        <div
            x-show="open"
            x-cloak
            class="fixed inset-0 z-50 {{ $side ? 'flex justify-end bg-black/40' : 'flex items-end justify-center bg-black/40 p-4 sm:items-center' }}"
            @click.self="open = false"
            role="dialog"
            aria-modal="true"
            aria-label="Ayuda de {{ $help['title'] ?? $topic }}"
        >
            <div class="ar-card {{ $side ? 'h-full max-h-full w-full max-w-md rounded-none border-y-0 border-e-0' : 'max-h-[85vh] w-full max-w-lg' }} overflow-y-auto p-5 shadow-lg" @click.stop>
                <div class="mb-3 flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">{{ $help['title'] ?? 'Ayuda' }}</h2>
                        @if (!empty($help['summary']))
                            <p class="ar-muted mt-1 text-sm">{{ $help['summary'] }}</p>
                        @endif
                    </div>
                    <button type="button" class="ar-btn ar-btn-secondary text-xs" @click="open = false">Cerrar</button>
                </div>

                @if (!empty($help['bullets']) && is_array($help['bullets']))
                    <ul class="mb-3 list-disc space-y-1 ps-5 text-sm">
                        @foreach ($help['bullets'] as $bullet)
                            <li>{{ $bullet }}</li>
                        @endforeach
                    </ul>
                @endif

                @if (!empty($help['flow']))
                    <p class="rounded-md border border-[var(--ar-border)] bg-[var(--ar-surface-2)] p-3 text-sm">
                        <span class="font-semibold">Flujo típico:</span> {{ $help['flow'] }}
                    </p>
                @endif
            </div>
        </div>
    </div>
@endif
