<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">{{ $title }}</h1>
                @if ($note)<p class="ar-muted text-sm">{{ $note }}</p>@endif
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($type === 'chart-accounts')
                    <x-page-help topic="chart_accounts" />
                @endif
                <a href="{{ route('reports.index') }}" class="ar-btn ar-btn-secondary">Volver</a>
                @canany(['exports.execute', 'reports.export'])
                    <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="ar-btn ar-btn-secondary">CSV</a>
                    <a href="{{ request()->fullUrlWithQuery(['export' => 'xlsx']) }}" class="ar-btn ar-btn-secondary">XLSX</a>
                    @if (in_array($type, ['finance-balances', 'clients-receivables', 'profitability', 'stock-current']))
                        <a href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}" class="ar-btn ar-btn-secondary">PDF</a>
                    @endif
                @endcanany
            </div>
        </div>
    </x-slot>

    @if (in_array($type, ['finance-movements', 'finance-income-expense', 'clients-movements', 'suppliers-movements', 'sales', 'profitability']))
        <form method="GET" class="ar-card mb-4 grid gap-3 p-4 sm:grid-cols-4">
            <div><label class="ar-label">Desde</label><input type="date" name="date_from" class="ar-input" value="{{ $filters['date_from'] ?? '' }}"></div>
            <div><label class="ar-label">Hasta</label><input type="date" name="date_to" class="ar-input" value="{{ $filters['date_to'] ?? '' }}"></div>
            @if ($type === 'finance-movements')
                <div>
                    <label class="ar-label">Ámbito</label>
                    <select name="scope" class="ar-input">
                        <option value="all">Todos</option>
                        <option value="personal" @selected(($filters['scope'] ?? '') === 'personal')>Personal</option>
                        <option value="professional" @selected(($filters['scope'] ?? '') === 'professional')>Profesional</option>
                    </select>
                </div>
                <div>
                    <label class="ar-label">Cuenta contable (plan)</label>
                    <select name="chart_account_id" class="ar-input">
                        <option value="">Todas</option>
                        <option value="unassigned" @selected(($filters['chart_account_id'] ?? '') === 'unassigned')>Sin asignar</option>
                        @foreach (\App\Models\ChartAccount::query()->orderBy('code')->get() as $ca)
                            <option value="{{ $ca->id }}" @selected((string) ($filters['chart_account_id'] ?? '') === (string) $ca->id)>{{ $ca->code }} — {{ $ca->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="flex items-end"><button class="ar-btn ar-btn-primary w-full">Filtrar</button></div>
        </form>
    @endif

    @if (!empty($totals))
        <div class="ar-card mb-4 p-4 text-sm">
            @foreach ($totals as $k => $v)
                <span class="me-4"><span class="ar-muted">{{ $k }}:</span> <strong>{{ is_numeric($v) ? number_format((float) $v, 2, ',', '.') : $v }}</strong></span>
            @endforeach
        </div>
    @endif

    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead>
                <tr>
                    @foreach ($columns as $label)
                        <th>{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        @foreach (array_keys($columns) as $key)
                            <td>{{ $row[$key] ?? '' }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($columns) }}" class="ar-muted py-6 text-center">Sin datos.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
