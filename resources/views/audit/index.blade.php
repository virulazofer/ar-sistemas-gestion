<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-semibold">Auditoría</h1>
            <p class="ar-muted text-sm">Registro de acciones relevantes del sistema.</p>
        </div>
    </x-slot>

    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Usuario</th>
                    <th>Acción</th>
                    <th>Entidad</th>
                    <th>Descripción</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td>{{ $log->created_at?->format('d/m/Y H:i') }}</td>
                        <td>{{ $log->user?->name ?? '—' }}</td>
                        <td>{{ $log->action }}</td>
                        <td class="text-xs">
                            @if ($log->entity_type)
                                {{ class_basename($log->entity_type) }} #{{ $log->entity_id }}
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $log->description }}</td>
                        <td>{{ $log->ip_address }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="ar-muted py-6 text-center">Sin registros todavía.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $logs->links() }}</div>
</x-app-layout>
