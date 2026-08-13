<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">{{ $movement->displayCode() }}</h1>
                <p class="ar-muted text-sm">Detalle del movimiento · id interno #{{ $movement->id }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('movements.index') }}" class="ar-btn ar-btn-secondary">Volver al listado</a>
                @can('update', $movement)
                    <a href="{{ route('movements.edit', $movement) }}" class="ar-btn ar-btn-primary">Editar movimiento</a>
                @endcan
            </div>
        </div>
    </x-slot>

    @if (session('status'))
        <p class="mb-4 rounded border px-3 py-2 text-sm" style="border-color: var(--ar-border);">{{ session('status') }}</p>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="ar-card space-y-2 p-5 text-sm">
            <p><span class="ar-muted">Código:</span> <strong>{{ $movement->displayCode() }}</strong></p>
            <p><span class="ar-muted">Fecha:</span> {{ $movement->movement_date?->format('d/m/Y') }} {{ $movement->movement_time }}</p>
            <p><span class="ar-muted">Tipo:</span> {{ $movement->type->label() }}</p>
            <p><span class="ar-muted">{{ $movement->type->value === 'income' ? 'Origen' : 'Ámbito' }}:</span> {{ $movement->scope->label() }}</p>
            <p><span class="ar-muted">Cuenta financiera:</span> {{ $movement->account?->name }}</p>
            <p><span class="ar-muted">Moneda:</span> {{ $movement->currency?->code }}</p>
            <p><span class="ar-muted">Importe:</span> {{ number_format((float) $movement->amount, 2, ',', '.') }} {{ $movement->currency?->code }}</p>
            <p><span class="ar-muted">Cotización congelada (FX):</span> {{ $movement->exchange_rate_value }} ({{ $movement->exchange_rate_at }})</p>
            <p><span class="ar-muted">Equiv. ARS:</span> {{ number_format((float) $movement->amount_ars, 2, ',', '.') }}</p>
            <p><span class="ar-muted">Equiv. USD:</span> {{ number_format((float) $movement->amount_usd, 2, ',', '.') }}</p>
            <p><span class="ar-muted">Cuenta contable:</span>
                @if ($movement->chartAccount)
                    {{ $movement->chartAccount->code }} · {{ $movement->chartAccount->pathLabel() }}
                @else
                    —
                @endif
            </p>
            <p><span class="ar-muted">Categoría:</span> {{ $movement->category?->name ?? '—' }} / {{ $movement->subcategory?->name ?? '—' }}</p>
            <p><span class="ar-muted">Descripción:</span> {{ $movement->description ?? '—' }}</p>
            <p><span class="ar-muted">Observaciones:</span> {{ $movement->observations ?? '—' }}</p>
            <p><span class="ar-muted">Cliente:</span> {{ $movement->client?->labelWithCode() ?? '—' }}</p>
            <p><span class="ar-muted">Proveedor:</span> {{ $movement->supplier?->name ?? '—' }}</p>
            <p><span class="ar-muted">Estado:</span> {{ $movement->status->value === 'posted' ? 'Confirmado' : 'Anulado' }}</p>
            <p><span class="ar-muted">Usuario:</span> {{ $movement->user?->name ?? '—' }}</p>
            @if ($movement->transfer_id)
                <p><span class="ar-muted">ID transferencia:</span> {{ $movement->transfer_id }}</p>
            @endif
            @if ($movement->status->value === 'voided')
                <p><span class="ar-muted">Anulado por:</span> {{ $movement->voidedByUser?->name }} · {{ $movement->voided_at }}</p>
                <p><span class="ar-muted">Motivo:</span> {{ $movement->void_reason }}</p>
            @endif
        </div>

        <div class="space-y-4">
            @if ($pair)
                <div class="ar-card space-y-2 p-5 text-sm">
                    <h2 class="font-semibold">Pierna vinculada</h2>
                    <p>{{ $pair->displayCode() }} · {{ $pair->type->label() }} · {{ $pair->account?->name }} · {{ number_format((float) $pair->amount, 2, ',', '.') }}</p>
                    <a href="{{ route('movements.show', $pair) }}" style="color: var(--ar-brand);">Ver {{ $pair->displayCode() }}</a>
                </div>
            @endif

            @if (!empty($links))
                <div class="ar-card space-y-2 p-5 text-sm">
                    <h2 class="font-semibold">Relaciones</h2>
                    <ul class="list-disc ps-5">
                        @foreach ($links as $link)
                            <li>{{ $link['label'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($movement->editAudits->isNotEmpty())
                <div class="ar-card space-y-2 p-5 text-sm">
                    <h2 class="font-semibold">Auditoría de cambios</h2>
                    <div class="max-h-64 overflow-y-auto">
                        <table class="ar-table text-xs">
                            <thead>
                                <tr>
                                    <th>Campo</th>
                                    <th>Antes</th>
                                    <th>Después</th>
                                    <th>Usuario</th>
                                    <th>Cuando</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($movement->editAudits as $audit)
                                    <tr>
                                        <td>{{ $audit->field }}</td>
                                        <td>{{ $audit->old_value ?? '—' }}</td>
                                        <td>{{ $audit->new_value ?? '—' }}</td>
                                        <td>{{ $audit->user?->name ?? '—' }}</td>
                                        <td>{{ $audit->created_at?->format('d/m/Y H:i') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if ($movement->isPosted())
        @can('void', $movement)
            <form method="POST" action="{{ route('movements.void', $movement) }}" class="ar-card mt-4 max-w-xl space-y-3 p-5">
                @csrf
                <h2 class="font-semibold">Anular movimiento</h2>
                <p class="ar-muted text-sm">Acción separada de la edición. No se elimina: queda anulado y fuera de saldos. Si es transferencia, se anulan ambas piernas. Requiere motivo.</p>
                <textarea name="void_reason" class="ar-input" rows="3" required placeholder="Motivo de anulación"></textarea>
                @error('void_reason')
                    <p class="text-sm" style="color: var(--ar-danger);">{{ $message }}</p>
                @enderror
                <button class="ar-btn ar-btn-secondary" style="border-color: var(--ar-danger); color: var(--ar-danger);">Anular</button>
            </form>
        @endcan
    @endif
</x-app-layout>
