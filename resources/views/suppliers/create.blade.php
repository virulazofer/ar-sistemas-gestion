<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">Nuevo proveedor</h1>
            <a href="{{ route('suppliers.index') }}" class="ar-btn ar-btn-secondary">Cancelar</a>
        </div>
    </x-slot>

    <form method="POST" action="{{ route('suppliers.store') }}" class="ar-card mx-auto max-w-2xl space-y-4 p-6">
        @csrf
        @include('suppliers._form')
        <div class="flex justify-end">
            <button class="ar-btn ar-btn-primary">Guardar</button>
        </div>
    </form>
</x-app-layout>
