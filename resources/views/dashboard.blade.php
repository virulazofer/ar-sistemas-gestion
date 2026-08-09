<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Inicio</h1>
                <p class="ar-muted text-sm">Núcleo del sistema — Etapa 1. La carga rápida de movimientos llegará en la Etapa 2.</p>
            </div>
        </div>
    </x-slot>

    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        <div class="ar-card p-5">
            <h2 class="mb-1 font-semibold">Sesión</h2>
            <p class="ar-muted text-sm">{{ auth()->user()->name }} ({{ auth()->user()->email }})</p>
            <p class="ar-muted mt-2 text-sm">Tema: {{ auth()->user()->theme }}</p>
            <p class="ar-muted text-sm">Roles: {{ auth()->user()->getRoleNames()->join(', ') ?: '—' }}</p>
        </div>

        @can('users.view')
            <a href="{{ route('users.index') }}" class="ar-card block p-5 transition hover:opacity-90">
                <h2 class="mb-1 font-semibold">Usuarios</h2>
                <p class="ar-muted text-sm">Administrar usuarios del sistema.</p>
            </a>
        @endcan

        @can('permissions.view')
            <a href="{{ route('permissions.index') }}" class="ar-card block p-5 transition hover:opacity-90">
                <h2 class="mb-1 font-semibold">Permisos</h2>
                <p class="ar-muted text-sm">Matriz de permisos por rol.</p>
            </a>
        @endcan

        @can('settings.view')
            <a href="{{ route('settings.edit') }}" class="ar-card block p-5 transition hover:opacity-90">
                <h2 class="mb-1 font-semibold">Configuración</h2>
                <p class="ar-muted text-sm">Parámetros generales de la aplicación.</p>
            </a>
        @endcan

        @can('audit.view')
            <a href="{{ route('audit.index') }}" class="ar-card block p-5 transition hover:opacity-90">
                <h2 class="mb-1 font-semibold">Auditoría</h2>
                <p class="ar-muted text-sm">Registro de acciones relevantes.</p>
            </a>
        @endcan
    </div>
</x-app-layout>
