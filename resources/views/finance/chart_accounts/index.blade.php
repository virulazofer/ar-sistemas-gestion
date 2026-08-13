<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <h1 class="text-xl font-semibold">Plan de cuentas</h1>
                <x-page-help topic="chart_accounts" />
            </div>
            <div class="flex flex-wrap gap-2">
                @if (($progress['pending'] ?? 0) > 0)
                    <a href="{{ route('chart-accounts.classify') }}" class="ar-btn ar-btn-secondary text-xs">
                        Clasificar movimientos ({{ $progress['pending'] }})
                    </a>
                @else
                    <a href="{{ route('chart-accounts.classify') }}" class="ar-btn ar-btn-secondary text-xs ar-muted">Clasificar movimientos · al día</a>
                @endif
                <a href="{{ route('accounts.index') }}" class="ar-btn ar-btn-secondary text-xs">Cuentas financieras</a>
                @can('categories.create')
                    <a href="{{ route('chart-accounts.create', ['parent_id' => $selected?->id]) }}" class="ar-btn ar-btn-primary">Nueva subcuenta</a>
                @endcan
            </div>
        </div>
    </x-slot>

    @if (session('status'))
        <p class="mb-4 rounded border px-3 py-2 text-sm" style="border-color: var(--ar-border);">{{ session('status') }}</p>
    @endif

    <p class="ar-muted mb-4 text-sm">
        <strong>Cuenta contable</strong> (este plan) ≠ <strong>cuenta financiera</strong> (caja/banco/tarjeta).
        Clasificación económica única. Dimensiones: <strong>Plan</strong> (qué) · <strong>Cuenta financiera</strong> (dónde) · <strong>Ámbito/Origen</strong> · Cliente/Proveedor · Fecha · Importe.
    </p>

    <form method="GET" class="ar-card mb-4 flex flex-wrap items-end gap-3 p-4">
        <input type="hidden" name="account" value="{{ $selected?->id }}">
        <div>
            <label class="ar-label">Desde</label>
            <input type="date" name="from" class="ar-input" value="{{ $dateFrom }}">
        </div>
        <div>
            <label class="ar-label">Hasta</label>
            <input type="date" name="to" class="ar-input" value="{{ $dateTo }}">
        </div>
        <div>
            <label class="ar-label">Ámbito / Origen</label>
            <select name="scope" class="ar-input">
                <option value="">Todos</option>
                <option value="personal" @selected($scope === 'personal')>Personal</option>
                <option value="professional" @selected($scope === 'professional')>Profesional</option>
                <option value="mixed" @selected($scope === 'mixed')>Mixto</option>
                <option value="financial" @selected($scope === 'financial')>Financiero</option>
            </select>
        </div>
        <button class="ar-btn ar-btn-secondary">Filtrar</button>
    </form>

    <div class="grid gap-4 lg:grid-cols-12">
        <aside class="ar-card lg:col-span-4 p-3 max-h-[70vh] overflow-auto text-sm">
            <p class="mb-2 font-semibold">Árbol</p>
            @foreach ($roots as $root)
                @include('finance.chart_accounts._tree_nav', ['account' => $root, 'selectedId' => $selected?->id, 'depth' => 0])
            @endforeach
        </aside>

        <section class="ar-card lg:col-span-8 p-4">
            @if ($selected && $detail)
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="ar-muted text-xs">{{ $selected->code }} · {{ $selected->typeLabel() }}</p>
                        <h2 class="text-lg font-semibold">{{ $selected->name }}</h2>
                        <p class="ar-muted text-sm">{{ $detail['path'] }}</p>
                        @if ($selected->help_text)
                            <p class="mt-2 text-sm">
                                <span class="font-medium">¿Qué significa?</span>
                                {{ $selected->help_text }}
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
                            <a href="{{ route('chart-accounts.create', ['parent_id' => $selected->id]) }}" class="ar-btn ar-btn-secondary text-xs">Agregar subcuenta</a>
                        @endcan
                    </div>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-3 text-sm">
                    <div><p class="ar-muted text-xs">Movimientos (filtro)</p><p class="font-semibold tabular-nums">{{ number_format($detail['count']) }}</p></div>
                    <div><p class="ar-muted text-xs">Total ARS</p><p class="font-semibold tabular-nums">{{ number_format((float) $detail['total_ars'], 2, ',', '.') }}</p></div>
                    <div><p class="ar-muted text-xs">Estado</p><p class="font-semibold">{{ $selected->is_active ? 'Activa' : 'Inactiva' }}{{ $selected->is_protected ? ' · raíz protegida' : '' }}</p></div>
                </div>

                @if (!empty($detail['by_scope']))
                    <div class="mt-4">
                        <h3 class="font-semibold text-sm mb-2">Por ámbito / origen</h3>
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4 text-sm">
                            @foreach ($detail['by_scope'] as $sk => $sv)
                                <div class="rounded border p-2" style="border-color: var(--ar-border);">
                                    <p class="ar-muted text-xs">{{ config('finance.scopes.'.$sk, $sk) }}</p>
                                    <p class="tabular-nums">{{ $sv['count'] }} · {{ number_format((float) $sv['total_ars'], 2, ',', '.') }}</p>
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
                                    <a class="underline" href="{{ route('chart-accounts.index', array_filter(['account' => $child->id, 'from' => $dateFrom, 'to' => $dateTo, 'scope' => $scope])) }}">
                                        {{ $child->code }} · {{ $child->name }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($detail['financial_accounts']->isNotEmpty())
                    <div class="mt-4">
                        <h3 class="font-semibold text-sm mb-2">Cuentas financieras en esta ubicación</h3>
                        <ul class="text-sm space-y-1">
                            @foreach ($detail['financial_accounts'] as $fa)
                                <li>{{ $fa->name }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="mt-4">
                    <h3 class="font-semibold text-sm mb-2">Movimientos (últimos 50 del filtro)</h3>
                    @forelse ($detail['movements'] as $m)
                        <div class="flex flex-wrap justify-between gap-2 border-t py-2 text-sm" style="border-color: var(--ar-border);">
                            <div>
                                <a href="{{ route('movements.show', $m) }}" class="underline">{{ $m->movement_date?->format('d/m/Y') }}</a>
                                · {{ $m->description ?: '—' }}
                                <span class="ar-muted">· {{ config('finance.scopes.'.($m->scope?->value ?? $m->scope), $m->scope?->value ?? $m->scope) }}</span>
                            </div>
                            <div class="tabular-nums">{{ number_format((float) $m->amount_ars, 2, ',', '.') }}</div>
                        </div>
                    @empty
                        <p class="ar-muted text-sm">Sin movimientos en el filtro actual.</p>
                    @endforelse
                </div>
            @else
                <p class="ar-muted">Seleccioná una cuenta del árbol.</p>
            @endif
        </section>
    </div>
</x-app-layout>
