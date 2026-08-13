@php
    $derived = $detail['derived'] ?? [];
    $display = $derived['display'] ?? 'amount';
    $mode = $detail['amount_mode'] ?? \App\Support\UiSemantics::MODE_RESULT;
    $periodLabel = $period['label'] ?? 'período';
@endphp

<div class="flex flex-wrap items-start justify-between gap-3">
    <div>
        <p class="ar-muted text-xs">{{ $selected->code }} · {{ $selected->typeLabel() }}</p>
        <h2 class="text-lg font-semibold">{{ $selected->name }}</h2>
        <p class="ar-muted text-sm">{{ $detail['path'] }}</p>
        @if (!empty($detail['includes_descendants']))
            <p class="mt-1 text-xs rounded inline-block px-2 py-0.5" style="background: var(--ar-surface-2, #f3f4f6);">
                Total incluye subcuentas
            </p>
        @endif
        @if (!empty($derived['help']) || $selected->help_text)
            <p class="mt-2 text-sm">
                <span class="font-medium">¿Qué significa?</span>
                {{ $derived['help'] ?? $selected->help_text }}
            </p>
        @endif
    </div>
    <div class="flex flex-wrap gap-2">
        @unless ($selected->isProtectedRoot())
            @can('categories.edit')
                <a href="{{ route('chart-accounts.edit', $selected) }}" class="ar-btn ar-btn-secondary text-xs">Editar</a>
            @endcan
        @endunless
        @can('categories.create')
            <a href="{{ route('chart-accounts.create', ['parent_id' => $selected->id]) }}" class="ar-btn ar-btn-secondary text-xs">+ Subcuenta</a>
        @endcan
    </div>
</div>

<div class="mt-4 grid gap-3 sm:grid-cols-3 text-sm">
    <div>
        <p class="ar-muted text-xs">Total período</p>
        @if ($display !== 'amount')
            <p class="font-semibold ar-muted">{{ $derived['display_label'] ?? 'Sin datos suficientes' }}</p>
        @else
            @php
                $showMode = ($derived['kind'] ?? '') === 'clients_cc' ? \App\Support\UiSemantics::MODE_CLIENT_CC : $mode;
            @endphp
            <p class="font-semibold tabular-nums text-lg {{ \App\Support\UiSemantics::cssClass((string)$detail['total_ars'], $showMode) }}">
                ${{ number_format((float) $detail['total_ars'], 2, ',', '.') }}
            </p>
        @endif
    </div>
    <div>
        <p class="ar-muted text-xs">Movimientos</p>
        <p class="font-semibold tabular-nums">{{ number_format($detail['count']) }}</p>
    </div>
    <div>
        <p class="ar-muted text-xs">Estado</p>
        <p class="font-semibold">{{ $selected->is_active ? 'Activa' : 'Inactiva' }}{{ $selected->is_protected ? ' · raíz protegida' : '' }}</p>
    </div>
</div>

{{-- Clientes CC ranking --}}
@if (($derived['kind'] ?? '') === 'clients_cc')
    <div class="mt-4">
        <h3 class="font-semibold text-sm mb-2">Total a cobrar · ranking</h3>
        <p class="ar-muted text-xs mb-2">Rojo = nos deben. Click → ficha / CC del cliente.</p>
        @forelse (($derived['ranking'] ?? []) as $row)
            @if (($row['currency'] ?? '') === 'ARS' || true)
                <div class="flex justify-between gap-2 border-t py-2 text-sm" style="border-color: var(--ar-border);">
                    <a href="{{ $row['url'] ?? '#' }}" class="underline">{{ $row['name'] }}</a>
                    <span class="tabular-nums {{ \App\Support\UiSemantics::cssClass((string)$row['balance'], \App\Support\UiSemantics::MODE_CLIENT_CC) }}">
                        ${{ number_format((float) $row['balance'], 2, ',', '.') }}
                        <span class="ar-muted text-xs">{{ $row['currency'] }}</span>
                    </span>
                </div>
            @endif
        @empty
            <p class="ar-muted text-sm">Sin saldos a cobrar en este momento.</p>
        @endforelse
    </div>
@endif

{{-- Disponibilidades FA --}}
@if (($derived['kind'] ?? '') === 'disponibilidades' && !empty($derived['accounts']) && $derived['accounts']->isNotEmpty())
    <div class="mt-4">
        <h3 class="font-semibold text-sm mb-2">Cuentas financieras (derivadas)</h3>
        @foreach ($derived['accounts'] as $fa)
            <div class="flex justify-between text-sm border-t py-1.5" style="border-color: var(--ar-border);">
                <span>{{ $fa->name }}</span>
                <span class="tabular-nums {{ \App\Support\UiSemantics::cssClass((string)$fa->cached_balance, \App\Support\UiSemantics::MODE_ASSET) }}">
                    ${{ number_format((float) $fa->cached_balance, 2, ',', '.') }}
                </span>
            </div>
        @endforeach
    </div>
@endif

{{-- Inventario --}}
@if (($derived['kind'] ?? '') === 'inventory')
    <div class="mt-4 rounded border p-3 text-sm" style="border-color: var(--ar-border);">
        <p class="font-medium">Valuación de inventario no disponible</p>
        <p class="ar-muted text-xs mt-1">Preparado para integración futura FIFO / costo. No se muestra $0 engañoso.</p>
    </div>
@endif

