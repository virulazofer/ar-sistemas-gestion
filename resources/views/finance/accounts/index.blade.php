<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">Cuentas financieras</h1>
            @can('accounts.create')
                <a href="{{ route('accounts.create') }}" class="ar-btn ar-btn-primary">Nueva cuenta</a>
            @endcan
        </div>
    </x-slot>

    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Tipo</th>
                    <th>Moneda</th>
                    <th>Estado</th>
                    <th class="text-right">Saldo</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($accounts as $account)
                    <tr>
                        <td>{{ $account->name }}</td>
                        <td>{{ $account->type->label() }}</td>
                        <td>{{ $account->currency->code }}</td>
                        <td>{{ $account->status }}</td>
                        <td class="text-right">{{ number_format((float) $account->computed_balance, 2, ',', '.') }}</td>
                        <td class="text-right">
                            @can('accounts.edit')
                                <a href="{{ route('accounts.edit', $account) }}" class="text-sm" style="color: var(--ar-brand);">Editar</a>
                            @endcan
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="ar-muted mt-3 text-xs">El saldo no es editable: se calcula desde movimientos confirmados.</p>
</x-app-layout>
