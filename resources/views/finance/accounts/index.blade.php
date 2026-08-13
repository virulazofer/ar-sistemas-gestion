<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">Cuentas financieras</h1>
            <div class="flex flex-wrap items-center gap-2">
                <x-page-help topic="accounts" />
                @if ($showInactive)
                    <a href="{{ route('accounts.index') }}" class="ar-btn ar-btn-secondary text-xs">Solo activas</a>
                @else
                    <a href="{{ route('accounts.index', ['inactive' => 1]) }}" class="ar-btn ar-btn-secondary text-xs">Ver inactivas</a>
                @endif
                @can('accounts.create')
                    <a href="{{ route('accounts.create') }}" class="ar-btn ar-btn-primary">Nueva cuenta</a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Tipo</th>
                    <th>Moneda</th>
                    <th>CBU/CVU / Tarjeta</th>
                    <th class="text-right">Saldo</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($accounts as $account)
                    @php
                        $isCard = $account->type->value === 'credit_card';
                        $reliable = (bool) ($account->balance_reliable ?? true);
                        $bal = (string) ($account->computed_balance ?? '0');
                        $mode = $isCard
                            ? \App\Support\UiSemantics::MODE_LIABILITY
                            : \App\Support\UiSemantics::MODE_ASSET;
                    @endphp
                    <tr>
                        <td>
                            {{ $account->name }}
                            @if ($account->status !== 'active')
                                <span class="ar-muted text-xs">(inactiva)</span>
                            @endif
                        </td>
                        <td>{{ $account->type->label() }}</td>
                        <td>{{ $account->currency->code ?? '—' }}</td>
                        <td class="text-xs">
                            @if ($isCard)
                                {{ $account->card_brand ?: 'Tarjeta' }} ·····{{ $account->card_last4 ?: '????' }}
                                @if ($account->card_holder)<br>{{ $account->card_holder }}@endif
                                @if ($account->card_expiry_month && $account->card_expiry_year)
                                    <br>{{ str_pad((string) $account->card_expiry_month, 2, '0', STR_PAD_LEFT) }}/{{ $account->card_expiry_year }}
                                @endif
                            @else
                                {{ $account->cbu_cvu ?: '—' }}
                                @if ($account->cuit)<br>CUIT {{ \App\Rules\Cuit::format($account->cuit) }}@endif
                            @endif
                        </td>
                        <td class="text-right">
                            @if (! $reliable)
                                <span class="ar-muted text-sm">Saldo no disponible</span>
                            @else
                                <span class="tabular-nums {{ \App\Support\UiSemantics::cssClass($bal, $mode) }}">
                                    {{ number_format((float) $bal, 2, ',', '.') }}
                                </span>
                            @endif
                        </td>
                        <td class="text-right">
                            @can('accounts.edit')
                                <a href="{{ route('accounts.edit', $account) }}" class="text-sm" style="color: var(--ar-brand);">Editar</a>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="ar-muted py-6 text-center">Sin cuentas{{ $showInactive ? '' : ' activas' }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="ar-muted mt-3 text-xs">El saldo no es editable: se calcula desde movimientos confirmados. Las tarjetas no almacenan CVV ni PAN completo. Listado por defecto: solo activas. El Plan de cuentas las refleja por tipo (vista derivada), sin duplicar el maestro.</p>
</x-app-layout>