{{-- Patrimonio / proveedores insufficient --}}
@if (in_array($derived['kind'] ?? '', ['equity', 'suppliers'], true) && $display !== 'amount')
    <div class="mt-4 rounded border p-3 text-sm" style="border-color: var(--ar-border);">
        <p class="font-medium">{{ $derived['display_label'] ?? 'Sin datos suficientes' }}</p>
        @if (($derived['kind'] ?? '') === 'equity')
            <p class="ar-muted text-xs mt-1">No hace falta cargar asientos de patrimonio para usar el sistema día a día.</p>
        @endif
    </div>
@endif

{{-- Distribution chart --}}
@if (!empty($detail['distribution']))
    <div class="mt-5">
        <h3 class="font-semibold text-sm mb-3">Distribución</h3>
        @php $maxPct = max(1, collect($detail['distribution'])->max('percent') ?: 1); @endphp
        <div class="space-y-2">
            @foreach ($detail['distribution'] as $bar)
                <a href="{{ route('chart-accounts.index', array_merge($qs ?? [], ['account' => $bar['id']])) }}" class="block group">
                    <div class="flex justify-between text-xs mb-0.5">
                        <span class="group-hover:underline">{{ $bar['name'] }}</span>
                        <span class="tabular-nums ar-muted">{{ $bar['percent'] }}% · ${{ number_format((float)$bar['total_ars'], 0, ',', '.') }}</span>
                    </div>
                    <div class="h-2 rounded overflow-hidden" style="background: var(--ar-surface-2, #e5e7eb);">
                        <div class="h-full rounded" style="width: {{ min(100, ($bar['percent'] / $maxPct) * 100) }}%; background: var(--ar-accent, #2563eb);"></div>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
@endif

@if (!empty($detail['by_scope']))
    <div class="mt-4">
        <h3 class="font-semibold text-sm mb-2">Por ámbito / origen</h3>
        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4 text-sm">
            @foreach ($detail['by_scope'] as $sk => $sv)
                <div class="rounded border p-2" style="border-color: var(--ar-border);">
                    <p class="ar-muted text-xs">{{ config('finance.scopes.'.$sk, $sk) }}</p>
                    <p class="tabular-nums">{{ $sv['count'] }} · ${{ number_format((float) $sv['total_ars'], 2, ',', '.') }}</p>
                </div>
            @endforeach
        </div>
    </div>
@endif

@if ($detail['children']->isNotEmpty())
    <div class="mt-4">
        <h3 class="font-semibold text-sm mb-2">Subcuentas</h3>
        <ul class="space-y-1 text-sm">
            @foreach ($detail['children'] as $child)
                <li>
                    <a class="underline" href="{{ route('chart-accounts.index', array_merge($qs ?? [], ['account' => $child->id])) }}">
                        {{ $child->code }} · {{ $child->name }}
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
@else
    <div class="mt-4">
        <p class="ar-muted text-sm">Esta cuenta no tiene subcuentas.</p>
        @can('categories.create')
            <a href="{{ route('chart-accounts.create', ['parent_id' => $selected->id]) }}" class="ar-btn ar-btn-secondary text-xs mt-2 inline-block">+ Agregar subcuenta</a>
        @endcan
    </div>
@endif

<div class="mt-5">
    <div class="flex flex-wrap items-end justify-between gap-2 mb-2">
        <h3 class="font-semibold text-sm">Movimientos</h3>
        <form method="GET" class="flex flex-wrap gap-2 items-end">
            @foreach ($qs ?? [] as $k => $v)
                <input type="hidden" name="{{ $k }}" value="{{ $v }}">
            @endforeach
            <input type="hidden" name="account" value="{{ $selected->id }}">
            <input type="search" name="q_mov" class="ar-input text-sm" placeholder="Buscar descripción…" value="{{ $qMov }}">
            <input type="hidden" name="sort" value="{{ $sortDir === 'asc' ? 'desc' : 'asc' }}">
            <button class="ar-btn ar-btn-secondary text-xs">Buscar</button>
            <a class="ar-btn ar-btn-secondary text-xs" href="{{ route('chart-accounts.index', array_merge($qs ?? [], ['account' => $selected->id, 'sort' => $sortDir === 'asc' ? 'desc' : 'asc', 'q_mov' => $qMov])) }}">
                Fecha {{ $sortDir === 'asc' ? '↑' : '↓' }}
            </a>
        </form>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="ar-muted text-left text-xs border-b" style="border-color: var(--ar-border);">
                    <th class="py-2 pr-2">Fecha</th>
                    <th class="py-2 pr-2">Descripción</th>
                    <th class="py-2 pr-2">Cuenta financiera</th>
                    <th class="py-2 pr-2">Ámbito</th>
                    <th class="py-2 text-right">Importe</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($detail['movements'] as $m)
                    <tr class="border-t" style="border-color: var(--ar-border);">
                        <td class="py-2 pr-2 whitespace-nowrap">
                            <a href="{{ route('movements.show', $m) }}" class="underline">{{ $m->movement_date?->format('d/m/Y') }}</a>
                        </td>
                        <td class="py-2 pr-2">{{ $m->description ?: '—' }}</td>
                        <td class="py-2 pr-2">{{ $m->account?->name ?? '—' }}</td>
                        <td class="py-2 pr-2">{{ config('finance.scopes.'.($m->scope?->value ?? $m->scope), $m->scope?->value ?? $m->scope) }}</td>
                        <td class="py-2 text-right tabular-nums {{ \App\Support\UiSemantics::cssClass((string)$m->amount_ars, $mode) }}">
                            ${{ number_format((float) $m->amount_ars, 2, ',', '.') }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="py-4 ar-muted">
                            No hay movimientos para {{ $periodLabel }}.
                            <a href="{{ route('chart-accounts.index', ['account' => $selected->id, 'period' => 'this_year']) }}" class="underline ml-1">Ver otro período</a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
