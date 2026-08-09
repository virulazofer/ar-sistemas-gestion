<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Resolución preview histórico</h1>
                <p class="ar-muted text-sm">Lote #{{ $batch->id }} · {{ $batch->original_filename }} · sin importar movimientos</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('imports.show', $batch) }}" class="ar-btn ar-btn-secondary">Volver al lote</a>
                @can('imports.execute')
                    <form method="POST" action="{{ route('imports.historical.reprocess', $batch) }}">
                        @csrf
                        <button class="ar-btn ar-btn-primary">Reprocesar ahora</button>
                    </form>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="ar-card mb-4 border border-amber-300 p-4 text-sm">
        Confirmación definitiva de movimientos históricos <strong>bloqueada</strong>. Todo lo que apruebes aquí solo afecta el preview.
    </div>

    <div class="ar-card mb-4 grid gap-3 p-4 sm:grid-cols-4 text-sm">
        <div>Verde: <strong>{{ $batch->rows_green }}</strong></div>
        <div>Amarillo: <strong>{{ $batch->rows_yellow }}</strong></div>
        <div>Rojo: <strong>{{ $batch->rows_red }}</strong></div>
        <div>Ingresos diff: <strong>{{ number_format((float) ($batch->reconciliation_payload['differences']['ingresos'] ?? 0), 2, ',', '.') }}</strong></div>
        <div>CC IN diff: <strong>{{ number_format((float) ($batch->reconciliation_payload['differences']['cc_in'] ?? 0), 2, ',', '.') }}</strong></div>
        <div>CC OUT diff: <strong>{{ number_format((float) ($batch->reconciliation_payload['differences']['cc_out'] ?? 0), 2, ',', '.') }}</strong></div>
        <div>Egresos diff: <strong>{{ number_format((float) ($batch->reconciliation_payload['differences']['egresos'] ?? 0), 2, ',', '.') }}</strong></div>
        <div>Decisiones aplicadas: <strong>{{ $batch->preview_payload['decisions_applied'] ?? 0 }}</strong></div>
    </div>

    @if ($evolution)
        <div class="ar-card mb-4 p-4 text-sm">
            <h2 class="mb-2 font-semibold">Evolución</h2>
            <p>ANTES: {{ $evolution['before']['green'] ?? '?' }} / {{ $evolution['before']['yellow'] ?? '?' }} / {{ $evolution['before']['red'] ?? '?' }}</p>
            <p>AHORA: {{ $evolution['after']['green'] ?? '?' }} / {{ $evolution['after']['yellow'] ?? '?' }} / {{ $evolution['after']['red'] ?? '?' }}</p>
        </div>
    @endif

    {{-- FECHAS --}}
    <section class="ar-card mb-6 p-4" id="fechas">
        <h2 class="mb-3 font-semibold">1. Fechas sospechosas ({{ count($queues['dates']) }})</h2>
        <p class="ar-muted mb-3 text-xs">No se corrige 2025→2026 automáticamente. Formato definitivo AAAA-MM-DD.</p>
        @can('imports.execute')
            <form method="POST" action="{{ route('imports.historical.decide-dates', $batch) }}">
                @csrf
                <div class="overflow-x-auto">
                    <table class="ar-table text-xs">
                        <thead>
                            <tr>
                                <th>Fila</th><th>Fecha orig.</th><th>Sugerida</th><th>Concepto</th><th>Cat.</th><th>Importe</th><th>Motivo</th><th>Acción</th><th>Corregir a</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($queues['dates'] as $i => $row)
                                <tr>
                                    <td>{{ $row['source_row'] }}
                                        <input type="hidden" name="decisions[{{ $i }}][source_row]" value="{{ $row['source_row'] }}">
                                    </td>
                                    <td>{{ $row['date'] ?? '—' }}</td>
                                    <td>{{ $row['suggested_date'] ?? '—' }}</td>
                                    <td>{{ \Illuminate\Support\Str::limit($row['concepto'] ?? '', 40) }}</td>
                                    <td>{{ $row['excel_cuenta_category'] ?? '' }}</td>
                                    <td class="text-right">{{ number_format((float) ($row['display_amount'] ?? 0), 2, ',', '.') }}</td>
                                    <td>{{ $row['suspicion_reason'] ?? '' }}</td>
                                    <td>
                                        <select name="decisions[{{ $i }}][action]" class="ar-input">
                                            <option value="skip" @selected(empty($row['decision']))>Pendiente</option>
                                            <option value="accept" @selected(($row['decision']['action'] ?? '') === 'accept')>Aceptar original</option>
                                            <option value="correct" @selected(($row['decision']['action'] ?? '') === 'correct')>Corregir</option>
                                            <option value="exclude" @selected(($row['decision']['action'] ?? '') === 'exclude')>Excluir</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" name="decisions[{{ $i }}][corrected_date]" class="ar-input" placeholder="AAAA-MM-DD"
                                               value="{{ $row['decision']['corrected_date'] ?? ($row['suggested_date'] ?? '') }}">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <button class="ar-btn ar-btn-primary mt-3">Aplicar fechas y reprocesar</button>
            </form>
        @endcan
    </section>

    {{-- VENTAS COMPLEJAS --}}
    <section class="ar-card mb-6 space-y-4 p-4" id="complejas">
        <h2 class="font-semibold">2. Ventas complejas ({{ count($queues['complex']) }})</h2>
        <p class="ar-muted text-xs">Objetivo: explicar CC IN 2.072.700 y CC OUT 1.568.700 residuales. Sin ajustes artificiales. Sin ejecutar importación.</p>
        @foreach ($queues['complex'] as $row)
            @php($p = $row['proposed_components'] ?? [])
            @php($d = $row['decision'] ?? null)
            <div class="rounded border border-[var(--ar-border)] p-3 text-sm">
                <div class="mb-2 font-medium">Fila {{ $row['source_row'] }} · {{ $row['date'] }} · {{ $row['concepto'] }}</div>
                <div class="mb-2 grid gap-1 text-xs sm:grid-cols-3">
                    <div>Cliente: {{ $row['client'] ?? '—' }}</div>
                    <div>Cuenta fin.: {{ $row['excel_subcuenta_account'] ?? '—' }}</div>
                    <div>Categoría: {{ $row['excel_cuenta_category'] ?? '—' }}</div>
                    <div>Ingresos: {{ number_format((float) ($row['amounts']['ingresos'] ?? 0), 2, ',', '.') }}</div>
                    <div>Egresos: {{ number_format((float) ($row['amounts']['egresos'] ?? 0), 2, ',', '.') }}</div>
                    <div>CC IN: {{ number_format((float) ($row['amounts']['cc_in'] ?? 0), 2, ',', '.') }}</div>
                    <div>CC OUT: {{ number_format((float) ($row['amounts']['cc_out'] ?? 0), 2, ',', '.') }}</div>
                    <div>Merca IN: {{ number_format((float) ($row['amounts']['merca_in'] ?? 0), 2, ',', '.') }}</div>
                    <div>Merca OUT: {{ number_format((float) ($row['amounts']['merca_out'] ?? 0), 2, ',', '.') }}</div>
                    <div>Venta: {{ number_format((float) ($row['amounts']['venta'] ?? 0), 2, ',', '.') }}</div>
                    <div>Utilidad: {{ number_format((float) ($row['amounts']['ut_ventas'] ?? 0), 2, ',', '.') }}</div>
                </div>
                <p class="mb-2 text-xs font-semibold">Interpretación propuesta por AR Sistemas</p>
                <ul class="mb-3 text-xs ar-muted">
                    <li>VENTA: {{ number_format((float) ($p['venta'] ?? 0), 2, ',', '.') }}</li>
                    <li>COBRO: {{ number_format((float) ($p['cobro'] ?? 0), 2, ',', '.') }}</li>
                    <li>CC (cargo/cobro): {{ number_format((float) ($p['cc_charge'] ?? 0), 2, ',', '.') }} / {{ number_format((float) ($p['cc_payment'] ?? 0), 2, ',', '.') }}</li>
                    <li>MERCADERÍA ENTREGADA: {{ number_format((float) ($p['merca_out'] ?? 0), 2, ',', '.') }}</li>
                    <li>MERCADERÍA RECIBIDA: {{ number_format((float) ($p['merca_in'] ?? 0), 2, ',', '.') }}</li>
                    <li>UTILIDAD: {{ number_format((float) ($p['utilidad'] ?? 0), 2, ',', '.') }}</li>
                </ul>
                @if ($d)
                    <p class="mb-2 text-xs text-green-700">Ya resuelta en preview.</p>
                @endif
                @can('imports.execute')
                    <form method="POST" action="{{ route('imports.historical.decide-complex', $batch) }}" class="grid gap-2 sm:grid-cols-4">
                        @csrf
                        <input type="hidden" name="source_row" value="{{ $row['source_row'] }}">
                        <label class="text-xs">VENTA<input class="ar-input" name="venta" type="number" step="0.01" value="{{ $d['venta'] ?? $p['venta'] ?? 0 }}"></label>
                        <label class="text-xs">COBRO<input class="ar-input" name="cobro" type="number" step="0.01" value="{{ $d['cobro'] ?? $p['cobro'] ?? 0 }}"></label>
                        <label class="text-xs">CC cargo<input class="ar-input" name="cc_charge" type="number" step="0.01" value="{{ $d['cc_charge'] ?? $p['cc_charge'] ?? 0 }}"></label>
                        <label class="text-xs">CC cobro<input class="ar-input" name="cc_payment" type="number" step="0.01" value="{{ $d['cc_payment'] ?? $p['cc_payment'] ?? 0 }}"></label>
                        <label class="text-xs">Merca entregada<input class="ar-input" name="merca_out" type="number" step="0.01" value="{{ $d['merca_out'] ?? $p['merca_out'] ?? 0 }}"></label>
                        <label class="text-xs">Merca recibida<input class="ar-input" name="merca_in" type="number" step="0.01" value="{{ $d['merca_in'] ?? $p['merca_in'] ?? 0 }}"></label>
                        <label class="text-xs">Utilidad<input class="ar-input" name="utilidad" type="number" step="0.01" value="{{ $d['utilidad'] ?? $p['utilidad'] ?? 0 }}"></label>
                        <label class="text-xs">Cliente<input class="ar-input" name="client" value="{{ $d['client'] ?? $row['client'] ?? '' }}"></label>
                        <button class="ar-btn ar-btn-primary sm:col-span-4">Aprobar interpretación (solo preview)</button>
                    </form>
                @endcan
            </div>
        @endforeach
    </section>

    {{-- MAPPINGS --}}
    <section class="ar-card mb-6 p-4" id="mappings">
        <h2 class="mb-3 font-semibold">3. Mappings de cuentas</h2>
        <p class="ar-muted mb-2 text-xs">No confundir cliente (Lidercar/DAASA en CC/Ventas) con cuenta financiera.</p>
        @if ($unknownAccounts)
            <p class="mb-2 text-sm">Pendientes: {{ implode(', ', array_keys($unknownAccounts)) }}</p>
            @can('imports.execute')
                <form method="POST" action="{{ route('imports.historical.approve-account', $batch) }}" class="grid gap-2 sm:grid-cols-3">
                    @csrf
                    <input name="excel_alias" class="ar-input" list="unk" placeholder="Alias Excel" required>
                    <datalist id="unk">
                        @foreach (array_keys($unknownAccounts) as $a)
                            <option value="{{ $a }}"></option>
                        @endforeach
                    </datalist>
                    <input name="name" class="ar-input" placeholder="Nombre AR" required>
                    <select name="type" class="ar-input" required>
                        <option value="other">Otro / analítico</option>
                        <option value="bank">Banco</option>
                        <option value="wallet">Billetera</option>
                        <option value="cash">Efectivo</option>
                        <option value="credit_card">Tarjeta</option>
                    </select>
                    <select name="currency" class="ar-input"><option value="ARS">ARS</option><option value="USD">USD</option></select>
                    <select name="holder" class="ar-input"><option value="fernando">Fernando</option><option value="gabi">Gabi</option></select>
                    <button class="ar-btn ar-btn-primary sm:col-span-3">Aprobar mapping y reprocesar</button>
                </form>
                <p class="ar-muted mt-2 text-xs">Sugerencias: «Saldo Inicial» → cuenta analítica apertura (other/Fernando); «Cintas» → solo si es medio de pago real (si es comercio, no mapear como caja).</p>
            @endcan
        @else
            <p class="ar-muted text-sm">Sin cuentas financieras desconocidas.</p>
        @endif
    </section>

    {{-- TARJETAS --}}
    <section class="ar-card mb-6 p-4" id="tarjetas">
        <h2 class="mb-3 font-semibold">4. Tarjetas ({{ count($queues['cards']) }})</h2>
        <p class="ar-muted mb-2 text-xs">Compra = gasto + pasivo. Pago resumen = baja banco + baja pasivo (sin segundo gasto).</p>
        @can('imports.execute')
            <form method="POST" action="{{ route('imports.historical.decide-cards', $batch) }}">
                @csrf
                <div class="overflow-x-auto">
                    <table class="ar-table text-xs">
                        <thead><tr><th>Fila</th><th>Fecha</th><th>Concepto</th><th>Cuenta</th><th>SubCuenta</th><th>Egresos</th><th>Pagos TC</th><th>Sugerido</th><th>Decisión</th><th>Antes/Después</th></tr></thead>
                        <tbody>
                            @foreach ($queues['cards'] as $i => $row)
                                <tr>
                                    <td>{{ $row['source_row'] }}
                                        <input type="hidden" name="decisions[{{ $i }}][source_row]" value="{{ $row['source_row'] }}">
                                    </td>
                                    <td>{{ $row['date'] }}</td>
                                    <td>{{ \Illuminate\Support\Str::limit($row['concepto'] ?? '', 30) }}</td>
                                    <td>{{ $row['excel_cuenta_category'] }}</td>
                                    <td>{{ $row['excel_subcuenta_account'] }}</td>
                                    <td>{{ number_format((float) ($row['amounts']['egresos'] ?? 0), 2, ',', '.') }}</td>
                                    <td>{{ number_format((float) ($row['amounts']['pagos_tc'] ?? 0), 2, ',', '.') }}</td>
                                    <td>{{ ($row['suggested_card_kind'] ?? '') === 'purchase' ? 'Compra' : 'Pago resumen' }}</td>
                                    <td>
                                        <select name="decisions[{{ $i }}][kind]" class="ar-input">
                                            <option value="skip">Pendiente</option>
                                            <option value="purchase" @selected(($row['decision']['kind'] ?? $row['suggested_card_kind'] ?? '') === 'purchase')>Compra con tarjeta</option>
                                            <option value="statement_payment" @selected(($row['decision']['kind'] ?? $row['suggested_card_kind'] ?? '') === 'statement_payment')>Pago del resumen</option>
                                        </select>
                                    </td>
                                    <td class="text-[10px]">
                                        Compra→ gasto+pasivo · Resumen→ banco↓ pasivo↓ sin 2º gasto
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <button class="ar-btn ar-btn-primary mt-3">Aplicar tarjetas y reprocesar</button>
            </form>
        @endcan
    </section>

    {{-- CC RESTANTE --}}
    <section class="ar-card mb-6 p-4" id="cc">
        <h2 class="mb-2 font-semibold">5. CC restante a revisar ({{ count($queues['cc']) }})</h2>
        <p class="ar-muted mb-2 text-xs">Reclasificar después de resolver ventas complejas y mappings. Solo listado de preview.</p>
        <ul class="text-xs space-y-1">
            @foreach ($queues['cc'] as $row)
                <li>Fila {{ $row['source_row'] }} · {{ $row['date'] }} · {{ $row['concepto'] }} · CC IN {{ number_format((float) ($row['amounts']['cc_in'] ?? 0), 2, ',', '.') }} · CC OUT {{ number_format((float) ($row['amounts']['cc_out'] ?? 0), 2, ',', '.') }} · {{ $row['review_status'] }}</li>
            @endforeach
        </ul>
    </section>

    {{-- MERCA --}}
    <section class="ar-card mb-6 p-4" id="merca">
        <h2 class="mb-2 font-semibold">6. Mercadería histórica ({{ count($queues['merca']) }})</h2>
        <p class="text-sm">Estado: <strong>ANÁLISIS / CONCILIACIÓN</strong>. No genera stock físico ni lotes FIFO.</p>
        <ul class="mt-2 text-xs ar-muted">
            @foreach ($queues['merca'] as $row)
                <li>Fila {{ $row['source_row'] }} · {{ $row['concepto'] }} · IN {{ number_format((float) ($row['amounts']['merca_in'] ?? 0), 2, ',', '.') }} · OUT {{ number_format((float) ($row['amounts']['merca_out'] ?? 0), 2, ',', '.') }}</li>
            @endforeach
        </ul>
    </section>

    {{-- AMBITO --}}
    <section class="ar-card mb-6 p-4" id="ambito">
        <h2 class="mb-3 font-semibold">7. Ámbito Personal / Profesional ({{ count($queues['scope']) }})</h2>
        <p class="ar-muted mb-2 text-xs">Selección múltiple. Reglas reutilizables solo si las aprobás (ej. «Gastos Gabi»→Personal). No forzar «Comidas»→Personal siempre.</p>
        @can('imports.execute')
            <form method="POST" action="{{ route('imports.historical.decide-scope', $batch) }}">
                @csrf
                <div class="mb-3 flex flex-wrap gap-2 text-sm">
                    <label><input type="radio" name="scope" value="personal" required> Personal</label>
                    <label><input type="radio" name="scope" value="professional"> Profesional</label>
                    <label><input type="radio" name="scope" value="pending"> Dejar pendiente</label>
                </div>
                <div class="mb-3 grid gap-2 sm:grid-cols-2">
                    <label class="text-xs flex items-center gap-2"><input type="checkbox" name="save_rule" value="1"> Guardar regla reutilizable</label>
                    <input name="rule_match" class="ar-input" placeholder="Match regla (ej. Gastos Gabi)">
                </div>
                <div class="overflow-x-auto max-h-96 overflow-y-auto">
                    <table class="ar-table text-xs">
                        <thead><tr><th></th><th>Fecha</th><th>Concepto</th><th>Cat.</th><th>Cuenta</th><th>Importe</th><th>Sugerido</th><th>Actual</th></tr></thead>
                        <tbody>
                            @foreach ($queues['scope'] as $row)
                                <tr>
                                    <td><input type="checkbox" name="source_rows[]" value="{{ $row['source_row'] }}"></td>
                                    <td>{{ $row['date'] }}</td>
                                    <td>{{ \Illuminate\Support\Str::limit($row['concepto'] ?? '', 40) }}</td>
                                    <td>{{ $row['excel_cuenta_category'] }}</td>
                                    <td>{{ $row['excel_subcuenta_account'] }}</td>
                                    <td>{{ number_format((float) ($row['display_amount'] ?? 0), 2, ',', '.') }}</td>
                                    <td>{{ $row['suggested_scope'] ?? '—' }}</td>
                                    <td>{{ $row['current_scope'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <button class="ar-btn ar-btn-primary mt-3">Aplicar ámbito a seleccionadas y reprocesar</button>
            </form>
        @endcan
    </section>
</x-app-layout>
