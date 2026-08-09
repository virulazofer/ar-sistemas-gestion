<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">Categorías y subcategorías</h1>
    </x-slot>

    <div class="mb-6 grid gap-4 lg:grid-cols-2">
        @can('categories.create')
            <form method="POST" action="{{ route('categories.store') }}" class="ar-card space-y-3 p-5">
                @csrf
                <h2 class="font-semibold">Nueva categoría</h2>
                <input name="name" class="ar-input" placeholder="Nombre" required>
                <select name="scope" class="ar-input">
                    <option value="personal">Personal</option>
                    <option value="professional">Profesional</option>
                    <option value="both">Ambos</option>
                </select>
                <select name="chart_account_id" class="ar-input">
                    <option value="">Plan de cuentas (opcional)</option>
                    @foreach ($chartAccounts as $ca)
                        <option value="{{ $ca->id }}">{{ $ca->code }} — {{ $ca->name }}</option>
                    @endforeach
                </select>
                <button class="ar-btn ar-btn-primary">Crear categoría</button>
            </form>

            <form method="POST" action="{{ route('subcategories.store') }}" class="ar-card space-y-3 p-5">
                @csrf
                <h2 class="font-semibold">Nueva subcategoría</h2>
                <select name="category_id" class="ar-input" required>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }} ({{ $category->scope }})</option>
                    @endforeach
                </select>
                <input name="name" class="ar-input" placeholder="Nombre" required>
                <select name="chart_account_id" class="ar-input">
                    <option value="">Plan de cuentas (opcional)</option>
                    @foreach ($chartAccounts as $ca)
                        <option value="{{ $ca->id }}">{{ $ca->code }} — {{ $ca->name }}</option>
                    @endforeach
                </select>
                <button class="ar-btn ar-btn-primary">Crear subcategoría</button>
            </form>
        @endcan
    </div>

    <div class="space-y-4">
        @foreach ($categories as $category)
            <div class="ar-card p-4">
                <h2 class="font-semibold">{{ $category->name }} <span class="ar-muted text-sm">({{ $category->scope }})</span></h2>
                <p class="ar-muted text-xs">Plan: {{ $category->chartAccount?->code }} {{ $category->chartAccount?->name }}</p>
                <ul class="mt-2 list-disc ps-5 text-sm">
                    @forelse ($category->subcategories as $sub)
                        <li>{{ $sub->name }}</li>
                    @empty
                        <li class="ar-muted">Sin subcategorías</li>
                    @endforelse
                </ul>
            </div>
        @endforeach
    </div>
</x-app-layout>
