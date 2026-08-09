<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">Editar usuario</h1>
    </x-slot>

    <form method="POST" action="{{ route('users.update', $user) }}" class="ar-card mx-auto max-w-2xl space-y-4 p-6">
        @csrf
        @method('PUT')
        @include('users._form', ['user' => $user])
        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ route('users.index') }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button type="submit" class="ar-btn ar-btn-primary">Guardar</button>
        </div>
    </form>
</x-app-layout>
