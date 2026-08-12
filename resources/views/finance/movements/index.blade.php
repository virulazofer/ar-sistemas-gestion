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
                    <th>Cuenta financiera</th>
                    <th>Descripción</th>
                    <th>Ámbito</th>
                    <th>Cuenta contable</th>
                    <th class="text-right">Importe</th>
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
                        $catHint = $movement->category?->name;
                        if ($movement->subcategory) {
                            $catHint = ($catHint ? $catHint.' / ' : '').$movement->subcategory->name;
                        }
                    @endphp
                    <tr>
                        <td><a href="{{ route('movements.show', $movement) }}" style="color: var(--ar-brand);">{{ $movement->movement_date?->format('d/m/Y') }}</a></td>
                        <td>{{ $movement->account?->name }}</td>
                        <td>
                            {{ $movement->description ?: '—' }}
                            @if ($catHint)
                                <div class="ar-muted text-xs">Cat: {{ $catHint }}</div>
                            @endif
                        </td>
                        <td>{{ $movement->scope->label() }}</td>
                        <td class="text-xs">{{ $chartLabel }}</td>
                        <td class="text-right {{ $amountClass }}">
                            {{ number_format((float) $signedDisplay, 2, ',', '.') }} {{ $movement->account?->currency?->code }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="ar-muted mt-2 text-xs">
        <strong>Cuenta financiera</strong> = caja/banco/tarjeta donde vive el dinero.
        <strong>Cuenta contable</strong> = plan de cuentas (mapeo).
        La categoría operativa aparece bajo la descripción.
        Colores semánticos solo en presentación (ingreso verde / egreso rojo; en CC: rojo = nos deben). El signo en DB no se modifica.
    </p>
    <div class="mt-4">{{ $movements->links() }}</div>
</x-app-layout>
