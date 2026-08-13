@php
    $pending = (int) ($pendingClassify ?? ($progress['pending'] ?? 0));
@endphp
<div class="flex flex-wrap gap-2">
    <a href="{{ route('chart-accounts.index') }}" class="ar-btn ar-btn-secondary text-xs">Ver plan</a>
    <a href="{{ route('chart-accounts.classify') }}" class="ar-btn ar-btn-secondary text-xs">
        @if ($pending > 0)
            Pendientes de clasificación ({{ $pending }})
        @else
            Pendientes de clasificación
        @endif
    </a>
    <a href="{{ route('chart-accounts.mapping') }}" class="ar-btn ar-btn-secondary text-xs">Asignación al plan</a>
    <a href="{{ route('imputation-rules.index') }}" class="ar-btn ar-btn-secondary text-xs">Reglas automáticas</a>
</div>
