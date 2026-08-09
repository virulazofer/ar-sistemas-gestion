<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between gap-3">
            <h1 class="text-xl font-semibold">Editar producto</h1>
            <a href="{{ route('products.show', $product) }}" class="ar-btn ar-btn-secondary">Volver</a>
        </div>
    </x-slot>
    <form method="POST" action="{{ route('products.update', $product) }}" class="ar-card mx-auto max-w-3xl space-y-4 p-6">
        @csrf
        @method('PUT')
        @include('products._form', ['product' => $product])
        <div class="flex justify-end"><button class="ar-btn ar-btn-primary">Guardar cambios</button></div>
    </form>
</x-app-layout>
