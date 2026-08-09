<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">Nuevo usuario</h1>
    </x-slot>

    <form method="POST" action="{{ route('users.store') }}" class="ar-card mx-auto max-w-2xl space-y-4 p-6">
        @csrf
        @include('users._form', ['user' => null])
        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ route('users.index') }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button type="submit" class="ar-btn ar-btn-primary">Crear</button>
        </div>
    </form>
</x-app-layout>
