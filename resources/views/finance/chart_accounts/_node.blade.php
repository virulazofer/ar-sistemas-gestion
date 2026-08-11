@php $pad = $depth * 1.25; @endphp
<div class="ar-card p-3" style="margin-left: {{ $pad }}rem;">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <span class="font-semibold">{{ $account->code }}</span>
            — {{ $account->name }}
            <span class="ar-muted text-xs">({{ $account->typeLabel() }}{{ $account->is_active ? '' : ' · inactiva' }})</span>
        </div>
        <div class="flex gap-2 text-sm">
            @can('categories.create')
                <a href="{{ route('chart-accounts.create', ['parent_id' => $account->id]) }}" style="color: var(--ar-brand);">+ Hija</a>
            @endcan
            @can('categories.edit')
                <a href="{{ route('chart-accounts.edit', $account) }}" style="color: var(--ar-brand);">Editar</a>
            @endcan
        </div>
    </div>
</div>
@foreach ($account->children as $child)
    @include('finance.chart_accounts._node', ['account' => $child, 'depth' => $depth + 1])
@endforeach
