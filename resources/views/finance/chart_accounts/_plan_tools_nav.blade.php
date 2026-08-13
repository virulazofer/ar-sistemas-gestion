@php
    $pending = (int) ($pendingClassify ?? ($progress['pending'] ?? 0));
@endphp
<div class="flex flex-wrap gap-2">
    <a href="{{ route('chart-accounts.index') }}" class="ar-btn ar-btn-secondary text-xs">Plan de cuentas</a>
    <a href="{{ route('accounts.index') }}" class="ar-btn ar-btn-secondary text-xs">Cuentas financieras</a>
    @if ($pending > 0)
        <a href="{{ route('chart-accounts.classify') }}" class="ar-btn ar-btn-secondary text-xs" style="color: var(--ar-danger);">
            Pendientes de clasificación ({{ $pending }})
        </a>
    @endif
    @can('categories.edit')
        <a href="{{ route('chart-accounts.advanced') }}" class="ar-btn ar-btn-secondary text-xs">Configuración avanzada</a>
    @endcan
</div>
