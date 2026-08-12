<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Editar {{ $account->code }}</h1></x-slot>
    <div class="mx-auto grid max-w-3xl gap-4 lg:grid-cols-3">
        <form method="POST" action="{{ route('chart-accounts.update', $account) }}" class="ar-card space-y-4 p-6 lg:col-span-2">
            @csrf
            @method('PUT')
            <div>
                <label class="ar-label">Código</label>
                <input name="code" class="ar-input" value="{{ old('code', $account->code) }}" required>
            </div>
            <div>
                <label class="ar-label">Nombre</label>
                <input name="name" class="ar-input" value="{{ old('name', $account->name) }}" required>
            </div>
            <div>
                <label class="ar-label">Tipo</label>
                <select name="type" class="ar-input" required>
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}" @selected(old('type', $account->type->value) === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ar-label">Cuenta padre</label>
                <select name="parent_id" class="ar-input">
                    <option value="">— Raíz —</option>
                    @foreach ($parents as $p)
                        <option value="{{ $p->id }}" @selected((int) old('parent_id', $account->parent_id) === $p->id)>{{ $p->code }} — {{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ar-label">Orden</label>
                <input type="number" name="sort_order" class="ar-input" value="{{ old('sort_order', $account->sort_order) }}">
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $account->is_active))> Activa
            </label>
            <div class="flex justify-end gap-2">
                <a href="{{ route('chart-accounts.index') }}" class="ar-btn ar-btn-secondary">Cancelar</a>
                <button class="ar-btn ar-btn-primary">Guardar</button>
            </div>
        </form>
        <div class="space-y-4">
            <div class="ar-card space-y-2 p-5 text-sm">
                <h2 class="font-semibold">Usado por</h2>
                <p>Categorías: <strong>{{ $usage['categories'] }}</strong></p>
                <p>Subcategorías: <strong>{{ $usage['subcategories'] }}</strong></p>
                <p>Movimientos: <strong>{{ $usage['movements'] }}</strong></p>
                <p>Cuentas hijas: <strong>{{ $usage['children'] }}</strong></p>
                <p class="ar-muted text-xs">Ver también <code>docs/plan-de-cuentas.md</code>.</p>
            </div>

            <div
                class="ar-card space-y-3 p-5 text-sm"
                x-data="{
                    open: false,
                    disposition: 'reassign',
                    confirmDelete() {
                        if (!this.open) { this.open = true; return false; }
                        if (this.disposition === 'cancel') { this.open = false; return false; }
                        return confirm('¿Confirmás eliminar esta cuenta contable?');
                    }
                }"
            >
                <h2 class="font-semibold" style="color: var(--ar-danger);">Eliminar cuenta</h2>
                <p class="ar-muted text-xs">Eliminación real (no soft-delete). Elegí qué hacer con las referencias.</p>

                <form
                    method="POST"
                    action="{{ route('chart-accounts.destroy', $account) }}"
                    @submit="if (!confirmDelete()) $event.preventDefault()"
                    class="space-y-3"
                >
                    @csrf
                    @method('DELETE')

                    <div x-show="open" x-cloak class="space-y-3 rounded border p-3" style="border-color: var(--ar-border);">
                        <label class="flex items-start gap-2">
                            <input type="radio" name="disposition" value="reassign" x-model="disposition" class="mt-1">
                            <span>Reasignar categorías, subcategorías y movimientos a otra cuenta</span>
                        </label>
                        <div x-show="disposition === 'reassign'" class="pl-6">
                            <label class="ar-label">Cuenta destino</label>
                            <select name="reassign_to" class="ar-input">
                                <option value="">— Elegir —</option>
                                @foreach ($reassignTargets as $t)
                                    <option value="{{ $t->id }}">{{ $t->code }} — {{ $t->name }}</option>
                                @endforeach
                            </select>
                            @error('reassign_to')<p class="text-sm" style="color: var(--ar-danger);">{{ $message }}</p>@enderror
                        </div>
                        <label class="flex items-start gap-2">
                            <input type="radio" name="disposition" value="unassign" x-model="disposition" class="mt-1">
                            <span>Dejar sin asignar (chart_account_id = null)</span>
                        </label>
                        <label class="flex items-start gap-2">
                            <input type="radio" name="disposition" value="cancel" x-model="disposition" class="mt-1">
                            <span>Cancelar</span>
                        </label>

                        @if ($usage['children'] > 0)
                            <div class="rounded border p-2 text-xs" style="border-color: var(--ar-border);">
                                <p class="mb-2">Hay <strong>{{ $usage['children'] }}</strong> cuenta(s) hija(s).</p>
                                <label class="flex items-start gap-2">
                                    <input type="radio" name="children_action" value="reparent" checked class="mt-0.5">
                                    <span>Reparentar hijas (al destino si reasignás, o al padre actual)</span>
                                </label>
                                <label class="flex items-start gap-2 mt-1">
                                    <input type="radio" name="children_action" value="block" class="mt-0.5">
                                    <span>Bloquear eliminación mientras haya hijas</span>
                                </label>
                                @error('children_action')<p class="mt-1" style="color: var(--ar-danger);">{{ $message }}</p>@enderror
                            </div>
                        @endif
                    </div>

                    <button type="submit" class="ar-btn ar-btn-secondary w-full text-xs" style="color: var(--ar-danger);">
                        <span x-text="open ? 'Confirmar eliminación' : 'Eliminar…'"></span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
