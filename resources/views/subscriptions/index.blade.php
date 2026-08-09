<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Abonos</h1>
                <p class="ar-muted text-sm">Servicios recurrentes · cargos idempotentes.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-page-help topic="subscriptions" />
                @can('subscriptions.generate')
                    <form method="POST" action="{{ route('subscriptions.generate-due') }}">@csrf<button class="ar-btn ar-btn-secondary">Generar vencidos</button></form>
                @endcan
                @can('subscriptions.create')
                    <a href="{{ route('subscriptions.create') }}" class="ar-btn ar-btn-primary">Nuevo abono</a>
                @endcan
            </div>
        </div>
    </x-slot>
    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead><tr><th>Cliente</th><th>Nombre</th><th>Period.</th><th>Importe</th><th>Estado</th><th>Próx.</th><th></th></tr></thead>
            <tbody>
                @forelse ($subscriptions as $sub)
                    <tr>
                        <td>{{ $sub->client->name }}</td>
                        <td>{{ $sub->name }}</td>
                        <td>{{ $sub->periodicity->label() }}</td>
                        <td>{{ $sub->currency_code }} {{ number_format((float) $sub->amount, 2, ',', '.') }}</td>
                        <td>{{ $sub->status->label() }}</td>
                        <td>{{ $sub->next_generation_on?->format('d/m/Y') }}</td>
                        <td class="text-right"><a href="{{ route('subscriptions.show', $sub) }}" style="color: var(--ar-brand);">Ver</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="ar-muted py-6 text-center">Sin abonos.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $subscriptions->links() }}</div>
</x-app-layout>
