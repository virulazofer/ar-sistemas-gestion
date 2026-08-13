<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <h1 class="text-xl font-semibold">Categorías y subcategorías</h1>
                <x-page-help topic="categories" />
            </div>
            <a href="{{ route('chart-accounts.mapping') }}" class="ar-btn ar-btn-secondary text-xs">Asignación al plan de cuentas</a>
        </div>
    </x-slot>

    <form method="GET" class="ar-card mb-4 flex flex-wrap items-end gap-3 p-4">
        <div>
            <label class="ar-label">Desde</label>
            <input type="date" name="from" class="ar-input" value="{{ $from->format('Y-m-d') }}">
        </div>
        <div>
            <label class="ar-label">Hasta</label>
            <input type="date" name="to" class="ar-input" value="{{ $to->format('Y-m-d') }}">
        </div>
        <button class="ar-btn ar-btn-secondary">Filtrar analítica</button>
    </form>

    <div class="mb-6 grid gap-4 lg:grid-cols-2">
        @can('categories.create')
            <form method="POST" action="{{ route('categories.store') }}" class="ar-card space-y-3 p-5">
                @csrf
                <h2 class="font-semibold">Nueva categoría</h2>
                <input name="name" class="ar-input" placeholder="Nombre" required>
                <select name="scope" class="ar-input">
                    <option value="personal">Personal</option>
                    <option value="professional">Profesional</option>
                    <option value="both">Ambos</option>
                </select>
                <p class="ar-muted text-xs">La cuenta del plan se asigna en <a href="{{ route('chart-accounts.mapping') }}" class="underline">Asignación al plan</a> (no acá).</p>
                <button class="ar-btn ar-btn-primary">Crear categoría</button>
            </form>

            <form method="POST" action="{{ route('subcategories.store') }}" class="ar-card space-y-3 p-5">
                @csrf
                <h2 class="font-semibold">Nueva subcategoría</h2>
                <select name="category_id" class="ar-input" required>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }} ({{ match($category->scope) { 'personal' => 'Personal', 'professional' => 'Profesional', 'both' => 'Ambos', default => $category->scope } }})</option>
                    @endforeach
                </select>
                <input name="name" class="ar-input" placeholder="Nombre" required>
                <p class="ar-muted text-xs">Asignación al plan: solo en la herramienta de asignación.</p>
                <button class="ar-btn ar-btn-primary">Crear subcategoría</button>
            </form>
        @endcan
    </div>

    <div class="space-y-3" x-data="{ open: {} }">
        @foreach ($categories as $category)
            @php
                $ct = $catTotals->get($category->id);
                $totalArs = (float) ($ct->total_ars ?? 0);
                $cnt = (int) ($ct->cnt ?? 0);
            @endphp
            <div class="ar-card p-4">
                <button
                    type="button"
                    class="flex w-full flex-wrap items-center justify-between gap-2 text-start"
                    @click="open[{{ $category->id }}] = !open[{{ $category->id }}]"
                    :aria-expanded="(!!open[{{ $category->id }}]).toString()"
                >
                    <span>
                        <span class="font-semibold">{{ $category->name }}</span>
                        <span class="ar-muted text-sm">({{ match($category->scope) { 'personal' => 'Personal', 'professional' => 'Profesional', 'both' => 'Ambos', default => $category->scope } }})</span>
                    </span>
                    <span class="text-sm tabular-nums">{{ number_format($totalArs, 2, ',', '.') }} ARS · {{ $cnt }} movs</span>
                </button>
                <p class="ar-muted mt-1 text-xs">Plan: {{ $category->chartAccount?->code }} {{ $category->chartAccount?->name ?: '—' }}</p>

                <div x-show="open[{{ $category->id }}]" x-cloak class="mt-3 space-y-2 border-t pt-3" style="border-color: var(--ar-border);">
                    <a href="{{ route('categories.show', ['category' => $category, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]) }}" class="ar-btn ar-btn-secondary text-xs">Ver detalle analítico</a>
                    <ul class="space-y-1 text-sm">
                        @forelse ($category->subcategories as $sub)
                            @php
                                $st = $subTotals->get($sub->id);
                            @endphp
                            <li class="flex flex-wrap items-center justify-between gap-2">
                                <a href="{{ route('subcategories.show', ['subcategory' => $sub, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]) }}" style="color: var(--ar-brand);">{{ $sub->name }}</a>
                                <span class="ar-muted tabular-nums">{{ number_format((float) ($st->total_ars ?? 0), 2, ',', '.') }} ARS · {{ (int) ($st->cnt ?? 0) }}</span>
                            </li>
                        @empty
                            <li class="ar-muted">Sin subcategorías</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        @endforeach
    </div>

    @can('categories.edit')
        <div class="mt-8 grid gap-4 lg:grid-cols-2">
            <form method="POST" action="{{ route('categories.merge.preview') }}" class="ar-card space-y-3 p-4">
                @csrf
                <h2 class="font-semibold">Fusionar categorías (con preview)</h2>
                <select name="source_id" class="ar-input" required>
                    <option value="">Origen</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
                <select name="target_id" class="ar-input" required>
                    <option value="">Destino</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
                <button class="ar-btn ar-btn-secondary">Vista previa fusión</button>
            </form>
            @if (session('category_merge_preview'))
                <div class="ar-card space-y-2 p-4">
                    <p class="font-semibold">Afectará {{ session('category_merge_preview')['movements'] }} movimientos</p>
                    <p class="text-sm">{{ session('category_merge_preview')['source'] }} → {{ session('category_merge_preview')['target'] }}</p>
                    <form method="POST" action="{{ route('categories.merge.apply') }}">
                        @csrf
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required> Confirmo fusión</label>
                        <button class="ar-btn ar-btn-primary mt-2">Aplicar fusión</button>
                    </form>
                </div>
            @endif
        </div>
    @endcan
</x-app-layout>
