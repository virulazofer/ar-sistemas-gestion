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
        <div class="ar-card space-y-2 p-5 text-sm">
            <h2 class="font-semibold">Usado por</h2>
            <p>Categorías: <strong>{{ $usage['categories'] }}</strong></p>
            <p>Subcategorías: <strong>{{ $usage['subcategories'] }}</strong></p>
            <p>Movimientos: <strong>{{ $usage['movements'] }}</strong></p>
            <p class="ar-muted text-xs">Ver también <a href="{{ url('/docs/plan-de-cuentas.md') }}" class="underline">docs/plan-de-cuentas.md</a> en el repositorio.</p>
        </div>
    </div>
</x-app-layout>
