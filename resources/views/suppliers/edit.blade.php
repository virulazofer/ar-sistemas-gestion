<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">Editar proveedor</h1>
            <a href="{{ route('suppliers.show', $supplier) }}" class="ar-btn ar-btn-secondary">Volver</a>
        </div>
    </x-slot>

    <form method="POST" action="{{ route('suppliers.update', $supplier) }}" class="ar-card mx-auto max-w-2xl space-y-4 p-6">
        @csrf
        @method('PUT')
        @include('suppliers._form', ['supplier' => $supplier])
        <div class="flex justify-end">
            <button class="ar-btn ar-btn-primary">Guardar cambios</button>
        </div>
    </form>
</x-app-layout>
