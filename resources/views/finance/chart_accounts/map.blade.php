<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">Mapa del plan de cuentas</h1>
            <a href="{{ route('chart-accounts.index') }}" class="ar-btn ar-btn-secondary">Volver al listado</a>
        </div>
    </x-slot>

    <div class="ar-card p-5" x-data="{ open: {} }">
        <p class="ar-muted mb-4 text-sm">Árbol interactivo generado desde la base de datos.</p>
        <ul class="space-y-2">
            @foreach ($roots as $root)
                <li>
                    <button type="button" class="font-semibold" @click="open[{{ $root->id }}] = !open[{{ $root->id }}]"
                        :aria-expanded="(!!open[{{ $root->id }}]).toString()">
                        {{ $root->code }} — {{ $root->name }}
                        <span class="ar-muted text-xs">({{ $root->children->count() }} hijas)</span>
                    </button>
                    <ul class="ms-4 mt-1 space-y-1 border-s ps-3" style="border-color: var(--ar-border);" x-show="open[{{ $root->id }}] !== false" x-cloak>
                        @foreach ($root->children as $child)
                            <li>
                                <span>{{ $child->code }} — {{ $child->name }}</span>
                                @if ($child->children->isNotEmpty())
                                    <ul class="ms-3 list-disc ps-4 text-sm">
                                        @foreach ($child->children as $g)
                                            <li>{{ $g->code }} — {{ $g->name }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </li>
            @endforeach
        </ul>
    </div>
</x-app-layout>
