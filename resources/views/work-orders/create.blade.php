<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Nueva orden de trabajo</h1></x-slot>
    <form method="POST" action="{{ route('work-orders.store') }}" class="ar-card mx-auto max-w-3xl space-y-4 p-6">
        @csrf
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="ar-label">Cliente</label>
                <select name="client_id" class="ar-input" required>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}">{{ $client->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ar-label">Tipo</label>
                <select name="work_order_type_id" class="ar-input" required>
                    @foreach ($types as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div>
            <label class="ar-label">Título</label>
            <input name="title" class="ar-input" required value="{{ old('title') }}">
        </div>
        <div>
            <label class="ar-label">Descripción</label>
            <textarea name="description" class="ar-input" rows="3">{{ old('description') }}</textarea>
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="ar-label">Prioridad</label>
                <select name="priority" class="ar-input">
                    <option value="normal">Normal</option>
                    <option value="low">Baja</option>
                    <option value="high">Alta</option>
                    <option value="urgent">Urgente</option>
                </select>
            </div>
            <div>
                <label class="ar-label">Técnico</label>
                <select name="assigned_user_id" class="ar-input">
                    <option value="">—</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ar-label">Moneda</label>
                <select name="currency_code" class="ar-input">
                    <option value="USD">USD</option>
                    <option value="ARS">ARS</option>
                </select>
            </div>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="ar-label">Equipo registrado (opcional)</label>
                <select name="equipment_id" class="ar-input">
                    <option value="">—</option>
                    @foreach ($equipments as $equipment)
                        <option value="{{ $equipment->id }}">{{ $equipment->code }} — {{ $equipment->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ar-label">Equipo externo (etiqueta)</label>
                <input name="external_label" class="ar-input" placeholder="Notebook cliente…">
            </div>
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
            <div><label class="ar-label">Fabricante</label><input name="external_manufacturer" class="ar-input"></div>
            <div><label class="ar-label">Modelo</label><input name="external_model" class="ar-input"></div>
            <div><label class="ar-label">Serie</label><input name="external_serial" class="ar-input"></div>
        </div>
        <div class="flex justify-end gap-2">
            <a href="{{ route('work-orders.index') }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button class="ar-btn ar-btn-primary">Crear OT</button>
        </div>
    </form>
</x-app-layout>
