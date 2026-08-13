<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Nueva cuenta contable</h1></x-slot>
    <form method="POST" action="{{ route('chart-accounts.store') }}" class="ar-card mx-auto max-w-xl space-y-4 p-6" x-data="{ parentId: '{{ old('parent_id', $parentId) }}' }">
        @csrf
        <div>
            <label class="ar-label">Código</label>
            <input name="code" class="ar-input" value="{{ old('code') }}" required>
        </div>
        <div>
            <label class="ar-label">Nombre</label>
            <input name="name" class="ar-input" value="{{ old('name') }}" required>
        </div>
        <div>
            <label class="ar-label">Tipo</label>
            <select name="type" class="ar-input" required>
                @foreach ($types as $type)
                    <option value="{{ $type->value }}" @selected(old('type') === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="ar-label">Cuenta padre</label>
            <select name="parent_id" class="ar-input" x-model="parentId" required>
                <option value="">— Elegir padre (bajo una de las 5 raíces) —</option>
                @foreach ($parents as $p)
                    <option value="{{ $p->id }}">{{ $p->code }} — {{ $p->name }}</option>
                @endforeach
            </select>
            <p class="ar-muted mt-1 text-xs" x-show="parentId">
                Vista previa padre:
                <template x-if="parentId">
                    <span>
                        @foreach ($parents as $p)
                            <span x-show="parentId == '{{ $p->id }}'">{{ $p->code }} — {{ $p->name }} ({{ $p->typeLabel() }})</span>
                        @endforeach
                    </span>
                </template>
            </p>
        </div>
        <div>
            <label class="ar-label">Orden</label>
            <input type="number" name="sort_order" class="ar-input" value="{{ old('sort_order', 100) }}">
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))> Activa
        </label>
        @if (!empty($returnTo))
            <input type="hidden" name="return" value="{{ $returnTo }}">
        @endif
        <div class="flex justify-end gap-2">
            <a href="{{ ($returnTo ?? null) === 'mapping' ? route('chart-accounts.mapping') : route('chart-accounts.index') }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button class="ar-btn ar-btn-primary">Crear</button>
        </div>
    </form>
</x-app-layout>
