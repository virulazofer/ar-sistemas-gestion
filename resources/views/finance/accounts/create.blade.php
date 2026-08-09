<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Nueva cuenta</h1></x-slot>

    <form method="POST" action="{{ route('accounts.store') }}" class="ar-card mx-auto max-w-xl space-y-4 p-6">
        @csrf
        <div>
            <label class="ar-label" for="name">Nombre</label>
            <input id="name" name="name" class="ar-input" value="{{ old('name') }}" required>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="ar-label" for="type">Tipo</label>
                <select id="type" name="type" class="ar-input" required>
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}" @selected(old('type') === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ar-label" for="currency_id">Moneda</label>
                <select id="currency_id" name="currency_id" class="ar-input" required>
                    @foreach ($currencies as $currency)
                        <option value="{{ $currency->id }}" @selected(old('currency_id') == $currency->id)>{{ $currency->code }} — {{ $currency->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div>
            <label class="ar-label" for="status">Estado</label>
            <select id="status" name="status" class="ar-input">
                <option value="active">Activa</option>
                <option value="inactive">Inactiva</option>
            </select>
        </div>
        <div>
            <label class="ar-label" for="external_identifier">Identificador opcional</label>
            <input id="external_identifier" name="external_identifier" class="ar-input" value="{{ old('external_identifier') }}">
        </div>
        <div>
            <label class="ar-label" for="description">Descripción</label>
            <textarea id="description" name="description" class="ar-input" rows="3">{{ old('description') }}</textarea>
        </div>
        <div class="flex justify-end gap-2">
            <a href="{{ route('accounts.index') }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button class="ar-btn ar-btn-primary">Crear</button>
        </div>
    </form>
</x-app-layout>
