<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="ar-muted text-xs">Plan de cuentas · Pendientes</p>
                <div class="flex items-center gap-2">
                    <h1 class="text-xl font-semibold">Pendientes de clasificación</h1>
                    <x-page-help topic="chart_accounts.unclassified" />
                </div>
            </div>
            @include('finance.chart_accounts._plan_tools_nav', ['progress' => $progress])
        </div>
    </x-slot>

    @if (session('status'))
        <p class="ar-card mb-4 p-3 text-sm">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <div class="ar-card mb-4 p-3 text-sm" style="color: var(--ar-danger);">
            <ul class="list-disc ps-5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    @if (($progress['pending'] ?? 0) === 0)
        <div class="ar-card p-8 text-center">
            <p class="text-lg font-semibold">No hay movimientos pendientes de clasificación.</p>
            <p class="ar-muted mt-2 text-sm">
                Solo aparecen ingresos/egresos publicados sin categoría operativa.
                Una categoría correcta alcanza: no hace falta cuenta del plan para estar al día.
            </p>
            <div class="mt-4 flex flex-wrap justify-center gap-2">
                <a href="{{ route('chart-accounts.index') }}" class="ar-btn ar-btn-secondary text-xs">Ver plan</a>
                <a href="{{ route('chart-accounts.mapping') }}" class="ar-btn ar-btn-secondary text-xs">Asignación al plan</a>
            </div>
        </div>
    @else
        <div class="ar-card mb-4 grid gap-3 p-4 sm:grid-cols-4">
            <div><p class="ar-muted text-xs">Movimientos totales</p><p class="text-lg font-semibold tabular-nums">{{ $progress['total'] }}</p></div>
            <div><p class="ar-muted text-xs">Clasificados (cat)</p><p class="text-lg font-semibold tabular-nums">{{ $progress['classified'] }}</p></div>
            <div><p class="ar-muted text-xs">Pendientes (sin categoría)</p><p class="text-lg font-semibold tabular-nums" style="color: var(--ar-danger);">{{ $progress['pending'] }}</p></div>
            <div><p class="ar-muted text-xs">Resuelto</p><p class="text-lg font-semibold tabular-nums">{{ $progress['percent'] }}%</p></div>
        </div>

        <p class="ar-muted mb-3 text-sm">
            Clasificación operativa: <strong>Naturaleza → Categoría → Subcategoría</strong>.
            Cat/sub correcta <strong>no</strong> exige cuenta del plan para estar completo.
            <strong>Cuenta financiera</strong> ≠ <strong>cuenta del plan</strong>. Ámbito no se modifica aquí.
        </p>

        <form method="GET" class="ar-card mb-4 grid gap-3 p-4 md:grid-cols-4 lg:grid-cols-6">
            <div class="md:col-span-2">
                <label class="ar-label">Buscar</label>
                <input type="text" name="q" class="ar-input" value="{{ $filters['q'] }}" placeholder="Concepto, categoría, medio…">
            </div>
            <div>
                <label class="ar-label">Ámbito</label>
                <select name="scope" class="ar-input">
                    <option value="">Todos</option>
                    <option value="personal" @selected(($filters['scope'] ?? '') === 'personal')>Personal</option>
                    <option value="professional" @selected(($filters['scope'] ?? '') === 'professional')>Profesional</option>
                </select>
            </div>
            <div>
                <label class="ar-label">Tipo</label>
                <select name="type" class="ar-input">
                    <option value="">Todos</option>
                    <option value="income" @selected(($filters['type'] ?? '') === 'income')>Ingresos</option>
                    <option value="expense" @selected(($filters['type'] ?? '') === 'expense')>Egresos</option>
                </select>
            </div>
            <div>
                <label class="ar-label">Categoría</label>
                <select name="category_id" class="ar-input">
                    <option value="">Todas</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}" @selected(($filters['category_id'] ?? null) == $cat->id)>{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ar-label">Cuenta financiera</label>
                <select name="financial_account_id" class="ar-input">
                    <option value="">Todas</option>
                    @foreach ($financialAccounts as $fa)
                        <option value="{{ $fa->id }}" @selected(($filters['financial_account_id'] ?? null) == $fa->id)>{{ $fa->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ar-label">Desde</label>
                <input type="date" name="from" class="ar-input" value="{{ $filters['from'] }}">
            </div>
            <div>
                <label class="ar-label">Hasta</label>
                <input type="date" name="to" class="ar-input" value="{{ $filters['to'] }}">
            </div>
            <div>
                <label class="ar-label">Orden</label>
                <select name="sort" class="ar-input">
                    <option value="date_desc" @selected(($filters['sort'] ?? '') === 'date_desc')>Fecha ↓</option>
                    <option value="date_asc" @selected(($filters['sort'] ?? '') === 'date_asc')>Fecha ↑</option>
                    <option value="amount_desc" @selected(($filters['sort'] ?? '') === 'amount_desc')>Importe ↓</option>
                    <option value="amount_asc" @selected(($filters['sort'] ?? '') === 'amount_asc')>Importe ↑</option>
                    <option value="description" @selected(($filters['sort'] ?? '') === 'description')>Concepto</option>
                </select>
            </div>
            <div>
                <label class="ar-label">Por página</label>
                <select name="per_page" class="ar-input">
                    @foreach ([10, 25, 50, 100] as $n)
                        <option value="{{ $n }}" @selected($perPage == $n)>{{ $n }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end"><button class="ar-btn ar-btn-primary">Filtrar</button></div>
        </form>

        <p class="mb-2 text-sm">
            Mostrando <strong>{{ $movements->firstItem() ?? 0 }}–{{ $movements->lastItem() ?? 0 }}</strong>
            de <strong>{{ $movements->total() }}</strong> coincidencias
            (pendientes globales: {{ $progress['pending'] }}).
        </p>

        <div class="mb-6" x-data="{ selected: [] }">
            @can('categories.edit')
                <form method="POST" action="{{ route('chart-accounts.unclassified.bulk.preview') }}" class="ar-card mb-4 space-y-3 p-4">
                    @csrf
                    <h2 class="font-semibold">Clasificación masiva</h2>
                    <template x-for="id in selected" :key="id">
                        <input type="hidden" name="movement_ids[]" :value="id">
                    </template>
                    <div class="grid gap-2 md:grid-cols-4">
                        <div>
                            <label class="ar-label">Categoría</label>
                            <select name="category_id" class="ar-input">
                                <option value="">—</option>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="ar-label">Subcategoría</label>
                            <select name="subcategory_id" class="ar-input">
                                <option value="">—</option>
                                @foreach ($subcategories as $sub)
                                    <option value="{{ $sub->id }}">{{ $sub->category?->name }} / {{ $sub->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="ar-label">Cuenta del plan</label>
                            <select name="chart_account_id" class="ar-input">
                                <option value="">—</option>
                                @foreach ($chartAccounts as $ca)
                                    <option value="{{ $ca->id }}">{{ $ca->code }} — {{ $ca->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="ar-label">Guardar regla (concepto contiene)</label>
                            <input type="text" name="rule_condition_value" class="ar-input" placeholder="Ej. Spotify">
                            <label class="mt-1 flex items-center gap-2 text-xs">
                                <input type="checkbox" name="save_rule" value="1"> Usar para futuros
                            </label>
                        </div>
                    </div>
                    <p class="text-xs ar-muted"><span x-text="selected.length"></span> fila(s) seleccionada(s)</p>
                    <button class="ar-btn ar-btn-secondary" :disabled="selected.length === 0">Vista previa masiva</button>
                </form>
            @endcan

            @if (! empty($bulkPreview))
                <div class="ar-card mb-4 space-y-2 p-4" style="border-color: var(--ar-brand);">
                    <p class="font-semibold">Vista previa: esta clasificación afectará {{ $bulkPreview['would_affect'] }} movimiento(s)</p>
                    <ul class="list-disc ps-5 text-sm">
                        @foreach ($bulkPreview['sample'] ?? [] as $row)
                            <li>#{{ $row['id'] }} — {{ $row['description'] ?: '—' }}</li>
                        @endforeach
                    </ul>
                    <form method="POST" action="{{ route('chart-accounts.unclassified.bulk.apply') }}" class="space-y-2">
                        @csrf
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required> Confirmo aplicar</label>
                        <button class="ar-btn ar-btn-primary">Aplicar clasificación masiva</button>
                    </form>
                </div>
            @endif

            <div class="ar-card overflow-x-auto">
                <table class="ar-table text-sm">
                    <thead>
                        <tr>
                            @can('categories.edit')<th></th>@endcan
                            <th>Fecha</th>
                            <th>Concepto</th>
                            <th>Cliente/Proveedor</th>
                            <th>Importe</th>
                            <th>Tipo</th>
                            <th>Ámbito</th>
                            <th>Categoría</th>
                            <th>Subcategoría</th>
                            <th>Cuenta del plan</th>
                            <th>Cuenta financiera</th>
                            <th>Origen</th>
                            <th>Estado</th>
                            @can('categories.edit')<th>Acción</th>@endcan
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($movements as $m)
                            <tr>
                                @can('categories.edit')
                                    <td>
                                        <input type="checkbox" value="{{ $m->id }}"
                                               @change="selected.includes({{ $m->id }}) ? selected = selected.filter(i => i !== {{ $m->id }}) : selected.push({{ $m->id }})">
                                    </td>
                                @endcan
                                <td class="whitespace-nowrap">{{ $m->movement_date?->format('d/m/Y') }}</td>
                                <td>{{ $m->description ?: '—' }}</td>
                                <td>{{ $m->client?->name ?? $m->supplier?->name ?? '—' }}</td>
                                <td class="tabular-nums whitespace-nowrap">{{ number_format((float) $m->amount_ars, 2, ',', '.') }}</td>
                                <td>{{ $m->type?->label() }}</td>
                                <td>{{ $m->scope?->label() }}</td>
                                <td>{{ $m->category?->name ?? '—' }}</td>
                                <td>{{ $m->subcategory?->name ?? '—' }}</td>
                                <td>{{ $m->chartAccount ? ($m->chartAccount->code.' '.$m->chartAccount->name) : 'Sin asignar' }}</td>
                                <td>{{ $m->account?->name ?? '—' }}</td>
                                <td>{{ $m->import_batch_id ? 'Importación' : ($m->source_sheet ?: 'Manual') }}</td>
                                <td>Pendiente de categoría</td>
                                @can('categories.edit')
                                    <td class="min-w-[14rem]">
                                        <form method="POST" action="{{ route('chart-accounts.unclassified.classify', $m) }}" class="space-y-1">
                                            @csrf
                                            <select name="category_id" class="ar-input text-xs">
                                                <option value="">Categoría</option>
                                                @foreach ($categories as $cat)
                                                    <option value="{{ $cat->id }}" @selected($m->category_id == $cat->id)>{{ $cat->name }}</option>
                                                @endforeach
                                            </select>
                                            <select name="subcategory_id" class="ar-input text-xs">
                                                <option value="">Subcategoría</option>
                                                @foreach ($subcategories as $sub)
                                                    <option value="{{ $sub->id }}" @selected($m->subcategory_id == $sub->id)>{{ $sub->name }}</option>
                                                @endforeach
                                            </select>
                                            <select name="chart_account_id" class="ar-input text-xs">
                                                <option value="">Cuenta del plan</option>
                                                @foreach ($chartAccounts as $ca)
                                                    <option value="{{ $ca->id }}">{{ $ca->code }} — {{ $ca->name }}</option>
                                                @endforeach
                                            </select>
                                            <button class="ar-btn ar-btn-secondary text-xs">Resolver</button>
                                        </form>
                                    </td>
                                @endcan
                            </tr>
                        @empty
                            <tr><td colspan="14" class="ar-muted py-6 text-center">No hay movimientos pendientes de clasificación.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $movements->links() }}</div>
        </div>

        <div class="ar-card mb-6 space-y-3 p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-semibold">Asistente por patrón</h2>
                <x-page-help topic="chart_accounts.assistant" />
            </div>
            <p class="ar-muted text-sm">Agrupa pendientes por concepto/clasificación. Confianza ALTA permite masivo; MEDIA requiere revisión; BAJA no auto-aplica.</p>

            @if (! empty($patternPreview))
                <div class="rounded border p-3 text-sm" style="border-color: var(--ar-brand);">
                    <p class="font-semibold">Esta regla afectará {{ $patternPreview['would_affect'] }} movimiento(s)</p>
                    <form method="POST" action="{{ route('chart-accounts.unclassified.pattern.apply') }}" class="mt-2 space-y-2">
                        @csrf
                        <label class="flex items-center gap-2"><input type="checkbox" name="confirm" value="1" required> Confirmo aplicar patrón</label>
                        <button class="ar-btn ar-btn-primary">Aplicar patrón</button>
                    </form>
                </div>
            @endif

            <div class="max-h-96 space-y-2 overflow-y-auto">
                @forelse ($patterns as $p)
                    <div class="rounded border p-3 text-sm" style="border-color: var(--ar-border);">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <strong>{{ $p['label'] }}</strong>
                                · {{ $p['count'] }} movimientos
                                · confianza <span class="font-semibold">{{ $p['confidence'] }}</span>
                            </div>
                        </div>
                        @can('categories.edit')
                            <form method="POST" action="{{ route('chart-accounts.unclassified.pattern.preview') }}" class="mt-2 grid gap-2 md:grid-cols-5">
                                @csrf
                                @foreach ($p['sample_ids'] as $sid)
                                    <input type="hidden" name="movement_ids[]" value="{{ $sid }}">
                                @endforeach
                                <input type="hidden" name="pattern_value" value="{{ $p['pattern_value'] }}">
                                <input type="hidden" name="confidence" value="{{ $p['confidence'] }}">
                                <select name="category_id" class="ar-input text-xs">
                                    <option value="">Categoría</option>
                                    @foreach ($categories as $cat)
                                        <option value="{{ $cat->id }}" @selected($p['suggested_category_id'] == $cat->id)>{{ $cat->name }}</option>
                                    @endforeach
                                </select>
                                <select name="subcategory_id" class="ar-input text-xs">
                                    <option value="">Subcategoría</option>
                                    @foreach ($subcategories as $sub)
                                        <option value="{{ $sub->id }}" @selected($p['suggested_subcategory_id'] == $sub->id)>{{ $sub->name }}</option>
                                    @endforeach
                                </select>
                                <select name="chart_account_id" class="ar-input text-xs">
                                    <option value="">Cuenta del plan</option>
                                    @foreach ($chartAccounts as $ca)
                                        <option value="{{ $ca->id }}" @selected($p['suggested_chart_account_id'] == $ca->id)>{{ $ca->code }} — {{ $ca->name }}</option>
                                    @endforeach
                                </select>
                                <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="save_rule" value="1"> Regla futura</label>
                                <button class="ar-btn ar-btn-secondary text-xs" @disabled($p['confidence'] === 'BAJA')>Preview destino</button>
                            </form>
                        @endcan
                    </div>
                @empty
                    <p class="ar-muted text-sm">Sin patrones pendientes.</p>
                @endforelse
            </div>
        </div>
    @endif
</x-app-layout>
