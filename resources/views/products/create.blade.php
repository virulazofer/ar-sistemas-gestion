<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between gap-3">
            <h1 class="text-xl font-semibold">Nuevo producto</h1>
            <a href="{{ route('products.index') }}" class="ar-btn ar-btn-secondary">Cancelar</a>
        </div>
    </x-slot>
    <form method="POST" action="{{ route('products.store') }}" class="ar-card mx-auto max-w-3xl space-y-4 p-6">
        @csrf
        @include('products._form')
        <div class="flex justify-end"><button class="ar-btn ar-btn-primary">Guardar</button></div>
    </form>
</x-app-layout>
