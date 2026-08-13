<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="ar-muted text-xs">Plan de cuentas · Reglas</p>
                <div class="flex items-center gap-2">
                    <h1 class="text-xl font-semibold">Reglas de clasificación automática</h1>
                    <x-page-help topic="imputation_rules" />
                </div>
            </div>
            @include('finance.chart_accounts._plan_tools_nav')
        </div>
    </x-slot>

    @if (session('status'))
        <p class="ar-card mb-4 p-3 text-sm">{{ session('status') }}</p>
    @endif

    <p class="ar-muted mb-4 text-sm">
        Condición → destino (categoría / subcategoría / cuenta del plan), con prioridad.
        Precedencia: <strong>1</strong> manual explícito · <strong>2</strong> subcategoría · <strong>3</strong> categoría · <strong>4</strong> regla genérica · <strong>5</strong> pendiente.
        Nunca se sobrescribe en silencio una clasificación manual confirmada.
    </p>

    @php
        $activeRules = $rules->where('is_active', true);
    @endphp

    @if ($activeRules->isEmpty())
        <div class="ar-card mb-6 p-6 text-center">
            <p class="font-semibold">No hay reglas automáticas activas.</p>
            <p class="ar-muted mt-2 text-sm">
                El sistema funciona sin ellas: la clasificación manual y la asignación cat/sub → plan bastan.
                Creá reglas solo cuando quieras acelerar casos repetidos (ej. concepto contiene «Spotify»).
            </p>
        </div>
    @endif

    @can('categories.edit')
        <form method="POST" action="{{ route('imputation-rules.store') }}" class="ar-card mb-6 grid gap-3 p-4 md:grid-cols-3">
            @csrf
            <h2 class="md:col-span-3 font-semibold">Nueva regla</h2>
            <div>
                <label class="ar-label">Nombre</label>
                <input name="name" class="ar-input" placeholder="Ej. Telecentro → Internet">
            </div>
            <div>
                <label class="ar-label">Condición</label>
                <select name="condition_type" class="ar-input" required>
                    <option value="description_contains">Concepto contiene</option>
                    <option value="exact_description">Concepto exacto</option>
                    <option value="movement_type">Tipo de movimiento</option>
                    <option value="category_name">Nombre de categoría</option>
                </select>
            </div>
            <div>
                <label class="ar-label">Valor</label>
                <input name="condition_value" class="ar-input" required placeholder="Telecentro / income / Super">
            </div>
            <div>
                <label class="ar-label">Categoría destino</label>
                <select name="target_category_id" class="ar-input">
                    <option value="">—</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ar-label">Subcategoría destino</label>
                <select name="target_subcategory_id" class="ar-input">
                    <option value="">—</option>
                    @foreach ($subcategories as $sub)
                        <option value="{{ $sub->id }}">{{ $sub->category?->name }} / {{ $sub->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ar-label">Cuenta del plan destino</label>
                <select name="target_chart_account_id" class="ar-input">
                    <option value="">—</option>
                    @foreach ($chartAccounts as $ca)
                        <option value="{{ $ca->id }}">{{ $ca->code }} — {{ $ca->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ar-label">Prioridad (menor = antes)</label>
                <input type="number" name="priority" class="ar-input" value="100" min="1" max="9999">
            </div>
            <div class="flex items-end gap-3">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" checked> Activa</label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="allow_manual_override" value="1" checked> Permite override</label>
            </div>
            <div class="flex items-end"><button class="ar-btn ar-btn-primary">Crear regla</button></div>
        </form>
    @endcan

    @if (! empty($preview))
        <div class="ar-card mb-4 space-y-2 p-4">
            <p class="font-semibold">
                Vista previa: {{ $preview['matched'] ?? $preview['would_affect'] ?? 0 }} coinciden
                · {{ $preview['manual'] ?? 0 }} manuales
                · {{ $preview['would_change'] ?? $preview['would_affect'] ?? 0 }} cambiarían
                · {{ $preview['intact'] ?? 0 }} intactos
            </p>
            <ul class="list-disc ps-5 text-sm">
                @foreach ($preview['sample'] ?? [] as $row)
                    <li>#{{ $row['id'] }} {{ $row['date'] }} — {{ $row['description'] ?: '—' }}@if(!empty($row['status'])) ({{ $row['status'] }})@endif</li>
                @endforeach
            </ul>
            @if (! empty($preview['rule_id']))
                <form method="POST" action="{{ route('imputation-rules.apply', $preview['rule_id']) }}" class="space-y-2">
                    @csrf
                    @if (! empty($preview['overwrite_manual']))
                        <input type="hidden" name="overwrite_manual" value="1">
                    @endif
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required> Confirmo aplicar</label>
                    <button class="ar-btn ar-btn-primary">Aplicar a movimientos</button>
                </form>
            @endif
        </div>
    @endif

    <div class="space-y-3">
        @forelse ($rules as $rule)
            <div class="ar-card space-y-2 p-4 text-sm">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="font-semibold">{{ $rule->name ?: 'Sin nombre' }}</p>
                        <p>Condición: {{ $rule->conditionLabel() }}</p>
                        <p>Destino: {{ $rule->destinationLabel() }}</p>
                        <p class="ar-muted">
                            Prioridad {{ $rule->priority }}
                            · {{ $rule->is_active ? 'Activa' : 'Inactiva' }}
                            · Coincidencias: {{ $rule->cached_match_count }}
                            · {{ $rule->creator?->name ?? '—' }}
                            · {{ $rule->created_at?->format('d/m/Y H:i') }}
                            · Override: {{ $rule->allow_manual_override ? 'sí' : 'no' }}
                        </p>
                    </div>
                    @can('categories.edit')
                        <div class="flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('imputation-rules.preview', $rule) }}">@csrf<button class="ar-btn ar-btn-secondary text-xs">Vista previa</button></form>
                            <form method="POST" action="{{ route('imputation-rules.destroy', $rule) }}" onsubmit="return confirm('¿Eliminar regla?')">@csrf @method('DELETE')<button class="ar-btn ar-btn-secondary text-xs">Eliminar</button></form>
                        </div>
                    @endcan
                </div>
            </div>
        @empty
            <p class="ar-muted text-sm">Todavía no hay reglas creadas.</p>
        @endforelse
    </div>
</x-app-layout>
