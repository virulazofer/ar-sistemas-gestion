<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <h1 class="text-xl font-semibold">Mapeo patrimonial (plan de cuentas)</h1>
                <x-page-help topic="chart_accounts.mapping" />
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('chart-accounts.index') }}" class="ar-btn ar-btn-secondary">Volver</a>
                <a href="{{ route('chart-accounts.classify') }}" class="ar-btn ar-btn-secondary">Clasificar movimientos</a>
                <a href="{{ route('imputation-rules.index') }}" class="ar-btn ar-btn-secondary">Reglas de imputación</a>
                @can('categories.create')
                    <a href="{{ route('chart-accounts.create', ['return' => 'mapping']) }}" class="ar-btn ar-btn-primary">Crear cuenta inline</a>
                @endcan
            </div>
        </div>
    </x-slot>

    <p class="ar-muted mb-4 text-sm">
        Reglas dinámicas: al crear movimientos se resuelve subcategoría → categoría → regla de imputación → tipo → sin asignar.
        Materializar en movimientos históricos solo con <strong>preview + aplicar</strong>.
        Distinción: <strong>cuenta contable</strong> ≠ <strong>cuenta financiera</strong>.
    </p>

    <div class="ar-card mb-4 grid gap-3 p-4 sm:grid-cols-4 text-sm">
        <div><p class="ar-muted text-xs">Totales</p><p class="font-semibold">{{ $progress['total'] }}</p></div>
        <div><p class="ar-muted text-xs">Clasificados</p><p class="font-semibold">{{ $progress['classified'] }}</p></div>
        <div><p class="ar-muted text-xs">Pendientes</p><p class="font-semibold">{{ $progress['pending'] }}</p></div>
        <div><p class="ar-muted text-xs">Resuelto</p><p class="font-semibold">{{ $progress['percent'] }}%</p></div>
    </div>

    @if ($unassignedMovements > 0)
        <a href="{{ route('chart-accounts.classify') }}" class="mb-4 block rounded border p-3 text-sm" style="border-color: var(--ar-danger, #b91c1c); color: var(--ar-danger, #b91c1c);">
            Alerta: <strong>{{ $unassignedMovements }}</strong> movimientos sin categoría operativa — Clasificar movimientos
        </a>
    @endif

    @if (session('status'))
        <p class="ar-card mb-4 p-3 text-sm">{{ session('status') }}</p>
    @endif

    <div class="mb-6 grid gap-4 lg:grid-cols-2">
        <form method="POST" action="{{ route('chart-accounts.mapping.type-defaults') }}" class="ar-card space-y-3 p-4">
            @csrf
            <h2 class="font-semibold">Reglas de imputación por tipo</h2>
            <p class="ar-muted text-xs">Equivalente operativo a «defaults por tipo»: se guardan también como reglas reutilizables.</p>
            <div>
                <label class="ar-label">Ingresos → cuenta contable</label>
                <select name="income" class="ar-input">
                    <option value="">—</option>
                    @foreach ($chartAccounts as $ca)
                        <option value="{{ $ca->id }}" @selected(($typeDefaults['income'] ?? null) == $ca->id)>{{ $ca->code }} — {{ $ca->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ar-label">Egresos → cuenta contable</label>
                <select name="expense" class="ar-input">
                    <option value="">—</option>
                    @foreach ($chartAccounts as $ca)
                        <option value="{{ $ca->id }}" @selected(($typeDefaults['expense'] ?? null) == $ca->id)>{{ $ca->code }} — {{ $ca->name }}</option>
                    @endforeach
                </select>
            </div>
            <button class="ar-btn ar-btn-secondary">Guardar reglas por tipo</button>
            <a href="{{ route('imputation-rules.index') }}" class="ar-btn ar-btn-secondary text-xs">Administrar todas las reglas</a>
        </form>

        <div class="ar-card space-y-3 p-4">
            <h2 class="font-semibold">Clasificar movimientos</h2>
            <p class="text-sm">Cola operativa distinta del mapeo patrimonial. {{ $assistant['total_unmapped'] }} ítems cat/sub sin cuenta contable opcional.</p>
            <a href="{{ route('chart-accounts.classify') }}" class="ar-btn ar-btn-primary text-xs">Ir a Clasificar movimientos</a>
            @if ($assistant['categories'])
                <p class="text-xs font-semibold">Categorías</p>
                <ul class="list-disc ps-5 text-sm">
                    @foreach ($assistant['categories'] as $row)
                        <li>{{ $row['name'] }} ({{ \App\Support\UiLabels::get($row['scope'], $row['scope']) }}) · movs sin cuenta: {{ $row['movement_count'] }}</li>
                    @endforeach
                </ul>
            @endif
            @if ($assistant['subcategories_by_category'] ?? [])
                <p class="text-xs font-semibold">Subcategorías (agrupadas)</p>
                <div class="max-h-64 space-y-2 overflow-y-auto text-sm">
                    @foreach ($assistant['subcategories_by_category'] as $catName => $rows)
                        <div>
                            <p class="font-medium">{{ $catName }}</p>
                            <ul class="list-disc ps-5">
                                @foreach ($rows as $row)
                                    <li>{{ $row['name'] }} · movs sin cuenta: {{ $row['movement_count'] }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    @if (($imputationRules ?? collect())->isNotEmpty())
        <div class="ar-card mb-6 p-4">
            <h2 class="mb-2 font-semibold">Reglas activas (resumen)</h2>
            <ul class="list-disc ps-5 text-sm">
                @foreach ($imputationRules->where('is_active', true)->take(12) as $rule)
                    <li>{{ $rule->conditionLabel() }} → {{ $rule->destinationLabel() }} (prio {{ $rule->priority }})</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mb-6 space-y-3">
        <h2 class="font-semibold">Asignar categoría / subcategoría → cuenta contable</h2>
        @foreach ($categories as $category)
            <div class="ar-card space-y-2 p-4">
                <form method="POST" action="{{ route('chart-accounts.mapping.save') }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    <input type="hidden" name="target" value="category">
                    <input type="hidden" name="id" value="{{ $category->id }}">
                    <div class="min-w-[12rem] flex-1">
                        <p class="font-medium">{{ $category->name }} <span class="ar-muted text-xs">({{ \App\Support\UiLabels::get($category->scope, $category->scope) }})</span></p>
                    </div>
                    <select name="chart_account_id" class="ar-input max-w-md">
                        <option value="">Sin asignar</option>
                        @foreach ($chartAccounts as $ca)
                            <option value="{{ $ca->id }}" @selected($category->chart_account_id == $ca->id)>{{ $ca->code }} — {{ $ca->name }}</option>
                        @endforeach
                    </select>
                    <button class="ar-btn ar-btn-secondary text-xs">Guardar</button>
                </form>
                @foreach ($category->subcategories as $sub)
                    <form method="POST" action="{{ route('chart-accounts.mapping.save') }}" class="ms-4 flex flex-wrap items-end gap-2 border-s ps-3 text-sm" style="border-color: var(--ar-border);">
                        @csrf
                        <input type="hidden" name="target" value="subcategory">
                        <input type="hidden" name="id" value="{{ $sub->id }}">
                        <div class="min-w-[10rem] flex-1">↳ {{ $sub->name }}</div>
                        <select name="chart_account_id" class="ar-input max-w-md">
                            <option value="">Hereda / sin asignar</option>
                            @foreach ($chartAccounts as $ca)
                                <option value="{{ $ca->id }}" @selected($sub->chart_account_id == $ca->id)>{{ $ca->code }} — {{ $ca->name }}</option>
                            @endforeach
                        </select>
                        <button class="ar-btn ar-btn-secondary text-xs">Guardar</button>
                    </form>
                @endforeach
            </div>
        @endforeach
    </div>

    <div class="ar-card space-y-3 p-4">
        <h2 class="font-semibold">Materializar en movimientos existentes</h2>
        <p class="ar-muted text-sm">No recalcula FX congelado. Solo actualiza <code>chart_account_id</code> según reglas actuales.</p>
        <form method="POST" action="{{ route('chart-accounts.mapping.preview') }}">
            @csrf
            <button class="ar-btn ar-btn-secondary">Vista previa (auditoría)</button>
        </form>

        @if (! empty($preview))
            <div class="rounded border p-3 text-sm" style="border-color: var(--ar-border);">
                <p>Candidatos: {{ $preview['total_candidates'] }} · Cambiarían: {{ $preview['would_change'] }} · Nuevos asignados: {{ $preview['would_assign'] }} · Sin cambio: {{ $preview['unchanged'] }}</p>
                @if (! empty($preview['sample']))
                    <ul class="mt-2 list-disc ps-5">
                        @foreach ($preview['sample'] as $row)
                            <li>#{{ $row['id'] }} {{ $row['date'] }} · {{ $row['description'] ?: '—' }} · {{ $row['from'] ?? '∅' }} → {{ $row['to'] ?? '∅' }} ({{ $row['source'] }})</li>
                        @endforeach
                    </ul>
                @endif
                <form method="POST" action="{{ route('chart-accounts.mapping.apply') }}" class="mt-3 space-y-2">
                    @csrf
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="confirm" value="1" required>
                        Confirmo aplicar el mapeo a los movimientos existentes
                    </label>
                    <button class="ar-btn ar-btn-primary">Aplicar mapeo</button>
                </form>
            </div>
        @endif
    </div>
</x-app-layout>
