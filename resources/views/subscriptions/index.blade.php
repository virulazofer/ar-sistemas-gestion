<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Abonos</h1>
                <p class="ar-muted text-sm">Servicios recurrentes · generan cargos (CHARGE), no ingresos.</p>
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

    @if ($subscriptions->isEmpty())
        <div class="ar-card mx-auto max-w-xl space-y-4 p-8 text-center">
            <h2 class="text-lg font-semibold">Todavía no hay abonos</h2>
            <p class="ar-muted text-sm">
                Un abono define un servicio recurrente. Al generar el período se crea un <strong>cargo (CHARGE)</strong>
                en la cuenta corriente del cliente — aumenta lo que nos deben — <strong>no</strong> un ingreso en caja/banco.
                El ingreso aparece recién cuando cobrás (recibo / carga rápida con «Aplicar a CC»).
            </p>
            <ul class="ar-muted mx-auto max-w-md list-disc space-y-1 pl-5 text-left text-sm">
                <li>Creá el abono desde cero (cliente, importe, periodicidad).</li>
                <li>«Generar vencidos» emite cargos idempotentes por período.</li>
                <li>No mueve stock ni registra dinero hasta el cobro.</li>
            </ul>
            @can('subscriptions.create')
                <a href="{{ route('subscriptions.create') }}" class="ar-btn ar-btn-primary">Crear primer abono</a>
            @endcan
        </div>
    @else
        <div class="ar-card overflow-x-auto">
            <table class="ar-table">
                <thead><tr><th>Cliente</th><th>Nombre</th><th>Period.</th><th>Importe</th><th>Estado</th><th>Próx.</th><th></th></tr></thead>
                <tbody>
                    @foreach ($subscriptions as $sub)
                        <tr>
                            <td>{{ $sub->client->name }}</td>
                            <td>{{ $sub->name }}</td>
                            <td>{{ $sub->periodicity->label() }}</td>
                            <td>{{ $sub->currency_code }} {{ number_format((float) $sub->amount, 2, ',', '.') }}</td>
                            <td>{{ $sub->status->label() }}</td>
                            <td>{{ $sub->next_generation_on?->format('d/m/Y') }}</td>
                            <td class="text-right"><a href="{{ route('subscriptions.show', $sub) }}" style="color: var(--ar-brand);">Ver</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $subscriptions->links() }}</div>
    @endif
</x-app-layout>
