<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Nuevo cliente</h1></x-slot>
    <form method="POST" action="{{ route('clients.store') }}" class="ar-card mx-auto max-w-2xl space-y-4 p-6">
        @csrf
        @include('clients._form')
        <div class="flex justify-end gap-2">
            <a href="{{ route('clients.index') }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button class="ar-btn ar-btn-primary">Crear</button>
        </div>
    </form>
</x-app-layout>
