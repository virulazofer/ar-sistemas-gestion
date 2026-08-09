<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Editar cliente</h1></x-slot>
    <form method="POST" action="{{ route('clients.update', $client) }}" class="ar-card mx-auto max-w-2xl space-y-4 p-6">
        @csrf
        @method('PUT')
        @include('clients._form', ['client' => $client])
        <div class="flex justify-end gap-2">
            <a href="{{ route('clients.show', $client) }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button class="ar-btn ar-btn-primary">Guardar</button>
        </div>
    </form>
</x-app-layout>
