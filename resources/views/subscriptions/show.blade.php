<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">{{ $subscription->name }}</h1>
                <p class="ar-muted text-sm">{{ $subscription->client->name }} · {{ $subscription->periodicity->label() }} · {{ $subscription->status->label() }}</p>
            </div>
            <a href="{{ route('subscriptions.index') }}" class="ar-btn ar-btn-secondary">Listado</a>
        </div>
    </x-slot>

    <div class="mb-4 grid gap-4 sm:grid-cols-3">
        <div class="ar-card p-4">
            <p class="ar-muted text-sm">Importe</p>
            <p class="text-2xl font-bold">{{ $subscription->currency_code }} {{ number_format((float) $subscription->amount, 2, ',', '.') }}</p>
        </div>
        <div class="ar-card p-4 text-sm">
            <p><span class="ar-muted">Inicio:</span> {{ $subscription->starts_on?->format('d/m/Y') }}</p>
            <p><span class="ar-muted">Fin:</span> {{ $subscription->ends_on?->format('d/m/Y') ?: '—' }}</p>
            <p><span class="ar-muted">Próxima generación:</span> {{ $subscription->next_generation_on?->format('d/m/Y') }}</p>
        </div>
        <div class="space-y-2">
            @can('subscriptions.generate')
                <form method="POST" action="{{ route('subscriptions.generate', $subscription) }}" class="ar-card space-y-2 p-4">
                    @csrf
                    <label class="ar-label">Generar período (YYYY-MM)</label>
                    <input name="period_key" class="ar-input" placeholder="2026-09">
                    <button class="ar-btn ar-btn-primary w-full">Generar ahora</button>
                </form>
            @endcan
            @can('subscriptions.edit')
                <form method="POST" action="{{ route('subscriptions.status', $subscription) }}" class="ar-card flex gap-2 p-4">
                    @csrf
                    <select name="status" class="ar-input">
                        @foreach (\App\Enums\SubscriptionStatus::cases() as $st)
                            <option value="{{ $st->value }}" @selected($subscription->status === $st)>{{ $st->label() }}</option>
                        @endforeach
                    </select>
                    <button class="ar-btn ar-btn-secondary">Estado</button>
                </form>
            @endcan
        </div>
    </div>

    <div class="ar-card overflow-x-auto">
        <h2 class="border-b px-4 py-3 font-semibold" style="border-color: var(--ar-border);">Períodos / cargos</h2>
        <table class="ar-table">
            <thead><tr><th>Clave</th><th>Desde</th><th>Hasta</th><th class="text-right">Importe</th><th>CC</th><th>Generado</th></tr></thead>
            <tbody>
                @forelse ($subscription->periods as $period)
                    <tr>
                        <td>{{ $period->period_key }}</td>
                        <td>{{ $period->period_start?->format('d/m/Y') }}</td>
                        <td>{{ $period->period_end?->format('d/m/Y') }}</td>
                        <td class="text-right">{{ $period->currency_code }} {{ number_format((float) $period->amount, 2, ',', '.') }}</td>
                        <td>#{{ $period->client_ledger_entry_id }}</td>
                        <td>{{ $period->generated_at?->format('d/m/Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="ar-muted py-6 text-center">Sin períodos generados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
