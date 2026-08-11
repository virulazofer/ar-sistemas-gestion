<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-2">
            <h1 class="text-xl font-semibold">Nueva cuenta</h1>
            <x-page-help topic="accounts" />
        </div>
    </x-slot>

    <form method="POST" action="{{ route('accounts.store') }}" class="ar-card mx-auto max-w-xl space-y-4 p-6">
        @csrf
        @include('finance.accounts._fields')
        <div class="flex justify-end gap-2">
            <a href="{{ route('accounts.index') }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button class="ar-btn ar-btn-primary">Crear</button>
        </div>
    </form>
</x-app-layout>
