@php
    $isSelected = (int) $selectedId === (int) $account->id;
    $pad = max(0, (int) $depth) * 12;
    $hasChildren = $account->children && $account->children->isNotEmpty();
@endphp
<div class="mb-0.5" x-data="{ open: {{ $isSelected || $depth < 1 ? 'true' : 'false' }} }">
    <div class="flex items-center gap-1 rounded px-1 py-0.5 {{ $isSelected ? 'font-semibold' : '' }}"
         style="padding-left: {{ $pad }}px; {{ $isSelected ? 'background: var(--ar-surface-2, transparent);' : '' }}">
        @if ($hasChildren)
            <button type="button" class="ar-muted text-xs w-4" @click="open = !open" :aria-expanded="open">▸</button>
        @else
            <span class="w-4"></span>
        @endif
        <a href="{{ route('chart-accounts.index', array_filter(['account' => $account->id, 'from' => $dateFrom ?? null, 'to' => $dateTo ?? null, 'scope' => $scope ?? null])) }}"
           class="hover:underline truncate">
            <span class="ar-muted">{{ $account->code }}</span> {{ $account->name }}
        </a>
    </div>
    @if ($hasChildren)
        <div x-show="open" x-cloak>
            @foreach ($account->children as $child)
                @include('finance.chart_accounts._tree_nav', ['account' => $child, 'selectedId' => $selectedId, 'depth' => $depth + 1])
            @endforeach
        </div>
    @endif
</div>
