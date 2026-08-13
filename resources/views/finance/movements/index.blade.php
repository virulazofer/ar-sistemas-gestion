<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <h1 class="text-xl font-semibold">Movimientos</h1>
                <x-page-help topic="movements" />
            </div>
            @can('movements.create')
                <a href="{{ route('movements.quick') }}" class="ar-btn ar-btn-primary">Carga rápida</a>
            @endcan
        </div>
    </x-slot>

    <form class="mb-4 flex flex-wrap gap-2" method="GET">
        <select name="scope" class="ar-input w-auto">
            <option value="">Ámbito</option>
            <option value="personal" @selected(request('scope')==='personal')>Personal</option>
            <option value="professional" @selected(request('scope')==='professional')>Profesional</option>
            <option value="financial" @selected(request('scope')==='financial')>Financiero</option>
            <option value="mixed" @selected(request('scope')==='mixed')>Mixto</option>
        </select>
        <select name="type" class="ar-input w-auto">
            <option value="">Tipo</option>
            @foreach (config('finance.movement_types') as $value => $label)
                <option value="{{ $value }}" @selected(request('type')===$value)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="status" class="ar-input w-auto">
            <option value="">Estado</option>
            <option value="posted" @selected(request('status')==='posted')>Confirmado</option>
            <option value="voided" @selected(request('status')==='voided')>Anulado</option>
        </select>
        <button class="ar-btn ar-btn-secondary">Filtrar</button>
    </form>

    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Descripción</th>
                    <th>Cuenta contable</th>
                    <th>Ámbito</th>
                    <th>Cuenta financiera</th>
                    <th class="text-right">Importe</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($movements as $movement)
                    @php
                        $signedDisplay = \App\Support\Money::mul(
                            (string) $movement->amount,
                            (string) $movement->type->signedMultiplier()
                        );
                        $amountClass = \App\Support\UiSemantics::cssClass($signedDisplay, \App\Support\UiSemantics::MODE_RESULT);
                        $chartLabel = $movement->chartAccount
                            ? trim(($movement->chartAccount->code ?? '').' '.($movement->chartAccount->name ?? ''))
                            : '—';
                        $scopeLabel = $movement->type->value === 'income'
                            ? ('Origen: '.$movement->scope->label())
                            : $movement->scope->label();
                        $showUrl = route('movements.show', $movement);
                    @endphp
                    <tr
                        class="mov-row"
                        style="cursor: pointer;"
                        onclick="window.location='{{ $showUrl }}'"
                        onkeydown="if(event.key==='Enter'){window.location='{{ $showUrl }}'}"
                        tabindex="0"
                        role="link"
                        aria-label="Ver movimiento {{ $movement->displayCode() }}"
                    >
                        <td>
                            <a href="{{ $showUrl }}" style="color: var(--ar-brand);" onclick="event.stopPropagation()">
                                {{ $movement->movement_date?->format('d/m/Y') }}
                            </a>
                        </td>
                        <td>
                            <a href="{{ $showUrl }}" style="color: inherit; text-decoration: underline;" onclick="event.stopPropagation()">
                                {{ $movement->description ?: '—' }}
                            </a>
                            <div class="ar-muted text-xs">{{ $movement->displayCode() }}</div>
                        </td>
                        <td class="text-xs">{{ $chartLabel }}</td>
                        <td>{{ $scopeLabel }}</td>
                        <td>{{ $movement->account?->name }}</td>
                        <td class="text-right {{ $amountClass }}">
                            {{ number_format((float) $signedDisplay, 2, ',', '.') }} {{ $movement->account?->currency?->code }}
                        </td>
                        <td class="text-right" onclick="event.stopPropagation()">
                            <a href="{{ $showUrl }}" class="ar-btn ar-btn-secondary text-xs">Ver</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <style>
        .mov-row:hover { background: var(--ar-surface-2, rgba(0,0,0,.04)); }
        .mov-row:focus-visible { outline: 2px solid var(--ar-brand); outline-offset: -2px; }
    </style>
    <p class="ar-muted mt-2 text-xs">
        <strong>Cuenta financiera</strong> = caja/banco/tarjeta donde vive el dinero.
        <strong>Cuenta contable</strong> = plan de cuentas (mapeo).
        En ingresos, la columna Ámbito muestra el Origen.
        Clic en la fila, descripción o «Ver» abre el detalle.
    </p>
    <div class="mt-4">{{ $movements->links() }}</div>
</x-app-layout>
