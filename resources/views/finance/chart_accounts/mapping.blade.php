<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="ar-muted text-xs">Plan de cuentas · Asignación</p>
                <div class="flex items-center gap-2">
                    <h1 class="text-xl font-semibold">Asignación al plan de cuentas</h1>
                    <x-page-help topic="chart_accounts.mapping" />
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                @include('finance.chart_accounts._plan_tools_nav', ['progress' => $progress])
                @can('categories.create')
                    <a href="{{ route('chart-accounts.create', ['return' => 'mapping']) }}" class="ar-btn ar-btn-primary text-xs">Crear cuenta inline</a>
                @endcan
            </div>
        </div>
    </x-slot>

    <p class="ar-muted mb-4 text-sm">
        Vinculá categorías y subcategorías con cuentas del plan.
        Precedencia: manual explícito → subcategoría → categoría → regla automática / tipo → pendiente.
        Materializar en movimientos históricos solo con <strong>vista previa + confirmar</strong>.
        Por defecto <strong>no</strong> se sobrescribe una clasificación ya confirmada.
        Distinción: <strong>cuenta del plan</strong> ≠ <strong>cuenta financiera</strong>.
    </p>

    <div class="ar-card mb-4 grid gap-3 p-4 sm:grid-cols-4 text-sm">
        <div><p class="ar-muted text-xs">Totales</p><p class="font-semibold">{{ $progress['total'] }}</p></div>
        <div><p class="ar-muted text-xs">Clasificados</p><p class="font-semibold">{{ $progress['classified'] }}</p></div>
        <div><p class="ar-muted text-xs">Pendientes</p><p class="font-semibold">{{ $progress['pending'] }}</p></div>
        <div><p class="ar-muted text-xs">Resuelto</p><p class="font-semibold">{{ $progress['percent'] }}%</p></div>
    </div>

    @if ($unassignedMovements > 0)
        <a href="{{ route('chart-accounts.classify') }}" class="mb-4 block rounded border p-3 text-sm" style="border-color: var(--ar-danger, #b91c1c); color: var(--ar-danger, #b91c1c);">
            Alerta: <strong>{{ $unassignedMovements }}</strong> movimientos sin categoría operativa — Pendientes de clasificación
        </a>
    @endif

    @if (session('status'))
        <p class="ar-card mb-4 p-3 text-sm">{{ session('status') }}</p>
    @endif

    <div class="mb-6 grid gap-4 lg:grid-cols-2">
        <form method="POST" action="{{ route('chart-accounts.mapping.type-defaults') }}" class="ar-card space-y-3 p-4">
            @csrf
            <h2 class="font-semibold">Defaults por tipo (vía reglas)</h2>
            <p class="ar-muted text-xs">Se guardan como reglas reutilizables de clasificación automática.</p>
            <div>
                <label class="ar-label">Ingresos → cuenta del plan</label>
                <select name="income" class="ar-input">
                    <option value="">—</option>
                    @foreach ($chartAccounts as $ca)
                        <option value="{{ $ca->id }}" @selected(($typeDefaults['income'] ?? null) == $ca->id)>{{ $ca->code }} — {{ $ca->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ar-label">Egresos → cuenta del plan</label>
                <select name="expense" class="ar-input">
                    <option value="">—</option>
                    @foreach ($chartAccounts as $ca)
                        <option value="{{ $ca->id }}" @selected(($typeDefaults['expense'] ?? null) == $ca->id)>{{ $ca->code }} — {{ $ca->name }}</option>
                    @endforeach
                </select>
            </div>
            <button class="ar-btn ar-btn-secondary">Guardar defaults por tipo</button>
            <a href="{{ route('imputation-rules.index') }}" class="ar-btn ar-btn-secondary text-xs">Administrar reglas automáticas</a>
        </form>

        <div class="ar-card space-y-3 p-4">
            <h2 class="font-semibold">Pendientes de clasificación</h2>
            <p class="text-sm">Cola operativa distinta de esta asignación. {{ $assistant['total_unmapped'] }} ítems cat/sub sin cuenta del plan (opcional).</p>
            <a href="{{ route('chart-accounts.classify') }}" class="ar-btn ar-btn-primary text-xs">Ir a Pendientes</a>
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

    @if (($imputationRules ?? collect())->where('is_active', true)->isNotEmpty())
        <div class="ar-card mb-6 p-4">
            <h2 class="mb-2 font-semibold">Reglas automáticas activas (resumen)</h2>
            <ul class="list-disc ps-5 text-sm">
                @foreach ($imputationRules->where('is_active', true)->take(12) as $rule)
                    <li>{{ $rule->conditionLabel() }} → {{ $rule->destinationLabel() }} (prio {{ $rule->priority }})</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mb-6 space-y-3">
        <h2 class="font-semibold">Asignar categoría / subcategoría → cuenta del plan</h2>
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
        <p class="ar-muted text-sm">No recalcula FX. Solo actualiza la cuenta del plan según las reglas actuales. Requiere vista previa.</p>
        <form method="POST" action="{{ route('chart-accounts.mapping.preview') }}" class="space-y-2">
            @csrf
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="overwrite_manual" value="1">
                Incluir sobrescritura de clasificaciones ya confirmadas (no recomendado)
            </label>
            <button class="ar-btn ar-btn-secondary">Vista previa</button>
        </form>

        @if (! empty($preview))
            <div class="rounded border p-3 text-sm" style="border-color: var(--ar-border);">
                <p>
                    Candidatos: {{ $preview['total_candidates'] }}
                    · Coinciden (N): {{ $preview['matched'] ?? 0 }}
                    · Manuales (X): {{ $preview['manual'] ?? 0 }}
                    · Cambiarían (Y): {{ $preview['would_change'] }}
                    · Intactos (Z): {{ $preview['intact'] ?? $preview['unchanged'] }}
                </p>
                @if (! empty($preview['sample']))
                    <ul class="mt-2 list-disc ps-5">
                        @foreach ($preview['sample'] as $row)
                            <li>#{{ $row['id'] }} {{ $row['date'] }} · {{ $row['description'] ?: '—' }} · {{ $row['from'] ?? '∅' }} → {{ $row['to'] ?? '∅' }} ({{ $row['source'] }}{{ isset($row['status']) ? ' · '.$row['status'] : '' }})</li>
                        @endforeach
                    </ul>
                @endif
                <form method="POST" action="{{ route('chart-accounts.mapping.apply') }}" class="mt-3 space-y-2">
                    @csrf
                    @if (! empty($preview['overwrite_manual']))
                        <input type="hidden" name="overwrite_manual" value="1">
                    @endif
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="confirm" value="1" required>
                        Confirmo aplicar la asignación a los movimientos (sin tocar manuales salvo que lo haya marcado arriba)
                    </label>
                    <button class="ar-btn ar-btn-primary">Aplicar asignación</button>
                </form>
            </div>
        @endif
    </div>
</x-app-layout>
