<x-app-layout>
    @php
        $d = $data;
        $f = $filters;
        $period = $d['period'];
        $fin = $d['financial'];
        $eco = $d['economic'];
        $cc = $d['cc'];
        $pos = $d['position'];
        $prevYm = \Carbon\Carbon::createFromFormat('Y-m', $period['ym'])->subMonthNoOverflow()->format('Y-m');
        $nextYm = \Carbon\Carbon::createFromFormat('Y-m', $period['ym'])->addMonthNoOverflow()->format('Y-m');
        $kpiClass = function (string $amount, string $mode = \App\Support\UiSemantics::MODE_RESULT): string {
            return \App\Support\UiSemantics::kpiClass($amount, $mode);
        };
        $ccClass = function (string $amount): string {
            return \App\Support\UiSemantics::kpiClass($amount, \App\Support\UiSemantics::MODE_CLIENT_CC);
        };
        $fmtVar = function (?array $v): string {
            if ($v === null) {
                return 'Sin base de comparación';
            }
            $pct = $v['percent'] ?? null;
            if ($pct === null) {
                return 'Sin base de comparación';
            }
            $sign = \App\Support\Money::isPositive($pct) ? '+' : '';

            return $sign.$pct.'%';
        };
    @endphp

    <x-slot name="header">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Tablero de gestión</h1>
                <p class="ar-muted text-sm">
                    Período analizado: {{ $period['from_label'] }} — {{ $period['to_label'] }}
                    · Ámbito: {{ $d['scope'] === 'all' ? 'Todos' : ($d['scope'] === 'personal' ? 'Personal' : 'Profesional') }}
                </p>
                <p class="mt-1 text-xs">
                    <a href="{{ route('chart-accounts.index') }}" style="color: var(--ar-brand);">Plan de cuentas (drill)</a>
                    ·
                    <a href="{{ route('reports.show', ['type' => 'finance-movements', 'chart_account_id' => 'unassigned']) }}" style="color: var(--ar-brand);">Movs. sin cuenta contable</a>
                </p>
            </div>
            <x-page-help topic="management" />
        </div>
    </x-slot>

    <form method="GET" action="{{ route('dashboard.management') }}" class="ar-card mb-4 p-4" x-data="{ preset: '{{ $f['preset'] }}' }">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="ar-label">Período</label>
                <select name="preset" class="ar-input" x-model="preset" @change="if (preset !== 'custom') $el.form.submit()">
                    <option value="this_month" @selected(($f['preset'] ?? '') === 'this_month')>Este mes</option>
                    <option value="previous_month" @selected(($f['preset'] ?? '') === 'previous_month')>Mes anterior</option>
                    <option value="year" @selected(($f['preset'] ?? '') === 'year')>Año actual</option>
                    <option value="month" @selected(($f['preset'] ?? '') === 'month')>Mes (navegación)</option>
                    <option value="custom" @selected(($f['preset'] ?? '') === 'custom')>Personalizado</option>
                </select>
            </div>

            <div class="flex items-end gap-1">
                <a class="ar-btn ar-btn-secondary" href="{{ route('dashboard.management', ['preset' => 'month', 'ym' => $prevYm, 'scope' => $d['scope'], 'chart_months' => $d['chart_months']]) }}" aria-label="Mes anterior">&lt;</a>
                <div class="px-3 py-2 text-sm font-semibold">{{ $period['label'] }}</div>
                <a class="ar-btn ar-btn-secondary" href="{{ route('dashboard.management', ['preset' => 'month', 'ym' => $nextYm, 'scope' => $d['scope'], 'chart_months' => $d['chart_months']]) }}" aria-label="Mes siguiente">&gt;</a>
                <input type="hidden" name="ym" value="{{ $period['ym'] }}">
            </div>

            <div class="flex flex-wrap gap-2" x-show="preset === 'custom'" x-cloak>
                <div>
                    <label class="ar-label" for="from">Desde</label>
                    <input id="from" name="from" type="text" class="ar-input" placeholder="DD/MM/AAAA" value="{{ $f['from'] }}">
                </div>
                <div>
                    <label class="ar-label" for="to">Hasta</label>
                    <input id="to" name="to" type="text" class="ar-input" placeholder="DD/MM/AAAA" value="{{ $f['to'] }}">
                </div>
                <button type="submit" class="ar-btn ar-btn-primary self-end">Aplicar</button>
            </div>

            <div>
                <label class="ar-label">Ámbito</label>
                <select name="scope" class="ar-input" onchange="this.form.submit()">
                    <option value="all" @selected($d['scope'] === 'all')>Todos</option>
                    <option value="professional" @selected($d['scope'] === 'professional')>Profesional</option>
                    <option value="personal" @selected($d['scope'] === 'personal')>Personal</option>
                </select>
            </div>

            <div>
                <label class="ar-label">Gráficos</label>
                <select name="chart_months" class="ar-input" onchange="this.form.submit()">
                    <option value="6" @selected($d['chart_months'] === 6)>6 meses</option>
                    <option value="12" @selected($d['chart_months'] === 12)>12 meses</option>
                </select>
            </div>
        </div>
        @if (!($d['comparison']['has_base'] ?? false))
            <p class="ar-muted mt-2 text-xs">Comparación: Sin base de comparación</p>
        @else
            <p class="ar-muted mt-2 text-xs">Comparación vs {{ $d['comparison']['label'] }}</p>
        @endif
    </form>

    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide ar-muted">Financiero</h2>
    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <a href="{{ $d['drilldown']['income'] }}" class="ar-card ar-kpi-card block p-4 hover:opacity-95">
            <p class="ar-muted text-xs">Ingresos</p>
            <p class="ar-kpi-value {{ $kpiClass($fin['income_ars']) }}">{{ \App\Support\Money::formatAr($fin['income_ars'], 'ARS') }}</p>
            <p class="ar-muted text-xs">{{ \App\Support\Money::formatAr($fin['income_usd'], 'USD') }}</p>
            @if ($fin['comparison_available'] ?? false)
                <p class="text-xs {{ $kpiClass($fin['variation']['income_ars']['delta'] ?? '0') }}">{{ $fmtVar($fin['variation']['income_ars'] ?? null) }}</p>
            @else
                <p class="ar-muted text-xs">Sin base de comparación</p>
            @endif
        </a>
        <a href="{{ $d['drilldown']['expense'] }}" class="ar-card ar-kpi-card block p-4 hover:opacity-95">
            <p class="ar-muted text-xs">Egresos</p>
            <p class="ar-kpi-value {{ $kpiClass(\App\Support\Money::mul($fin['expense_ars'], '-1')) }}">{{ \App\Support\Money::formatAr($fin['expense_ars'], 'ARS') }}</p>
            <p class="ar-muted text-xs">{{ \App\Support\Money::formatAr($fin['expense_usd'], 'USD') }}</p>
            @if ($fin['comparison_available'] ?? false)
                <p class="text-xs">{{ $fmtVar($fin['variation']['expense_ars'] ?? null) }}</p>
            @else
                <p class="ar-muted text-xs">Sin base de comparación</p>
            @endif
        </a>
        <div class="ar-card ar-kpi-card p-4">
            <p class="ar-muted text-xs">Resultado (Ing - Egr)</p>
            <p class="ar-kpi-value {{ $kpiClass($fin['result_ars']) }}">{{ \App\Support\Money::formatAr($fin['result_ars'], 'ARS') }}</p>
            <p class="ar-muted text-xs">{{ \App\Support\Money::formatAr($fin['result_usd'], 'USD') }}</p>
            @if ($fin['comparison_available'] ?? false)
                <p class="text-xs {{ $kpiClass($fin['variation']['result_ars']['delta'] ?? '0') }}">{{ $fmtVar($fin['variation']['result_ars'] ?? null) }}</p>
            @else
                <p class="ar-muted text-xs">Sin base de comparación</p>
            @endif
        </div>
    </div>

    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide ar-muted">Económico</h2>
    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <a href="{{ $d['drilldown']['sales'] }}" class="ar-card ar-kpi-card block p-4">
            <p class="ar-muted text-xs">Ventas</p>
            <p class="ar-kpi-value {{ $kpiClass($eco['sales_ars']) }}">{{ \App\Support\Money::formatAr($eco['sales_ars'], 'ARS') }}</p>
            <p class="ar-muted text-xs">{{ \App\Support\Money::formatAr($eco['sales_usd'], 'USD') }} · {{ $eco['count'] }} docs</p>
            @if (!empty($eco['note']))
                <p class="ar-muted mt-1 text-xs">{{ $eco['note'] }}</p>
            @endif
        </a>
        <div class="ar-card ar-kpi-card p-4">
            <p class="ar-muted text-xs">Costo / merca</p>
            <p class="ar-kpi-value">{{ \App\Support\Money::formatAr($eco['cost_ars'], 'ARS') }}</p>
            <p class="ar-muted text-xs">{{ \App\Support\Money::formatAr($eco['cost_usd'], 'USD') }}</p>
        </div>
        <div class="ar-card ar-kpi-card p-4">
            <p class="ar-muted text-xs">Utilidad</p>
            <p class="ar-kpi-value {{ $kpiClass($eco['utility_ars']) }}">{{ \App\Support\Money::formatAr($eco['utility_ars'], 'ARS') }}</p>
            <p class="ar-muted text-xs">{{ \App\Support\Money::formatAr($eco['utility_usd'], 'USD') }}</p>
            <p class="ar-muted mt-1 text-xs">Utilidad ≠ ingreso financiero</p>
        </div>
    </div>

    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide ar-muted">Cuentas corrientes (al cierre)</h2>
    <div class="mb-4 grid gap-3 lg:grid-cols-4">
        <div class="ar-card ar-kpi-card p-4">
            <p class="ar-muted text-xs">A cobrar al cierre</p>
            <p class="ar-kpi-value {{ $ccClass($cc['closing']['ARS']) }}">{{ \App\Support\Money::formatAr($cc['closing']['ARS'], 'ARS') }}</p>
            <p class="ar-muted text-xs {{ $ccClass($cc['closing']['USD']) }}">{{ \App\Support\Money::formatAr($cc['closing']['USD'], 'USD') }}</p>
        </div>
        <div class="ar-card ar-kpi-card p-4">
            <p class="ar-muted text-xs">Nuevas deudas (CC IN)</p>
            <p class="ar-kpi-value {{ $ccClass($cc['new_debt']['ARS']) }}">{{ \App\Support\Money::formatAr($cc['new_debt']['ARS'], 'ARS') }}</p>
            <p class="ar-muted text-xs">{{ \App\Support\Money::formatAr($cc['new_debt']['USD'], 'USD') }}</p>
        </div>
        <div class="ar-card ar-kpi-card p-4">
            <p class="ar-muted text-xs">Cobros / cancelaciones (CC OUT)</p>
            <p class="ar-kpi-value {{ $kpiClass($cc['collections']['ARS']) }}">{{ \App\Support\Money::formatAr($cc['collections']['ARS'], 'ARS') }}</p>
            <p class="ar-muted text-xs">{{ \App\Support\Money::formatAr($cc['collections']['USD'], 'USD') }}</p>
        </div>
        <div class="ar-card ar-kpi-card p-4">
            <p class="ar-muted text-xs">Variación (cierre - apertura)</p>
            <p class="ar-kpi-value {{ $ccClass($cc['variation']['ARS']) }}">{{ \App\Support\Money::formatAr($cc['variation']['ARS'], 'ARS') }}</p>
            <p class="ar-muted text-xs">{{ \App\Support\Money::formatAr($cc['variation']['USD'], 'USD') }}</p>
        </div>
    </div>

    @if (!empty($cc['bridge']))
        <div class="ar-card mb-4 p-4 text-sm">
            <p class="mb-1 font-semibold">Puente CC ARS</p>
            <p>
                Inicial <span class="{{ $ccClass($cc['bridge']['ARS']['initial']) }}">{{ \App\Support\Money::formatAr($cc['bridge']['ARS']['initial']) }}</span>
                + IN {{ \App\Support\Money::formatAr($cc['bridge']['ARS']['in']) }}
                - OUT {{ \App\Support\Money::formatAr($cc['bridge']['ARS']['out']) }}
                = <span class="{{ $ccClass($cc['bridge']['ARS']['computed_final']) }}">{{ \App\Support\Money::formatAr($cc['bridge']['ARS']['computed_final']) }}</span>
                (cierre <span class="{{ $ccClass($cc['bridge']['ARS']['final']) }}">{{ \App\Support\Money::formatAr($cc['bridge']['ARS']['final']) }}</span>)
            </p>
            @if (!empty($cc['note']))
                <p class="ar-muted mt-1">{{ $cc['note'] }}</p>
            @endif
        </div>
    @endif

    @if (!empty($cc['clients']) || ($cc['applicable'] ?? false))
        <div class="ar-card mb-4 overflow-x-auto p-4">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h3 class="font-semibold">Top deudores al cierre</h3>
                <a href="{{ $d['drilldown']['client_current_accounts'] ?? $d['drilldown']['clients'] }}" class="text-sm" style="color: var(--ar-brand);">Ver todas las cuentas corrientes</a>
            </div>
            <table class="ar-table">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Moneda</th>
                        <th class="text-right">Saldo a cobrar</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($cc['clients'] as $row)
                        <tr>
                            <td>
                                @if ($row['url'])
                                    <a href="{{ $row['url'] }}" style="color: var(--ar-brand);">{{ $row['name'] }}</a>
                                @else
                                    {{ $row['name'] }}
                                @endif
                            </td>
                            <td>{{ $row['currency'] }}</td>
                            <td class="text-right {{ $ccClass($row['balance']) }}">{{ \App\Support\Money::formatAr($row['balance'], $row['currency'] ?? 'ARS') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="ar-muted px-4 py-4 text-center">Sin deudores al cierre.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide ar-muted">Posición al cierre ({{ $pos['as_of_label'] }})</h2>
    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <div class="ar-card ar-kpi-card p-4">
            <p class="ar-muted text-xs">Disponibilidades</p>
            <p class="ar-kpi-value">{{ \App\Support\Money::formatAr($pos['liquid']['ARS']['total'], 'ARS') }}</p>
            <p class="ar-muted text-xs">{{ \App\Support\Money::formatAr($pos['liquid']['USD']['total'], 'USD') }}</p>
            <ul class="ar-muted mt-2 space-y-0.5 text-xs">
                <li>Efectivo ARS {{ \App\Support\Money::formatAr($pos['liquid']['ARS']['cash']) }}</li>
                <li>Bancos ARS {{ \App\Support\Money::formatAr($pos['liquid']['ARS']['bank']) }}</li>
                <li>Billeteras ARS {{ \App\Support\Money::formatAr($pos['liquid']['ARS']['wallet']) }}</li>
            </ul>
        </div>
        <div class="ar-card ar-kpi-card p-4">
            <p class="ar-muted text-xs">Pasivos tarjetas</p>
            <p class="ar-kpi-value ar-kpi-negative">{{ \App\Support\Money::formatAr($pos['liabilities']['ARS'], 'ARS') }}</p>
            <p class="ar-muted text-xs">{{ \App\Support\Money::formatAr($pos['liabilities']['USD'], 'USD') }}</p>
        </div>
        <div class="ar-card ar-kpi-card p-4">
            <p class="ar-muted text-xs">Posición neta</p>
            <p class="ar-kpi-value {{ $kpiClass($pos['net']['ARS']) }}">{{ \App\Support\Money::formatAr($pos['net']['ARS'], 'ARS') }}</p>
            <p class="ar-muted text-xs">{{ \App\Support\Money::formatAr($pos['net']['USD'], 'USD') }}</p>
        </div>
    </div>

    @if (!empty($pos['by_holder']))
        <div class="ar-card mb-4 overflow-x-auto p-4">
            <h3 class="mb-2 font-semibold">Desglose por titular</h3>
            <p class="ar-muted mb-2 text-xs">{{ $pos['note'] }}</p>
            <table class="ar-table">
                <thead>
                    <tr>
                        <th>Titular</th>
                        <th class="text-right">Líquido ARS</th>
                        <th class="text-right">Pasivos ARS</th>
                        <th class="text-right">Neto ARS</th>
                        <th class="text-right">Neto USD</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($pos['by_holder'] as $h)
                        <tr>
                            <td>{{ $h['name'] }}</td>
                            <td class="text-right">{{ \App\Support\Money::formatAr($h['liquid']['ARS']) }}</td>
                            <td class="text-right">{{ \App\Support\Money::formatAr($h['liabilities']['ARS']) }}</td>
                            <td class="text-right {{ $kpiClass($h['net']['ARS']) }}">{{ \App\Support\Money::formatAr($h['net']['ARS']) }}</td>
                            <td class="text-right {{ $kpiClass($h['net']['USD']) }}">{{ \App\Support\Money::formatAr($h['net']['USD'], 'USD') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="mb-4 grid gap-4 lg:grid-cols-2">
        @foreach ([
            'income' => ['title' => 'Ingresos por tipo', 'rows' => $d['income_by_type'], 'sort' => 'sort_income'],
            'expense' => ['title' => 'Egresos por tipo', 'rows' => $d['expense_by_type'], 'sort' => 'sort_expense'],
        ] as $key => $block)
            <div class="ar-card overflow-x-auto p-4">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-semibold">{{ $block['title'] }}</h3>
                    <form method="GET" class="flex gap-1">
                        <input type="hidden" name="preset" value="{{ $f['preset'] }}">
                        <input type="hidden" name="ym" value="{{ $period['ym'] }}">
                        <input type="hidden" name="scope" value="{{ $d['scope'] }}">
                        <input type="hidden" name="chart_months" value="{{ $d['chart_months'] }}">
                        @if (($f['preset'] ?? '') === 'custom')
                            <input type="hidden" name="from" value="{{ $f['from'] }}">
                            <input type="hidden" name="to" value="{{ $f['to'] }}">
                        @endif
                        @if ($key === 'income')
                            <input type="hidden" name="sort_expense" value="{{ $f['sort_expense'] }}">
                        @else
                            <input type="hidden" name="sort_income" value="{{ $f['sort_income'] }}">
                        @endif
                        <select name="{{ $block['sort'] }}" class="ar-input text-xs" onchange="this.form.submit()">
                            <option value="amount_desc" @selected(($f[$block['sort']] ?? '') === 'amount_desc')>Importe ↓</option>
                            <option value="amount_asc" @selected(($f[$block['sort']] ?? '') === 'amount_asc')>Importe ↑</option>
                            <option value="name_asc" @selected(($f[$block['sort']] ?? '') === 'name_asc')>Tipo A-Z</option>
                            <option value="pct_desc" @selected(($f[$block['sort']] ?? '') === 'pct_desc')>% ↓</option>
                            <option value="var_desc" @selected(($f[$block['sort']] ?? '') === 'var_desc')>Var ↓</option>
                        </select>
                    </form>
                </div>
                <table class="ar-table">
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th class="text-right">Importe</th>
                            <th class="text-right">%</th>
                            <th class="text-right">Var.</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($block['rows'] as $row)
                            <tr>
                                <td><a href="{{ $row['url'] }}" style="color: var(--ar-brand);">{{ $row['name'] }}</a></td>
                                <td class="text-right">{{ \App\Support\Money::formatAr($row['amount_ars']) }}</td>
                                <td class="text-right">{{ $row['percent'] === null ? '—' : $row['percent'].'%' }}</td>
                                <td class="text-right {{ $kpiClass($row['variation_delta']) }}">
                                    @if ($row['variation_percent'] === null)
                                        Sin base
                                    @else
                                        {{ \App\Support\Money::isPositive($row['variation_percent']) ? '+' : '' }}{{ $row['variation_percent'] }}%
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="ar-muted">Sin datos en el período.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>

    <div class="mb-4 grid gap-4 lg:grid-cols-2">
        <div class="ar-card p-4">
            <h3 class="mb-2 font-semibold">Ing / Egr / Resultado financiero</h3>
            <canvas id="chart-financial" height="220"></canvas>
        </div>
        <div class="ar-card p-4">
            <h3 class="mb-2 font-semibold">Ventas / Utilidad</h3>
            <canvas id="chart-economic" height="220"></canvas>
        </div>
    </div>

    <div class="ar-card mb-4 overflow-x-auto p-4">
        <h3 class="mb-2 font-semibold">Resumen mensual</h3>
        <table class="ar-table">
            <thead>
                <tr>
                    <th>Mes</th>
                    <th class="text-right">Ingresos</th>
                    <th class="text-right">Egresos</th>
                    <th class="text-right">Resultado</th>
                    <th class="text-right">Ventas</th>
                    <th class="text-right">Utilidad</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($d['monthly_summary'] as $row)
                    <tr class="{{ $row['ym'] === $period['ym'] ? 'ar-row-active' : '' }}">
                        <td><a href="{{ $row['url'] }}" style="color: var(--ar-brand);">{{ $row['label'] }}</a></td>
                        <td class="text-right">{{ \App\Support\Money::formatAr($row['income_ars']) }}</td>
                        <td class="text-right">{{ \App\Support\Money::formatAr($row['expense_ars']) }}</td>
                        <td class="text-right {{ $kpiClass($row['result_ars']) }}">{{ \App\Support\Money::formatAr($row['result_ars']) }}</td>
                        <td class="text-right">{{ \App\Support\Money::formatAr($row['sales_ars']) }}</td>
                        <td class="text-right {{ $kpiClass($row['utility_ars']) }}">{{ \App\Support\Money::formatAr($row['utility_ars']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if (!empty($d['limitations']))
        <div class="ar-card mb-6 p-4">
            <h3 class="mb-2 font-semibold">Limitaciones</h3>
            <ul class="ar-muted list-disc space-y-1 pl-5 text-xs">
                @foreach ($d['limitations'] as $lim)
                    <li>{{ $lim }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        (function () {
            const labels = @json($d['charts']['labels']);
            const fin = @json($d['charts']['financial']);
            const eco = @json($d['charts']['economic']);
            const css = getComputedStyle(document.documentElement);
            const text = css.getPropertyValue('--ar-text').trim() || '#15202b';
            const muted = css.getPropertyValue('--ar-muted').trim() || '#5b6b7c';
            const brand = css.getPropertyValue('--ar-brand').trim() || '#0f4c5c';
            const danger = css.getPropertyValue('--ar-danger').trim() || '#b42318';
            const success = css.getPropertyValue('--ar-success').trim() || '#067647';
            const grid = css.getPropertyValue('--ar-border').trim() || '#d7dee7';

            const commonOpts = {
                responsive: true,
                plugins: {
                    legend: { labels: { color: text } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                const n = Number(ctx.raw || 0);
                                return ctx.dataset.label + ': $ ' + n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                            }
                        }
                    }
                },
                scales: {
                    x: { ticks: { color: muted }, grid: { color: grid } },
                    y: { ticks: { color: muted }, grid: { color: grid } }
                }
            };

            const elFin = document.getElementById('chart-financial');
            if (elFin && window.Chart) {
                new Chart(elFin, {
                    type: 'line',
                    data: {
                        labels,
                        datasets: [
                            { label: 'Ingresos', data: fin.income.map(Number), borderColor: success, backgroundColor: 'transparent', tension: 0.25 },
                            { label: 'Egresos', data: fin.expense.map(Number), borderColor: danger, backgroundColor: 'transparent', tension: 0.25 },
                            { label: 'Resultado', data: fin.result.map(Number), borderColor: brand, backgroundColor: 'transparent', tension: 0.25 }
                        ]
                    },
                    options: commonOpts
                });
            }

            const elEco = document.getElementById('chart-economic');
            if (elEco && window.Chart) {
                new Chart(elEco, {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: [
                            { label: 'Ventas', data: eco.sales.map(Number), backgroundColor: brand },
                            { label: 'Utilidad', data: eco.utility.map(Number), backgroundColor: success }
                        ]
                    },
                    options: commonOpts
                });
            }
        })();
    </script>
</x-app-layout>
