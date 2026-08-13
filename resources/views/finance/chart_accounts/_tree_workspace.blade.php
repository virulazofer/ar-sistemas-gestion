@php
    $derived = app(\App\Services\Finance\ChartAccountWorkspaceService::class)->derivedSummaryForCode((string) $node['code']);
    $display = $derived['display'] ?? 'amount';
    $total = $derived['total_ars'] ?? ($node['total_ars'] ?? '0.00');
    $mode = $node['amount_mode'] ?? \App\Support\UiSemantics::MODE_RESULT;
    $isSelected = (int) ($selectedId ?? 0) === (int) $node['id'];
    $pad = max(0, (int) $depth) * 12;
    $hasChildren = ! empty($node['children']);
    $nodeJson = [
        'code' => $node['code'],
        'name' => $node['name'],
        'path' => ($node['code'] ?? '').' '.$node['name'],
        'children' => collect($node['children'] ?? [])->map(fn ($c) => [
            'code' => $c['code'], 'name' => $c['name'], 'path' => $c['code'].' '.$c['name'],
            'children' => $c['children'] ?? [],
        ])->all(),
    ];
@endphp
<div class="mb-0.5"
     x-data="{ node: @js($nodeJson), openLocal: {{ $isSelected || $depth < 1 ? 'true' : 'false' }} }"
     x-show="matches(node)"
     x-cloak>
    <div class="flex items-center gap-1 rounded px-1 py-1 {{ $isSelected ? 'font-semibold ring-1' : 'hover:bg-black/5' }}"
         style="padding-left: {{ $pad }}px; {{ $isSelected ? 'background: var(--ar-surface-2, #f3f4f6); --tw-ring-color: var(--ar-border);' : '' }}">
        @if ($hasChildren)
            <button type="button" class="ar-muted text-xs w-4 shrink-0" @click="openLocal = !openLocal" :aria-expanded="openLocal">▸</button>
        @else
            <span class="w-4 shrink-0"></span>
        @endif
        <a href="{{ route('chart-accounts.index', array_merge($qs ?? [], ['account' => $node['id']])) }}"
           class="flex-1 min-w-0 truncate hover:underline">
            <span class="ar-muted">{{ $node['code'] }}</span> {{ $node['name'] }}
        </a>
        <span class="tabular-nums text-xs shrink-0 {{ $display === 'amount' ? \App\Support\UiSemantics::cssClass((string)$total, $mode) : 'ar-muted' }}">
            @if ($display !== 'amount')
                —
            @else
                ${{ number_format((float) $total, 0, ',', '.') }}
            @endif
        </span>
    </div>
    @if ($hasChildren)
        <div x-show="openLocal || (treeQ && treeQ.length > 0)">
            @foreach ($node['children'] as $child)
                @include('finance.chart_accounts._tree_workspace', [
                    'node' => $child,
                    'selectedId' => $selectedId,
                    'depth' => $depth + 1,
                    'qs' => $qs,
                ])
            @endforeach
        </div>
    @endif
</div>
