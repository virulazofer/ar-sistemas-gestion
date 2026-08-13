<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-semibold">Editar {{ $account->code }}</h1>
            <p class="ar-muted text-sm">{{ $account->pathLabel() }}</p>
        </div>
    </x-slot>
    <div class="mx-auto grid max-w-3xl gap-4 lg:grid-cols-3">
        <form method="POST" action="{{ route('chart-accounts.update', $account) }}" class="ar-card space-y-4 p-6 lg:col-span-2">
            @csrf
            @method('PUT')
            @if ($account->isProtectedRoot())
                <p class="rounded border p-3 text-sm" style="border-color: var(--ar-border);">
                    Raíz estructural protegida: no se puede mover ni cambiar de naturaleza.
                </p>
            @endif
            <div>
                <label class="ar-label">Código</label>
                <input name="code" class="ar-input" value="{{ old('code', $account->code) }}" required @disabled($account->isProtectedRoot())>
                @if ($account->isProtectedRoot())
                    <input type="hidden" name="code" value="{{ $account->code }}">
                @endif
            </div>
            <div>
                <label class="ar-label">Nombre</label>
                <input name="name" class="ar-input" value="{{ old('name', $account->name) }}" required>
            </div>
            <div>
                <label class="ar-label">Tipo</label>
                <select name="type" class="ar-input" required @disabled($account->isProtectedRoot())>
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}" @selected(old('type', $account->type->value) === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
                @if ($account->isProtectedRoot())
                    <input type="hidden" name="type" value="{{ $account->type->value }}">
                @endif
            </div>
            <div>
                <label class="ar-label">Cuenta padre</label>
                <select name="parent_id" class="ar-input" @disabled($account->isProtectedRoot())>
                    @if ($account->isProtectedRoot())
                        <option value="">— Raíz —</option>
                    @else
                        <option value="">— Elegir padre —</option>
                    @endif
                    @foreach ($parents as $p)
                        <option value="{{ $p->id }}" @selected((int) old('parent_id', $account->parent_id) === $p->id)>{{ $p->code }} — {{ $p->name }}</option>
                    @endforeach
                </select>
                @error('parent_id')<p class="text-sm" style="color: var(--ar-danger);">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="ar-label">Ayuda contextual</label>
                <textarea name="help_text" class="ar-input" rows="2">{{ old('help_text', $account->help_text) }}</textarea>
            </div>
            <div>
                <label class="ar-label">Orden</label>
                <input type="number" name="sort_order" class="ar-input" value="{{ old('sort_order', $account->sort_order) }}">
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $account->is_active))> Activa
            </label>
            <div class="flex justify-end gap-2">
                <a href="{{ route('chart-accounts.index', ['account' => $account->id]) }}" class="ar-btn ar-btn-secondary">Cancelar</a>
                <button class="ar-btn ar-btn-primary">Guardar</button>
            </div>
        </form>
        <div class="space-y-4">
            <div class="ar-card space-y-2 p-5 text-sm">
                <h2 class="font-semibold">Usado por</h2>
                <p>Movimientos: <strong>{{ $usage['movements'] }}</strong></p>
                <p>Subcuentas: <strong>{{ $usage['children'] }}</strong></p>
                <p>Cuentas financieras: <strong>{{ $usage['financial_accounts'] }}</strong></p>
            </div>

            @unless ($account->isProtectedRoot())
            <div
                class="ar-card space-y-3 p-5 text-sm"
                x-data="{
                    open: false,
                    disposition: {{ $usage['movements'] > 0 ? "'reassign'" : "'delete'" }},
                    hasMovements: {{ $usage['movements'] > 0 ? 'true' : 'false' }},
                    confirmDelete() {
                        if (!this.open) { this.open = true; return false; }
                        if (this.disposition === 'cancel') { this.open = false; return false; }
                        if (this.hasMovements && this.disposition !== 'reassign') {
                            alert('Con movimientos la reasignación es obligatoria.');
                            return false;
                        }
                        return confirm('¿Confirmás eliminar esta cuenta?');
                    }
                }"
            >
                <h2 class="font-semibold" style="color: var(--ar-danger);">Eliminar cuenta</h2>
                @if ($usage['movements'] > 0)
                    <p class="text-xs">Esta cuenta contiene <strong>{{ $usage['movements'] }}</strong> movimientos. Debés reasignarlos.</p>
                @else
                    <p class="ar-muted text-xs">Cuenta vacía: se puede eliminar con confirmación.</p>
                @endif

                <form method="POST" action="{{ route('chart-accounts.destroy', $account) }}"
                      @submit="if (!confirmDelete()) $event.preventDefault()" class="space-y-3">
                    @csrf
                    @method('DELETE')
                    <div x-show="open" x-cloak class="space-y-3 rounded border p-3" style="border-color: var(--ar-border);">
                        @if ($usage['movements'] > 0)
                            <input type="hidden" name="disposition" value="reassign">
                            <label class="ar-label">Reasignar movimientos a</label>
                            <select name="reassign_to" class="ar-input" required>
                                <option value="">— Buscar / elegir —</option>
                                @foreach ($reassignTargets as $t)
                                    <option value="{{ $t->id }}">{{ $t->code }} — {{ $t->name }}</option>
                                @endforeach
                            </select>
                            @error('reassign_to')<p class="text-sm" style="color: var(--ar-danger);">{{ $message }}</p>@enderror
                        @else
                            <label class="flex items-start gap-2">
                                <input type="radio" name="disposition" value="delete" x-model="disposition" class="mt-1">
                                <span>Eliminar cuenta vacía</span>
                            </label>
                            <label class="flex items-start gap-2">
                                <input type="radio" name="disposition" value="cancel" x-model="disposition" class="mt-1">
                                <span>Cancelar</span>
                            </label>
                        @endif

                        @if ($usage['children'] > 0)
                            <div class="rounded border p-2 text-xs" style="border-color: var(--ar-border);">
                                <p class="mb-2">Hay <strong>{{ $usage['children'] }}</strong> subcuenta(s).</p>
                                <label class="flex items-start gap-2">
                                    <input type="radio" name="children_action" value="reparent" checked class="mt-0.5">
                                    <span>Reparentar hijas</span>
                                </label>
                                <label class="flex items-start gap-2 mt-1">
                                    <input type="radio" name="children_action" value="block" class="mt-0.5">
                                    <span>Bloquear mientras haya hijas</span>
                                </label>
                            </div>
                        @endif
                        @error('disposition')<p class="text-sm" style="color: var(--ar-danger);">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" class="ar-btn ar-btn-secondary w-full text-xs" style="color: var(--ar-danger);">
                        <span x-text="open ? 'Confirmar eliminación' : 'Eliminar…'"></span>
                    </button>
                </form>
            </div>
            @endunless
        </div>
    </div>
</x-app-layout>
