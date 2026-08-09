<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Equipos armados</h1>
                <p class="ar-muted text-sm">Consumo FIFO real · seriales · costo consolidado.</p>
            </div>
            <div class="flex gap-2">
                <x-page-help topic="equipment" />
                @can('equipment.view')
                    <a href="{{ route('equipment.types.index') }}" class="ar-btn ar-btn-secondary">Tipos / plantillas</a>
                @endcan
                @can('equipment.assemble')
                    <a href="{{ route('equipment.create') }}" class="ar-btn ar-btn-primary">Armar equipo</a>
                @endcan
            </div>
        </div>
    </x-slot>
    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead><tr><th>Código</th><th>Nombre</th><th>Tipo</th><th>Estado</th><th class="text-right">Costo USD</th><th></th></tr></thead>
            <tbody>
                @forelse ($equipments as $equipment)
                    <tr>
                        <td>{{ $equipment->code }}</td>
                        <td>{{ $equipment->name }}</td>
                        <td>{{ $equipment->type->name }}</td>
                        <td>{{ $equipment->status->label() }}</td>
                        <td class="text-right">{{ number_format((float) $equipment->total_cost_usd, 2, ',', '.') }}</td>
                        <td class="text-right"><a href="{{ route('equipment.show', $equipment) }}" style="color: var(--ar-brand);">Ver</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="ar-muted py-6 text-center">Sin equipos.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $equipments->links() }}</div>
</x-app-layout>
