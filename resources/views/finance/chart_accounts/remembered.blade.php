<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Clasificaciones recordadas</h1>
                <p class="ar-muted text-sm">Patrón → Plan de cuentas + ámbito. No memorizan la cuenta financiera.</p>
            </div>
            <a href="{{ route('chart-accounts.advanced') }}" class="ar-btn ar-btn-secondary text-xs">Configuración avanzada</a>
        </div>
    </x-slot>

    @if (session('status'))
        <p class="mb-4 rounded border px-3 py-2 text-sm" style="border-color: var(--ar-border);">{{ session('status') }}</p>
    @endif

    <div class="ar-card overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="ar-muted text-left text-xs border-b" style="border-color: var(--ar-border);">
                    <th class="p-3">Patrón</th>
                    <th class="p-3">Clasificación</th>
                    <th class="p-3">Ámbito/Origen</th>
                    <th class="p-3">Tipo</th>
                    <th class="p-3">Último uso</th>
                    <th class="p-3">Estado</th>
                    <th class="p-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    <tr class="border-t" style="border-color: var(--ar-border);">
                        <td class="p-3">{{ $item->pattern_display }}</td>
                        <td class="p-3">{{ $item->classificationLabel() }}</td>
                        <td class="p-3">{{ config('finance.scopes.'.$item->scope, $item->scope ?: '—') }}</td>
                        <td class="p-3">{{ $item->movement_type === 'income' ? 'Ingreso' : 'Egreso' }}</td>
                        <td class="p-3">{{ $item->last_used_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="p-3">{{ $item->is_active ? 'Activa' : 'Inactiva' }}</td>
                        <td class="p-3 text-right space-x-2 whitespace-nowrap">
                            @if ($item->is_active)
                                <form method="POST" action="{{ route('remembered-classifications.deactivate', $item) }}" class="inline">
                                    @csrf
                                    <button class="underline text-xs">Desactivar</button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('remembered-classifications.destroy', $item) }}" class="inline" onsubmit="return confirm('¿Eliminar?')">
                                @csrf
                                @method('DELETE')
                                <button class="underline text-xs" style="color: var(--ar-danger);">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="p-4 ar-muted">Todavía no hay clasificaciones recordadas. Se crean al guardar una operación y responder «Sí» a recordar.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $items->links() }}</div>
</x-app-layout>
