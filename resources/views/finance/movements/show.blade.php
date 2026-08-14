<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">{{ $movement->displayCode() }}</h1>
                <p class="ar-muted text-sm">Detalle del movimiento</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('movements.index') }}" class="ar-btn ar-btn-secondary">Volver al listado</a>
                @can('update', $movement)
                    <a href="{{ route('movements.edit', $movement) }}" class="ar-btn ar-btn-primary">Editar</a>
                @endcan
            </div>
        </div>
    </x-slot>

    @if (session('status'))
        <p class="mb-4 rounded border px-3 py-2 text-sm" style="border-color: var(--ar-border);">{{ session('status') }}</p>
    @endif

    @php
        $fxReadable = number_format((float) $movement->exchange_rate_value, 2, ',', '.');
        $fxDateLabel = !empty($fxDate)
            ? \Carbon\Carbon::parse($fxDate)->format('d/m/Y')
            : ($movement->exchange_rate_at?->format('d/m/Y') ?? '—');
        $fieldLabels = [
            'movement_date' => 'Fecha',
            'movement_time' => 'Hora',
            'type' => 'Tipo',
            'scope' => 'Ámbito/Origen',
            'description' => 'Descripción',
            'observations' => 'Observaciones',
            'chart_account_id' => 'Cuenta contable',
            'category_id' => 'Categoría',
            'subcategory_id' => 'Subcategoría',
            'financial_account_id' => 'Cuenta financiera',
            'currency_id' => 'Moneda',
            'amount' => 'Importe',
            'exchange_rate_value' => 'Cotización',
            'exchange_rate_at' => 'Fecha cotización',
            'amount_ars' => 'Equiv. ARS',
            'amount_usd' => 'Equiv. USD',
            'client_id' => 'Cliente',
            'supplier_id' => 'Proveedor',
            'status' => 'Estado',
            'fx_mode' => 'Modo FX',
        ];
        $scopeLabels = [
            'personal' => 'Personal',
            'professional' => 'Profesional',
            'mixed' => 'Mixto',
            'financial' => 'Financiero',
        ];
        $formatAuditVal = function (?string $field, ?string $val) use ($scopeLabels) {
            if ($val === null || $val === '') {
                return '—';
            }
            if ($field === 'scope') {
                return $scopeLabels[$val] ?? $val;
            }
            if (in_array($field, ['amount', 'amount_ars', 'amount_usd', 'exchange_rate_value'], true) && is_numeric($val)) {
                $dec = $field === 'exchange_rate_value' ? 2 : 2;
                return '$ '.number_format((float) $val, $dec, ',', '.');
            }
            if ($field === 'movement_date' && preg_match('/^\d{4}-\d{2}-\d{2}/', $val)) {
                return \Carbon\Carbon::parse($val)->format('d/m/Y');
            }
            return $val;
        };
    @endphp

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="ar-card space-y-2 p-5 text-sm">
            <p><span class="ar-muted">Código:</span> <strong>{{ $movement->displayCode() }}</strong></p>
            <p><span class="ar-muted">Fecha:</span> {{ $movement->movement_date?->format('d/m/Y') }}{{ $movement->movement_time ? ' · '.$movement->movement_time : '' }}</p>
            <p><span class="ar-muted">Tipo:</span> {{ $movement->type->label() }}</p>
            <p><span class="ar-muted">{{ $movement->type->value === 'income' ? 'Origen' : 'Ámbito' }}:</span> {{ $movement->scope->label() }}</p>
            <p><span class="ar-muted">Descripción:</span> {{ $movement->description ?? '—' }}</p>
            @if ($movement->observations)
                <p><span class="ar-muted">Observaciones:</span> {{ $movement->observations }}</p>
            @endif
            <p><span class="ar-muted">Cuenta contable:</span>
                @if ($movement->chartAccount)
                    {{ $movement->chartAccount->pathLabel() }}
                    <span class="ar-muted">({{ $movement->chartAccount->code }})</span>
                @else
                    —
                @endif
            </p>
            <p><span class="ar-muted">Cuenta financiera:</span> {{ $movement->account?->name ?? '—' }}</p>
            <p><span class="ar-muted">Importe:</span>
                {{ number_format((float) $movement->amount, 2, ',', '.') }}
                {{ $movement->currency?->code ?? $movement->account?->currency?->code }}
            </p>
            <p><span class="ar-muted">Cotización utilizada:</span> USD 1 = ARS {{ $fxReadable }}</p>
            <p><span class="ar-muted">Fecha cotización:</span> {{ $fxDateLabel }}</p>
            @if (!empty($fxMismatch))
                <p class="rounded border px-2 py-1 text-xs" style="border-color: var(--ar-warning, #ca8a04); color: var(--ar-warning, #a16207);">
                    La cotización no corresponde a la fecha del movimiento ({{ $movement->movement_date?->format('d/m/Y') }}).
                </p>
            @endif
            <p><span class="ar-muted">Equivalente ARS:</span> {{ number_format((float) $movement->amount_ars, 2, ',', '.') }}</p>
            <p><span class="ar-muted">Equivalente USD:</span> {{ number_format((float) $movement->amount_usd, 2, ',', '.') }}</p>
            @if ($movement->client)
                <p><span class="ar-muted">Cliente:</span> {{ $movement->client->labelWithCode() }}</p>
            @endif
            @if ($movement->supplier)
                <p><span class="ar-muted">Proveedor:</span> {{ $movement->supplier->name }}</p>
            @endif
            <p><span class="ar-muted">Estado:</span> {{ $movement->status->value === 'posted' ? 'Confirmado' : 'Anulado' }}</p>
            @if ($movement->status->value === 'voided')
                <p><span class="ar-muted">Anulado por:</span> {{ $movement->voidedByUser?->name }} · {{ $movement->voided_at?->format('d/m/Y H:i') }}</p>
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

            @php $auditCount = $movement->editAudits->count(); @endphp
            <details class="ar-card p-5 text-sm" @if ($auditCount === 0) style="opacity:.85" @endif>
                <summary class="cursor-pointer font-semibold select-none">
                    Historial de cambios ({{ $auditCount }})
                </summary>
                @if ($auditCount === 0)
                    <p class="ar-muted mt-3">Sin cambios registrados.</p>
                @else
                    <ul class="mt-3 space-y-3">
                        @foreach ($movement->editAudits as $audit)
                            <li class="border-t pt-2" style="border-color: var(--ar-border);">
                                <div class="ar-muted text-xs">
                                    {{ $audit->created_at?->format('d/m/Y H:i') }} · {{ $audit->user?->name ?? '—' }}
                                </div>
                                <div class="mt-1">
                                    <strong>{{ $fieldLabels[$audit->field] ?? $audit->field }}:</strong>
                                    {{ $formatAuditVal($audit->field, $audit->old_value) }}
                                    →
                                    {{ $formatAuditVal($audit->field, $audit->new_value) }}
                                </div>
                                @if ($audit->reason)
                                    <div class="ar-muted mt-0.5 text-xs">Motivo: {{ $audit->reason }}</div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </details>
        </div>
    </div>

    @if ($movement->isPosted())
        @can('void', $movement)
            <form method="POST" action="{{ route('movements.void', $movement) }}" class="ar-card mt-4 max-w-xl space-y-3 p-5">
                @csrf
                <h2 class="font-semibold">Anular movimiento</h2>
                <p class="ar-muted text-sm">La anulación es distinta de la edición: usala cuando la operación no debía existir. Requiere motivo.</p>
                <textarea name="void_reason" class="ar-input" rows="3" required placeholder="Motivo de anulación"></textarea>
                @error('void_reason')
                    <p class="text-sm" style="color: var(--ar-danger);">{{ $message }}</p>
                @enderror
                <button class="ar-btn ar-btn-secondary" style="border-color: var(--ar-danger); color: var(--ar-danger);">Anular</button>
            </form>
        @endcan
    @endif
</x-app-layout>
