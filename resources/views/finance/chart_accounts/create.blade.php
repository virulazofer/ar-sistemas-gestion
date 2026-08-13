<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Nueva subcuenta</h1></x-slot>
    <form method="POST" action="{{ route('chart-accounts.store') }}" class="ar-card mx-auto max-w-xl space-y-4 p-6"
          x-data="{
              parentId: '{{ old('parent_id', $parentId) }}',
              code: '{{ old('code', $suggestedCode) }}',
              type: '{{ old('type', $parentType) }}',
              async onParentChange() {
                  if (!this.parentId) return
                  try {
                      const r = await fetch('{{ route('chart-accounts.suggest-code') }}?parent_id=' + this.parentId)
                      const j = await r.json()
                      this.code = j.code
                      if (j.type) this.type = j.type
                  } catch (e) {}
              }
          }"
          x-init="if (parentId && !code) onParentChange()">
        @csrf
        <div>
            <label class="ar-label">Cuenta padre</label>
            <select name="parent_id" class="ar-input" x-model="parentId" @change="onParentChange()" required>
                <option value="">— Elegir padre (bajo una de las 5 raíces) —</option>
                @foreach ($parents as $p)
                    <option value="{{ $p->id }}" data-type="{{ $p->type instanceof \BackedEnum ? $p->type->value : $p->type }}">
                        {{ $p->code }} — {{ $p->name }}
                    </option>
                @endforeach
            </select>
            <p class="ar-muted mt-1 text-xs">El sistema sugiere el próximo código disponible.</p>
        </div>
        <div>
            <label class="ar-label">Nombre</label>
            <input name="name" class="ar-input" value="{{ old('name') }}" required placeholder="Ej. Panadería">
        </div>
        <div>
            <label class="ar-label">Código sugerido</label>
            <input name="code" class="ar-input" x-model="code" required>
            @error('code')<p class="text-sm" style="color: var(--ar-danger);">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="ar-label">Tipo (naturaleza)</label>
            <select name="type" class="ar-input" x-model="type" required>
                @foreach ($types as $t)
                    <option value="{{ $t->value }}">{{ $t->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="ar-label">Descripción / ayuda (opcional)</label>
            <textarea name="help_text" class="ar-input" rows="2">{{ old('help_text') }}</textarea>
        </div>
        <div>
            <label class="ar-label">Orden</label>
            <input type="number" name="sort_order" class="ar-input" value="{{ old('sort_order', 100) }}">
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))> Activa
        </label>
        <div class="flex justify-end gap-2">
            <a href="{{ route('chart-accounts.index', array_filter(['account' => $parentId])) }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button class="ar-btn ar-btn-primary">Crear</button>
        </div>
    </form>
</x-app-layout>
