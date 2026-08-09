<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">{{ $user->name }}</h1>
            @can('users.edit')
                <a href="{{ route('users.edit', $user) }}" class="ar-btn ar-btn-primary">Editar</a>
            @endcan
        </div>
    </x-slot>

    <div class="ar-card max-w-2xl space-y-3 p-6">
        <p><span class="ar-muted">Usuario:</span> {{ $user->username }}</p>
        <p><span class="ar-muted">Email:</span> {{ $user->email }}</p>
        <p><span class="ar-muted">Estado:</span> {{ $user->status }}</p>
        <p><span class="ar-muted">Tema:</span> {{ $user->theme }}</p>
        <p><span class="ar-muted">Roles:</span> {{ $user->roles->pluck('name')->join(', ') ?: '—' }}</p>
    </div>
</x-app-layout>
