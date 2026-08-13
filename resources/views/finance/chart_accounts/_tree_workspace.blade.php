@php
    $derived = app(\App\Services\Finance\ChartAccountWorkspaceService::class)->derivedSummaryForCode((string) $node['code']);
    $display = $derived['display'] ?? 'amount';
    $total = $derived['total_ars'] ?? ($node['total_ars'] ?? '0.00');
    $mode = $node['amount_mode'] ?? \App\Support\UiSemantics::MODE_RESULT;
    $isSelected = (int) ($selectedId ?? 0) === (int) $node['id'];
    $pad = max(0, (int) $depth) * 12;
    $hasChildren = ! empty($node['children']);
    $flattenSearch = function (array $n) use (&$flattenSearch): string {
        $parts = [($n['code'] ?? '').' '.($n['name'] ?? '')];
        foreach ($n['children'] ?? [] as $child) {
            $parts[] = $flattenSearch($child);
        }

        return mb_strtolower(implode(' ', $parts));
    };
    $searchBlob = $flattenSearch($node);
@endphp
{{-- Roots must stay visible without Alpine: no x-cloak on nodes (defaults visible). --}}
<div class="mb-0.5 ar-chart-tree-node"
     data-account-id="{{ $node['id'] }}"
     x-data="{ openLocal: {{ $isSelected || $depth < 1 ? 'true' : 'false' }}, searchBlob: @js($searchBlob) }"
     x-show="!(treeQ || '').trim() || searchBlob.includes((treeQ || '').toLowerCase().trim())">
    <div class="flex items-center gap-1 rounded px-1 py-1 {{ $isSelected ? 'font-semibold ring-1' : 'hover:bg-black/5' }}"
         style="padding-left: {{ $pad }}px; {{ $isSelected ? 'background: var(--ar-surface-2, #f3f4f6); --tw-ring-color: var(--ar-border);' : '' }}">
        @if ($hasChildren)
            <button type="button"
                    class="ar-muted text-xs ar-chart-caret"
                    :class="openLocal ? 'is-open' : ''"
                    @click.stop="openLocal = !openLocal"
                    :aria-expanded="openLocal"
                    aria-label="Expandir o contraer">▸</button>
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
        <div x-show="openLocal || !!(treeQ && treeQ.length)">
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
