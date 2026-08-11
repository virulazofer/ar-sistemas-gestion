@php
    $pad = max(0, (int) $depth) * 16;
@endphp
<div class="rounded border p-2 text-sm" style="border-color: var(--ar-border); margin-inline-start: {{ $pad }}px;">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <button type="button" class="text-start font-medium" @click="open[{{ $node['id'] }}] = !open[{{ $node['id'] }}]">
            {{ $node['code'] }} — {{ $node['name'] }}
            <span class="ar-muted text-xs">({{ $node['type_label'] }})</span>
        </button>
        <span class="tabular-nums">
            {{ number_format((float) $node['total_ars'], 2, ',', '.') }} ARS
            · {{ $node['count'] }} movs
        </span>
    </div>
    <div x-show="open[{{ $node['id'] }}]" x-cloak class="mt-2 space-y-1">
        <p class="ar-muted text-xs">Propio: {{ number_format((float) $node['own_ars'], 2, ',', '.') }} ARS ({{ $node['own_count'] }})</p>
        @foreach ($node['children'] as $child)
            @include('finance.chart_accounts._tree_totals', ['node' => $child, 'depth' => $depth + 1])
        @endforeach
    </div>
</div>
