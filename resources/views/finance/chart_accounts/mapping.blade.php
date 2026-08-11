<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <h1 class="text-xl font-semibold">Mapeo al plan de cuentas</h1>
                <x-page-help topic="chart_accounts.mapping" />
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('chart-accounts.index') }}" class="ar-btn ar-btn-secondary">Volver</a>
                @can('categories.create')
                    <a href="{{ route('chart-accounts.create', ['return' => 'mapping']) }}" class="ar-btn ar-btn-primary">Crear cuenta inline</a>
                @endcan
            </div>
        </div>
    </x-slot>

    <p class="ar-muted mb-4 text-sm">
        Reglas dinámicas: al crear movimientos se resuelve subcategoría → categoría → tipo → sin asignar.
        Materializar en movimientos históricos solo con <strong>preview + aplicar</strong> (no reescribe 762 uno a uno a mano).
        Distinción: cuenta contable ≠ cuenta financiera.
    </p>

    @if ($unassignedMovements > 0)
        <div class="mb-4 rounded border p-3 text-sm" style="border-color: var(--ar-danger, #b91c1c); color: var(--ar-danger, #b91c1c);">
            Alerta: <strong>{{ $unassignedMovements }}</strong> movimientos sin cuenta contable.
        </div>
    @endif

    @if (session('status'))
        <p class="ar-card mb-4 p-3 text-sm">{{ session('status') }}</p>
    @endif

    <div class="mb-6 grid gap-4 lg:grid-cols-2">
        <form method="POST" action="{{ route('chart-accounts.mapping.type-defaults') }}" class="ar-card space-y-3 p-4">
            @csrf
            <h2 class="font-semibold">Defaults por tipo de movimiento</h2>
            <div>
                <label class="ar-label">Ingreso →</label>
                <select name="income" class="ar-input">
                    <option value="">—</option>
                    @foreach ($chartAccounts as $ca)
                        <option value="{{ $ca->id }}" @selected(($typeDefaults['income'] ?? null) == $ca->id)>{{ $ca->code }} — {{ $ca->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ar-label">Gasto →</label>
                <select name="expense" class="ar-input">
                    <option value="">—</option>
                    @foreach ($chartAccounts as $ca)
                        <option value="{{ $ca->id }}" @selected(($typeDefaults['expense'] ?? null) == $ca->id)>{{ $ca->code }} — {{ $ca->name }}</option>
                    @endforeach
                </select>
            </div>
            <button class="ar-btn ar-btn-secondary">Guardar defaults</button>
        </form>

        <div class="ar-card space-y-3 p-4">
            <h2 class="font-semibold">Asistente: sin cuenta asignada</h2>
            <p class="text-sm">{{ $assistant['total_unmapped'] }} ítems (cat/sub) sin mapeo.</p>
            @if ($assistant['categories'])
                <p class="text-xs font-semibold">Categorías</p>
                <ul class="list-disc ps-5 text-sm">
                    @foreach ($assistant['categories'] as $row)
                        <li>{{ $row['name'] }} ({{ $row['scope'] }}) · movs sin cuenta: {{ $row['movement_count'] }}</li>
                    @endforeach
                </ul>
            @endif
            @if ($assistant['subcategories'])
                <p class="text-xs font-semibold">Subcategorías</p>
                <ul class="list-disc ps-5 text-sm">
                    @foreach ($assistant['subcategories'] as $row)
                        <li>{{ $row['category_name'] }} / {{ $row['name'] }} · movs sin cuenta: {{ $row['movement_count'] }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div class="mb-6 space-y-3">
        <h2 class="font-semibold">Asignar categoría / subcategoría</h2>
        @foreach ($categories as $category)
            <div class="ar-card space-y-2 p-4">
                <form method="POST" action="{{ route('chart-accounts.mapping.save') }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    <input type="hidden" name="target" value="category">
                    <input type="hidden" name="id" value="{{ $category->id }}">
                    <div class="min-w-[12rem] flex-1">
                        <p class="font-medium">{{ $category->name }} <span class="ar-muted text-xs">({{ $category->scope }})</span></p>
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
            <button class="ar-btn ar-btn-secondary">Vista previa (audit)</button>
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
