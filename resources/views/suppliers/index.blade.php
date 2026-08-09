<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Proveedores</h1>
                <p class="ar-muted text-sm">CC ARS / USD · compras contado o a crédito.</p>
            </div>
            @can('suppliers.create')
                <a href="{{ route('suppliers.create') }}" class="ar-btn ar-btn-primary">Nuevo proveedor</a>
            @endcan
        </div>
    </x-slot>

    <form method="GET" class="mb-4 flex gap-2">
        <input type="search" name="q" value="{{ $q }}" class="ar-input" placeholder="Buscar nombre, CUIT, email…">
        <button class="ar-btn ar-btn-secondary">Buscar</button>
    </form>

    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>CUIT / DNI</th>
                    <th>Email</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($suppliers as $supplier)
                    <tr>
                        <td>{{ $supplier->name }}</td>
                        <td>{{ $supplier->cuit ?: $supplier->dni ?: '—' }}</td>
                        <td>{{ $supplier->email ?: '—' }}</td>
                        <td>{{ $supplier->status === 'active' ? 'Activo' : 'Inactivo' }}</td>
                        <td class="text-right">
                            <a href="{{ route('suppliers.show', $supplier) }}" class="text-sm" style="color: var(--ar-brand);">Ver</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="ar-muted py-6 text-center">Sin proveedores.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $suppliers->links() }}</div>
</x-app-layout>
