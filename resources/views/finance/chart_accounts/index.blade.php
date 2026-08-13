<x-app-layout>
    @php
        $fmt = fn (?string $n) => $n === null ? null : number_format((float) $n, 2, ',', '.');
        $qs = array_filter([
            'period' => $period['preset'] ?? null,
            'from' => ($period['preset'] ?? '') === 'custom' ? ($period['from'] ?? null) : null,
            'to' => ($period['preset'] ?? '') === 'custom' ? ($period['to'] ?? null) : null,
            'scope' => $scope ?: null,
        ], fn ($v) => $v !== null && $v !== '');
        $accountUrl = fn ($id) => route('chart-accounts.index', array_merge($qs, ['account' => $id]));
        $amountClass = function (?string $amount, string $mode) {
            if ($amount === null) return 'ar-muted';
            return \App\Support\UiSemantics::cssClass($amount, $mode);
        };
    @endphp

    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <h1 class="text-xl font-semibold">Plan de cuentas</h1>
                <x-page-help topic="chart_accounts" />
            </div>
            <div class="flex flex-wrap gap-2">
                @if (($pending ?? 0) > 0)
                    <a href="{{ route('chart-accounts.classify') }}" class="ar-btn ar-btn-secondary text-xs" style="color: var(--ar-danger);">
                        {{ $pending }} movimientos necesitan clasificación
                    </a>
                @endif
                <a href="{{ route('accounts.index') }}" class="ar-btn ar-btn-secondary text-xs">Cuentas financieras</a>
                @can('categories.edit')
                    <a href="{{ route('chart-accounts.advanced') }}" class="ar-btn ar-btn-secondary text-xs">Configuración avanzada</a>
                @endcan
                @can('categories.create')
                    <a href="{{ route('chart-accounts.create', ['parent_id' => $selected?->id]) }}" class="ar-btn ar-btn-primary text-xs">+ Subcuenta</a>
                @endcan
            </div>
        </div>
    </x-slot>

    @if (session('status'))
        <p class="mb-4 rounded border px-3 py-2 text-sm" style="border-color: var(--ar-border);">{{ session('status') }}</p>
    @endif

    <form method="GET" class="ar-card mb-4 flex flex-wrap items-end gap-3 p-4" id="period-form">
        @if ($selected)
            <input type="hidden" name="account" value="{{ $selected->id }}">
        @endif
        <div>
            <label class="ar-label">Período</label>
            <select name="period" class="ar-input" onchange="const c=document.getElementById('custom-dates'); c.style.display=this.value==='custom'?'flex':'none'; this.form.submit();">
                @foreach ($periodOptions as $opt)
                    <option value="{{ $opt['value'] }}" @selected(($period['preset'] ?? '') === $opt['value'])>{{ $opt['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div id="custom-dates" class="flex flex-wrap gap-3" style="{{ ($period['preset'] ?? '') === 'custom' ? '' : 'display:none' }}">
            <div>
                <label class="ar-label">Desde</label>
                <input type="date" name="from" class="ar-input" value="{{ $period['from'] }}">
            </div>
            <div>
                <label class="ar-label">Hasta</label>
                <input type="date" name="to" class="ar-input" value="{{ $period['to'] }}">
            </div>
            <button class="ar-btn ar-btn-secondary self-end">Aplicar</button>
        </div>
        <div>
            <label class="ar-label">
                Ámbito / Origen
                <span class="ar-muted text-xs font-normal">(según rama)</span>
            </label>
            <select name="scope" class="ar-input" onchange="this.form.submit()">
                <option value="">Todos</option>
                <optgroup label="Egresos">
                    <option value="personal" @selected($scope === 'personal')>Personal</option>
                    <option value="professional" @selected($scope === 'professional')>Profesional</option>
                    <option value="mixed" @selected($scope === 'mixed')>Mixto</option>
                </optgroup>
                <optgroup label="Ingresos">
                    <option value="professional" @selected($scope === 'professional')>Profesional</option>
                    <option value="financial" @selected($scope === 'financial')>Financiero</option>
                </optgroup>
            </select>
        </div>
        <p class="ar-muted text-xs self-end pb-2">{{ $period['label'] ?? '' }}@if($period['from'] && $period['to']): {{ \Carbon\Carbon::parse($period['from'])->format('d/m/Y') }} – {{ \Carbon\Carbon::parse($period['to'])->format('d/m/Y') }}@endif</p>
    </form>

    {{-- MOBILE: navegación progresiva --}}
    <div class="lg:hidden space-y-3 mb-4">
        <div class="ar-card p-3">
            @if ($navNode)
                <a href="{{ route('chart-accounts.index', array_merge($qs, $navNode->parent_id ? ['nav' => $navNode->parent_id] : [])) }}" class="ar-muted text-sm underline">
                    ← {{ $navNode->parent_id ? ($navTrail[count($navTrail)-2]->name ?? 'Plan de cuentas') : 'Plan de cuentas' }}
                </a>
                <h2 class="mt-2 font-semibold">{{ $navNode->code }} · {{ $navNode->name }}</h2>
            @else
                <h2 class="font-semibold">Plan de cuentas</h2>
            @endif
            <ul class="mt-3 divide-y" style="border-color: var(--ar-border);">
                @forelse ($navChildren as $child)
                    <li class="py-2 flex items-center justify-between gap-2">
                        @if (!empty($child['children']))
                            <a href="{{ route('chart-accounts.index', array_merge($qs, ['nav' => $child['id']])) }}" class="font-medium">
                                {{ $child['name'] }}
                            </a>
                        @else
                            <a href="{{ $accountUrl($child['id']) }}" class="font-medium">{{ $child['name'] }}</a>
                        @endif
                        <span class="tabular-nums text-sm {{ $amountClass($child['total_ars'] ?? '0', $child['amount_mode'] ?? \App\Support\UiSemantics::MODE_RESULT) }}">
                            @php $d = app(\App\Services\Finance\ChartAccountWorkspaceService::class)->derivedSummaryForCode((string)$child['code']); @endphp
                            @if (($d['display'] ?? 'amount') !== 'amount')
                                <span class="ar-muted text-xs">{{ $d['display_label'] }}</span>
                            @else
                                ${{ $fmt($d['total_ars'] ?? $child['total_ars']) }}
                            @endif
                        </span>
                    </li>
                @empty
                    <li class="ar-muted text-sm py-2">Sin subcuentas.</li>
                @endforelse
            </ul>
            @if ($navNode)
                <a href="{{ $accountUrl($navNode->id) }}" class="ar-btn ar-btn-secondary text-xs mt-3 w-full">Ver detalle de {{ $navNode->name }}</a>
            @endif
        </div>
    </div>

    {{-- DESKTOP: dos columnas --}}
    <div
        class="hidden lg:grid gap-4 lg:grid-cols-12"
        x-data="{
            treeQ: @js($treeQ ?? ''),
            open: {},
            matches(node) {
                const q = (this.treeQ || '').toLowerCase().trim()
                if (!q) return true
                const hay = ((node.code||'') + ' ' + (node.name||'') + ' ' + (node.path||'')).toLowerCase()
                if (hay.includes(q)) return true
                return (node.children || []).some(c => this.matches(c))
            }
        }"
    >
        <aside class="ar-card lg:col-span-5 xl:col-span-4 p-3 max-h-[78vh] overflow-auto text-sm">
            <div class="mb-3 sticky top-0 z-10 pb-2" style="background: var(--ar-surface, #fff);">
                <p class="font-semibold mb-2">Árbol</p>
                <input type="search" class="ar-input text-sm" placeholder="Buscar: comb, susc, inter…" x-model="treeQ">
            </div>
            @foreach ($roots as $root)
                @include('finance.chart_accounts._tree_workspace', [
                    'node' => $root,
                    'selectedId' => $selected?->id,
                    'depth' => 0,
                    'qs' => $qs,
                ])
            @endforeach
        </aside>

        <section class="ar-card lg:col-span-7 xl:col-span-8 p-4 max-h-[78vh] overflow-auto">
            @if ($selected && $detail)
                @include('finance.chart_accounts._detail_panel', [
                    'selected' => $selected,
                    'detail' => $detail,
                    'period' => $period,
                    'scope' => $scope,
                    'qs' => $qs,
                    'sortDir' => $sortDir,
                    'qMov' => $qMov,
                ])
            @else
                @include('finance.chart_accounts._radiography', [
                    'radiography' => $radiography,
                    'period' => $period,
                    'qs' => $qs,
                ])
            @endif
        </section>
    </div>

    {{-- Mobile detail when account selected --}}
    @if ($selected && $detail)
        <div class="lg:hidden ar-card p-4 mt-2">
            @include('finance.chart_accounts._detail_panel', [
                'selected' => $selected,
                'detail' => $detail,
                'period' => $period,
                'scope' => $scope,
                'qs' => $qs,
                'sortDir' => $sortDir,
                'qMov' => $qMov,
            ])
        </div>
    @endif
</x-app-layout>
