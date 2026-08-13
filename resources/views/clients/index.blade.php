<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Clientes</h1>
                <p class="ar-muted text-sm">Cuentas corrientes ARS / USD independientes.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-page-help topic="clients" />
                <a href="{{ route('clients.current-accounts') }}" class="ar-btn ar-btn-secondary">Cuentas corrientes</a>
                @can('clients.create')
                    <a href="{{ route('clients.create') }}" class="ar-btn ar-btn-primary">Nuevo cliente</a>
                @endcan
            </div>
        </div>
    </x-slot>

    <form method="GET" class="mb-4 flex gap-2">
        <input type="search" name="q" value="{{ $q }}" class="ar-input" placeholder="Código (C001), nombre, CUIT, email…">
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
                @forelse ($clients as $client)
                    <tr>
                        <td>
                            <a href="{{ route('clients.show', $client) }}" class="font-medium" style="color: var(--ar-brand);">{{ $client->codeFormatted() }}</a>
                        </td>
                        <td>
                            <a href="{{ route('clients.show', $client) }}" style="color: var(--ar-brand);">{{ $client->name }}</a>
                        </td>
                        <td>{{ $client->cuit ?: $client->dni ?: '—' }}</td>
                        <td>{{ $client->email ?: '—' }}</td>
                        <td>{{ $client->status === 'active' ? 'Activo' : 'Inactivo' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="ar-muted py-6 text-center">Sin clientes.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $clients->links() }}</div>
</x-app-layout>
