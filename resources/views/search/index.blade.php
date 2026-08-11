<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-semibold">Resultados de búsqueda</h1>
            @if ($q !== '')
                <p class="ar-muted text-sm">
                    {{ $total }} {{ $total === 1 ? 'resultado' : 'resultados' }}
                    para <strong>«{{ $q }}»</strong>
                    @if ($type !== 'all')
                        · filtro {{ $groupLabels[$type] ?? $type }}
                    @endif
                </p>
            @else
                <p class="ar-muted text-sm">Escribí un término para buscar en navegación, acciones y entidades.</p>
            @endif
        </div>
    </x-slot>

    <form method="GET" action="{{ route('search') }}" class="ar-card mb-4 flex flex-wrap gap-2 p-4">
        <input name="q" class="ar-input min-w-[12rem] flex-1" value="{{ $q }}" placeholder="Navegación, acciones, cliente, producto, OT…" autofocus>
        <input type="hidden" name="type" value="{{ $type }}">
        <button class="ar-btn ar-btn-primary">Buscar</button>
    </form>

    @if ($q !== '')
        <div class="mb-4 flex flex-wrap gap-2">
            @php
                $filterTabs = ['all' => 'Todos'] + $groupLabels;
            @endphp
            @foreach ($filterTabs as $key => $label)
                @php
                    $count = $key === 'all' ? array_sum($totals) : (int) ($totals[$key] ?? 0);
                    $active = $type === $key;
                    $href = route('search', array_filter([
                        'q' => $q,
                        'type' => $key === 'all' ? null : $key,
                    ]));
                @endphp
                @if ($key === 'all' || $count > 0 || $active)
                    <a
                        href="{{ $href }}"
                        class="ar-btn text-xs {{ $active ? 'ar-btn-primary' : 'ar-btn-secondary' }}"
                    >
                        {{ $label }}@if ($key !== 'all' || $count > 0) ({{ $count }})@endif
                    </a>
                @endif
            @endforeach
        </div>
    @endif

    @if ($q !== '' && $total > 0)
        @foreach ($groupOrder as $group)
            @php $sectionItems = $groups[$group] ?? []; @endphp
            @if (count($sectionItems))
                <div class="ar-card mb-4 p-4">
                    <h2 class="mb-2 font-semibold">
                        {{ $groupLabels[$group] ?? $group }}
                        <span class="ar-muted text-sm font-normal">({{ (int) ($totals[$group] ?? 0) }})</span>
                    </h2>
                    <ul class="space-y-2 text-sm">
                        @foreach ($sectionItems as $item)
                            <li>
                                <a href="{{ $item['url'] ?? route($item['route'], $item['params']) }}" style="color: var(--ar-brand);">{{ $item['label'] }}</a>
                                @if (!empty($item['subtitle']))
                                    <span class="ar-muted"> — {{ $item['subtitle'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endforeach

        @if ($paginator->hasPages())
            <div class="mt-4">{{ $paginator->links() }}</div>
        @endif
    @elseif ($q !== '')
        <p class="ar-muted">No se encontraron resultados</p>
    @endif
</x-app-layout>
