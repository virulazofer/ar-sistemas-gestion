<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Proveedores</h1>
                <p class="ar-muted text-sm">CC ARS / USD · compras contado o a crédito.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-page-help topic="suppliers" />
                @can('suppliers.create')
                    <a href="{{ route('suppliers.create') }}" class="ar-btn ar-btn-primary">Nuevo proveedor</a>
                @endcan
            </div>
        </div>
    </x-slot>

    <form method="GET" class="mb-4 flex gap-2">
        <input type="search" name="q" value="{{ $q }}" class="ar-input" placeholder="Código (P001), nombre, CUIT, email…">
        <button class="ar-btn ar-btn-secondary">Buscar</button>
    </form>

    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Nombre</th>
                    <th>CUIT / DNI</th>
                    <th>Email</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($suppliers as $supplier)
                    <tr>
                        <td>
                            <a href="{{ route('suppliers.show', $supplier) }}" class="font-medium" style="color: var(--ar-brand);">{{ $supplier->codeFormatted() }}</a>
                        </td>
                        <td>
                            <a href="{{ route('suppliers.show', $supplier) }}" style="color: var(--ar-brand);">{{ $supplier->name }}</a>
                        </td>
                        <td>{{ $supplier->cuit ?: $supplier->dni ?: '—' }}</td>
                        <td>{{ $supplier->email ?: '—' }}</td>
                        <td>{{ $supplier->status === 'active' ? 'Activo' : 'Inactivo' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="ar-muted py-6 text-center">Sin proveedores.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $suppliers->links() }}</div>
</x-app-layout>
