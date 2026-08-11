<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <h1 class="text-xl font-semibold">Movimientos</h1>
                <x-page-help topic="movements" />
            </div>
            @can('movements.create')
                <a href="{{ route('movements.quick') }}" class="ar-btn ar-btn-primary">Carga rápida</a>
            @endcan
        </div>
    </x-slot>

    <form class="mb-4 flex flex-wrap gap-2" method="GET">
        <select name="scope" class="ar-input w-auto">
            <option value="">Ámbito</option>
            <option value="personal" @selected(request('scope')==='personal')>Personal</option>
            <option value="professional" @selected(request('scope')==='professional')>Profesional</option>
        </select>
        <select name="type" class="ar-input w-auto">
            <option value="">Tipo</option>
            @foreach (config('finance.movement_types') as $value => $label)
                <option value="{{ $value }}" @selected(request('type')===$value)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="status" class="ar-input w-auto">
            <option value="">Estado</option>
            <option value="posted" @selected(request('status')==='posted')>Confirmado</option>
            <option value="voided" @selected(request('status')==='voided')>Anulado</option>
        </select>
        <button class="ar-btn ar-btn-secondary">Filtrar</button>
    </form>

    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Tipo</th>
                    <th>Ámbito</th>
                    <th>Cuenta</th>
                    <th>Categoría</th>
                    <th class="text-right">Importe</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($movements as $movement)
                    <tr>
                        <td><a href="{{ route('movements.show', $movement) }}" style="color: var(--ar-brand);">{{ $movement->movement_date?->format('d/m/Y') }}</a></td>
                        <td>{{ $movement->type->label() }}</td>
                        <td>{{ $movement->scope->label() }}</td>
                        <td>{{ $movement->account?->name }}</td>
                        <td>{{ $movement->category?->name }} {{ $movement->subcategory ? '/ '.$movement->subcategory->name : '' }}</td>
                        <td class="text-right">{{ number_format((float) $movement->amount, 2, ',', '.') }} {{ $movement->account?->currency?->code }}</td>
                        <td>{{ $movement->status->value }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $movements->links() }}</div>
</x-app-layout>
