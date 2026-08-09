<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Usuarios</h1>
                <p class="ar-muted text-sm">Alta y administración de usuarios del sistema.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-page-help topic="users" />
                @can('users.create')
                    <a href="{{ route('users.create') }}" class="ar-btn ar-btn-primary">Nuevo usuario</a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Usuario</th>
                    <th>Email</th>
                    <th>Estado</th>
                    <th>Roles</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr>
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->username }}</td>
                        <td>{{ $user->email }}</td>
                        <td>{{ $user->status === 'active' ? 'Activo' : 'Inactivo' }}</td>
                        <td>{{ $user->roles->pluck('name')->join(', ') ?: '—' }}</td>
                        <td class="space-x-2 text-right">
                            @can('users.view')
                                <a href="{{ route('users.show', $user) }}" class="text-sm" style="color: var(--ar-brand);">Ver</a>
                            @endcan
                            @can('users.edit')
                                <a href="{{ route('users.edit', $user) }}" class="text-sm" style="color: var(--ar-brand);">Editar</a>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="ar-muted py-6 text-center">No hay usuarios.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $users->links() }}</div>
</x-app-layout>
