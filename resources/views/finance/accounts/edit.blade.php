<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Editar cuenta</h1></x-slot>

    <form method="POST" action="{{ route('accounts.update', $account) }}" class="ar-card mx-auto max-w-xl space-y-4 p-6">
        @csrf
        @method('PUT')
        <div>
            <label class="ar-label" for="name">Nombre</label>
            <input id="name" name="name" class="ar-input" value="{{ old('name', $account->name) }}" required>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="ar-label" for="type">Tipo</label>
                <select id="type" name="type" class="ar-input" required>
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}" @selected(old('type', $account->type->value) === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ar-label">Moneda</label>
                <input class="ar-input" value="{{ $account->currency->code }}" disabled>
                <p class="ar-muted mt-1 text-xs">La moneda no se modifica después de crear la cuenta.</p>
            </div>
        </div>
        <div>
            <label class="ar-label" for="status">Estado</label>
            <select id="status" name="status" class="ar-input">
                <option value="active" @selected(old('status', $account->status) === 'active')>Activa</option>
                <option value="inactive" @selected(old('status', $account->status) === 'inactive')>Inactiva</option>
            </select>
        </div>
        <div>
            <label class="ar-label" for="external_identifier">Identificador opcional</label>
            <input id="external_identifier" name="external_identifier" class="ar-input" value="{{ old('external_identifier', $account->external_identifier) }}">
        </div>
        <div>
            <label class="ar-label" for="description">Descripción</label>
            <textarea id="description" name="description" class="ar-input" rows="3">{{ old('description', $account->description) }}</textarea>
        </div>
        <div class="flex justify-end gap-2">
            <a href="{{ route('accounts.index') }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button class="ar-btn ar-btn-primary">Guardar</button>
        </div>
    </form>
</x-app-layout>
