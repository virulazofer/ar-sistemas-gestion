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

    @php
        $sortUrl = function (string $col) use ($sort, $dir, $naturalDir) {
            $next = ($sort === $col)
                ? ($dir === 'asc' ? 'desc' : 'asc')
                : ($naturalDir[$col] ?? 'asc');
            return request()->fullUrlWithQuery(['sort' => $col, 'dir' => $next, 'page' => null]);
        };
        $sortMark = function (string $col) use ($sort, $dir) {
            if ($sort !== $col) {
                return ' ↕';
            }
            return $dir === 'asc' ? ' ↑' : ' ↓';
        };
        $sel = function (string $key) {
            $raw = request()->input($key, []);
            if (! is_array($raw)) {
                $raw = $raw === null || $raw === '' ? [] : [$raw];
            }
            return array_map('strval', $raw);
        };
        $selType = $sel('type');
        $selScope = $sel('scope');
        $selStatus = $sel('status');
        $selFa = $sel('financial_account_id');
        $selCur = $sel('currency_id');
        $selChart = $sel('chart_account_id');
        $countLabel = function (array $selected, string $empty) {
            $n = count($selected);
            if ($n === 0) {
                return $empty;
            }
            return $empty.' ('.$n.')';
        };
    @endphp

    <form class="mb-4 flex flex-wrap items-end gap-2" method="GET" id="mov-filters">
        <input type="hidden" name="sort" value="{{ $sort }}">
        <input type="hidden" name="dir" value="{{ $dir }}">
        <div>
            <label class="ar-label text-xs" for="q">Buscar</label>
            <input id="q" name="q" class="ar-input" value="{{ request('q') }}" placeholder="MOV, descripción, cuenta…">
        </div>
        <div>
            <label class="ar-label text-xs" for="date_from">Desde</label>
            <input id="date_from" type="date" name="date_from" class="ar-input w-auto" value="{{ request('date_from') }}">
        </div>
        <div>
            <label class="ar-label text-xs" for="date_to">Hasta</label>
            <input id="date_to" type="date" name="date_to" class="ar-input w-auto" value="{{ request('date_to') }}">
        </div>

        <div class="mov-ms" x-data="{ open: false }">
            <span class="ar-label text-xs">Tipo</span>
            <button type="button" class="ar-input mov-ms-btn" @click="open = !open" :aria-expanded="open">
                {{ $countLabel($selType, 'Tipo') }}
            </button>
            <div class="mov-ms-panel" x-show="open" x-cloak @click.outside="open = false">
                @foreach (config('finance.movement_types') as $value => $label)
                    <label class="mov-ms-item">
                        <input type="checkbox" name="type[]" value="{{ $value }}" @checked(in_array((string) $value, $selType, true))>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        </div>

        <div class="mov-ms" x-data="{ open: false }">
            <span class="ar-label text-xs">Ámbito / Origen</span>
            <button type="button" class="ar-input mov-ms-btn" @click="open = !open" :aria-expanded="open">
                {{ $countLabel($selScope, 'Ámbito') }}
            </button>
            <div class="mov-ms-panel" x-show="open" x-cloak @click.outside="open = false">
                @foreach (['personal' => 'Personal', 'professional' => 'Profesional', 'financial' => 'Financiero', 'mixed' => 'Mixto'] as $value => $label)
                    <label class="mov-ms-item">
                        <input type="checkbox" name="scope[]" value="{{ $value }}" @checked(in_array($value, $selScope, true))>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        </div>

        <div class="mov-ms" x-data="{ open: false }">
            <span class="ar-label text-xs">Estado</span>
            <button type="button" class="ar-input mov-ms-btn" @click="open = !open" :aria-expanded="open">
                {{ $countLabel($selStatus, 'Estado') }}
            </button>
            <div class="mov-ms-panel" x-show="open" x-cloak @click.outside="open = false">
                <label class="mov-ms-item">
                    <input type="checkbox" name="status[]" value="posted" @checked(in_array('posted', $selStatus, true))>
                    <span>Confirmado</span>
                </label>
                <label class="mov-ms-item">
                    <input type="checkbox" name="status[]" value="voided" @checked(in_array('voided', $selStatus, true))>
                    <span>Anulado</span>
                </label>
            </div>
        </div>

        <div class="mov-ms" x-data="{ open: false }">
            <span class="ar-label text-xs">Cuenta financiera</span>
            <button type="button" class="ar-input mov-ms-btn" @click="open = !open" :aria-expanded="open">
                {{ $countLabel($selFa, 'FA') }}
            </button>
            <div class="mov-ms-panel" x-show="open" x-cloak @click.outside="open = false">
                @foreach ($accounts as $account)
                    <label class="mov-ms-item">
                        <input type="checkbox" name="financial_account_id[]" value="{{ $account->id }}" @checked(in_array((string) $account->id, $selFa, true))>
                        <span>{{ $account->name }}</span>
                    </label>
                @endforeach
            </div>
        </div>

        <div class="mov-ms" x-data="{ open: false }">
            <span class="ar-label text-xs">Moneda</span>
            <button type="button" class="ar-input mov-ms-btn" @click="open = !open" :aria-expanded="open">
                {{ $countLabel($selCur, 'Moneda') }}
            </button>
            <div class="mov-ms-panel" x-show="open" x-cloak @click.outside="open = false">
                @foreach ($currencies as $currency)
                    <label class="mov-ms-item">
                        <input type="checkbox" name="currency_id[]" value="{{ $currency->id }}" @checked(in_array((string) $currency->id, $selCur, true))>
                        <span>{{ $currency->code }}</span>
                    </label>
                @endforeach
            </div>
        </div>

        <div
            class="mov-ms mov-ms-wide"
            x-data="{
                open: false,
                q: '',
                concepts: {{ \Illuminate\Support\Js::from($conceptsPayload) }},
                selected: {{ \Illuminate\Support\Js::from($selChart) }},
                filtered() {
                    const qq = (this.q || '').trim().toLowerCase();
                    const selectedSet = new Set(this.selected.map(String));
                    const match = (c) => !qq
                        || (c.path || '').toLowerCase().includes(qq)
                        || (c.name || '').toLowerCase().includes(qq)
                        || (c.code || '').toLowerCase().includes(qq);
                    const picked = this.concepts.filter(c => selectedSet.has(String(c.id)));
                    const rest = this.concepts.filter(c => !selectedSet.has(String(c.id)) && match(c)).slice(0, 80);
                    return [...picked, ...rest];
                },
                label() {
                    const n = this.selected.length;
                    return n ? ('Cuenta contable (' + n + ')') : 'Cuenta contable';
                }
            }"
        >
            <span class="ar-label text-xs">Cuenta contable</span>
            <button type="button" class="ar-input mov-ms-btn" @click="open = !open" x-text="label()" :aria-expanded="open"></button>
            <div class="mov-ms-panel" x-show="open" x-cloak @click.outside="open = false">
                <input type="search" class="ar-input mov-ms-search" placeholder="Buscar cuenta…" x-model="q" @click.stop>
                <template x-for="c in filtered()" :key="c.id">
                    <label class="mov-ms-item">
                        <input
                            type="checkbox"
                            name="chart_account_id[]"
                            :value="String(c.id)"
                            :checked="selected.includes(String(c.id))"
                            @change="
                                const v = String(c.id);
                                if ($event.target.checked) {
                                    if (!selected.includes(v)) selected.push(v);
                                } else {
                                    selected = selected.filter(x => x !== v);
                                }
                            "
                        >
                        <span x-text="c.path || c.name"></span>
                    </label>
                </template>
                <template x-if="filtered().length === 0">
                    <p class="ar-muted px-2 py-1 text-xs">Sin coincidencias</p>
                </template>
                <template x-for="id in selected.filter(sid => !filtered().some(c => String(c.id) === sid))" :key="'hid-'+id">
                    <input type="hidden" name="chart_account_id[]" :value="id">
                </template>
            </div>
        </div>

        <div>
            <label class="ar-label text-xs" for="per_page">Por página</label>
            <select id="per_page" name="per_page" class="ar-input w-auto">
                @foreach ([25, 50, 100] as $n)
                    <option value="{{ $n }}" @selected((int) request('per_page', 25) === $n)>{{ $n }}</option>
                @endforeach
                @if ($p = (int) request('per_page', 25))
                    @if (! in_array($p, [25, 50, 100], true))
                        <option value="{{ $p }}" selected>{{ $p }}</option>
                    @endif
                @endif
            </select>
        </div>
        <button class="ar-btn ar-btn-secondary">Filtrar</button>
        <a href="{{ route('movements.index') }}" class="ar-btn ar-btn-secondary">Limpiar</a>
    </form>

    <div
        class="ar-card overflow-x-auto"
        x-data="movementsGrid({
            canEdit: {{ $canInlineEdit ? 'true' : 'false' }},
            concepts: {{ \Illuminate\Support\Js::from($conceptsPayload) }},
            recent: {{ \Illuminate\Support\Js::from($usage['recent'] ?? []) }},
            frequent: {{ \Illuminate\Support\Js::from($usage['frequent'] ?? []) }},
            accounts: {{ \Illuminate\Support\Js::from($accountsPayload) }},
            csrf: @js(csrf_token()),
        })"
    >
        <table class="ar-table mov-grid">
            <thead>
                <tr>
                    <th><a href="{{ $sortUrl('code') }}" class="mov-sort">Código{{ $sortMark('code') }}</a></th>
                    <th><a href="{{ $sortUrl('date') }}" class="mov-sort">Fecha{{ $sortMark('date') }}</a></th>
                    <th><a href="{{ $sortUrl('description') }}" class="mov-sort">Descripción{{ $sortMark('description') }}</a></th>
                    <th><a href="{{ $sortUrl('chart_account') }}" class="mov-sort">Cuenta contable{{ $sortMark('chart_account') }}</a></th>
                    <th><a href="{{ $sortUrl('scope') }}" class="mov-sort">Ámbito{{ $sortMark('scope') }}</a></th>
                    <th><a href="{{ $sortUrl('financial_account') }}" class="mov-sort">Cuenta financiera{{ $sortMark('financial_account') }}</a></th>
                    <th class="text-right"><a href="{{ $sortUrl('amount') }}" class="mov-sort">Importe{{ $sortMark('amount') }}</a></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($movements as $row)
                    <tr
                        class="mov-row"
                        :class="{ 'mov-row-editing': editing && editing.id === {{ (int) $row['id'] }} }"
                        data-id="{{ $row['id'] }}"
                        @click="onRowClick($event, {{ \Illuminate\Support\Js::from($row) }})"
                    >
                        <td class="mov-cell-code whitespace-nowrap">
                            <a href="{{ $row['show_url'] }}" class="mov-code-link" @click.stop>
                                {{ $row['code'] }}
                            </a>
                        </td>
                        <td
                            class="mov-cell {{ $canInlineEdit && $row['editable'] ? 'mov-cell-editable' : '' }}"
                            :class="cellClass({{ (int) $row['id'] }}, 'movement_date')"
                            data-field="movement_date"
                            @click.stop="startEdit({{ \Illuminate\Support\Js::from($row) }}, 'movement_date', $event)"
                        >
                            <template x-if="isEditing({{ (int) $row['id'] }}, 'movement_date')">
                                <input
                                    type="date"
                                    class="ar-input mov-editor"
                                    x-model="draft"
                                    x-ref="editor"
                                    @keydown="onEditorKey($event)"
                                    @blur="commitEdit()"
                                >
                            </template>
                            <template x-if="!isEditing({{ (int) $row['id'] }}, 'movement_date')">
                                <span x-text="displayOf({{ (int) $row['id'] }}, 'movement_date_display', @js($row['movement_date_display']))"></span>
                            </template>
                            <span class="mov-saved" x-show="savedFlash[{{ (int) $row['id'] }}] === 'movement_date'" x-cloak>Guardado ✓</span>
                            <span class="mov-err" x-show="errorFor({{ (int) $row['id'] }}, 'movement_date')" x-text="errorFor({{ (int) $row['id'] }}, 'movement_date')" x-cloak></span>
                        </td>
                        <td
                            class="mov-cell {{ $canInlineEdit && $row['is_posted'] ? 'mov-cell-editable' : '' }}"
                            :class="cellClass({{ (int) $row['id'] }}, 'description')"
                            data-field="description"
                            @click.stop="startEdit({{ \Illuminate\Support\Js::from($row) }}, 'description', $event)"
                        >
                            <template x-if="isEditing({{ (int) $row['id'] }}, 'description')">
                                <input
                                    type="text"
                                    class="ar-input mov-editor"
                                    x-model="draft"
                                    x-ref="editor"
                                    @keydown="onEditorKey($event)"
                                    @blur="commitEdit()"
                                >
                            </template>
                            <template x-if="!isEditing({{ (int) $row['id'] }}, 'description')">
                                @if ($canInlineEdit)
                                    <span
                                        class="mov-desc-editable"
                                        x-text="displayOf({{ (int) $row['id'] }}, 'description', @js($row['description'] ?: '—'))"
                                    ></span>
                                @else
                                    <a
                                        href="{{ $row['show_url'] }}"
                                        class="mov-desc-link"
                                        @click.stop
                                        x-text="displayOf({{ (int) $row['id'] }}, 'description', @js($row['description'] ?: '—'))"
                                    ></a>
                                @endif
                            </template>
                            <span class="mov-saved" x-show="savedFlash[{{ (int) $row['id'] }}] === 'description'" x-cloak>Guardado ✓</span>
                            <span class="mov-err" x-show="errorFor({{ (int) $row['id'] }}, 'description')" x-text="errorFor({{ (int) $row['id'] }}, 'description')" x-cloak></span>
                        </td>
                        <td
                            class="mov-cell text-xs {{ $canInlineEdit && $row['editable'] ? 'mov-cell-editable' : '' }}"
                            :class="cellClass({{ (int) $row['id'] }}, 'chart_account_id')"
                            data-field="chart_account_id"
                            @click.stop="startEdit({{ \Illuminate\Support\Js::from($row) }}, 'chart_account_id', $event)"
                        >
                            <template x-if="isEditing({{ (int) $row['id'] }}, 'chart_account_id')">
                                <div class="relative" @click.outside="/* wait for select */">
                                    <input
                                        type="text"
                                        class="ar-input mov-editor"
                                        placeholder="Buscar cuenta…"
                                        x-model="chartQuery"
                                        x-ref="editor"
                                        @input="filterCharts()"
                                        @keydown="onChartKey($event)"
                                        @blur="onChartBlur()"
                                    >
                                    <ul class="mov-omnibox" x-show="chartOpen && chartOptions.length" x-cloak>
                                        <template x-for="(opt, idx) in chartOptions" :key="opt.id">
                                            <li
                                                :class="{ 'mov-omnibox-active': idx === chartActive }"
                                                @mousedown.prevent="pickChart(opt)"
                                                @mouseenter="chartActive = idx"
                                            >
                                                <div x-text="opt.path || opt.name"></div>
                                                <div class="ar-muted text-xs" x-text="opt.code"></div>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </template>
                            <template x-if="!isEditing({{ (int) $row['id'] }}, 'chart_account_id')">
                                <span x-text="displayOf({{ (int) $row['id'] }}, 'chart_label', @js($row['chart_label']))"></span>
                            </template>
                            <span class="mov-saved" x-show="savedFlash[{{ (int) $row['id'] }}] === 'chart_account_id'" x-cloak>Guardado ✓</span>
                            <span class="mov-err" x-show="errorFor({{ (int) $row['id'] }}, 'chart_account_id')" x-text="errorFor({{ (int) $row['id'] }}, 'chart_account_id')" x-cloak></span>
                        </td>
                        <td
                            class="mov-cell {{ $canInlineEdit && $row['editable'] ? 'mov-cell-editable' : '' }}"
                            :class="cellClass({{ (int) $row['id'] }}, 'scope')"
                            data-field="scope"
                            @click.stop="startEdit({{ \Illuminate\Support\Js::from($row) }}, 'scope', $event)"
                        >
                            <template x-if="isEditing({{ (int) $row['id'] }}, 'scope')">
                                <select
                                    class="ar-input mov-editor"
                                    x-model="draft"
                                    x-ref="editor"
                                    @keydown="onEditorKey($event)"
                                    @change="commitEdit()"
                                    @blur="commitEdit()"
                                >
                                    <template x-for="opt in scopeOptionsFor(editing?.type)" :key="opt.value">
                                        <option :value="opt.value" x-text="opt.label"></option>
                                    </template>
                                </select>
                            </template>
                            <template x-if="!isEditing({{ (int) $row['id'] }}, 'scope')">
                                <span x-text="displayOf({{ (int) $row['id'] }}, 'scope_label', @js($row['scope_label']))"></span>
                            </template>
                            <span class="mov-saved" x-show="savedFlash[{{ (int) $row['id'] }}] === 'scope'" x-cloak>Guardado ✓</span>
                            <span class="mov-err" x-show="errorFor({{ (int) $row['id'] }}, 'scope')" x-text="errorFor({{ (int) $row['id'] }}, 'scope')" x-cloak></span>
                        </td>
                        <td
                            class="mov-cell {{ $canInlineEdit && $row['editable'] ? 'mov-cell-editable' : '' }}"
                            :class="cellClass({{ (int) $row['id'] }}, 'financial_account_id')"
                            data-field="financial_account_id"
                            @click.stop="startEdit({{ \Illuminate\Support\Js::from($row) }}, 'financial_account_id', $event)"
                        >
                            <template x-if="isEditing({{ (int) $row['id'] }}, 'financial_account_id')">
                                <select
                                    class="ar-input mov-editor"
                                    x-model="draft"
                                    x-ref="editor"
                                    @keydown="onEditorKey($event)"
                                    @change="commitEdit()"
                                    @blur="commitEdit()"
                                >
                                    <template x-for="a in accounts" :key="a.id">
                                        <option :value="String(a.id)" x-text="a.name + (a.currency ? ' ('+a.currency+')' : '')"></option>
                                    </template>
                                </select>
                            </template>
                            <template x-if="!isEditing({{ (int) $row['id'] }}, 'financial_account_id')">
                                <span x-text="displayOf({{ (int) $row['id'] }}, 'financial_account_name', @js($row['financial_account_name']))"></span>
                            </template>
                            <span class="mov-saved" x-show="savedFlash[{{ (int) $row['id'] }}] === 'financial_account_id'" x-cloak>Guardado ✓</span>
                            <span class="mov-err" x-show="errorFor({{ (int) $row['id'] }}, 'financial_account_id')" x-text="errorFor({{ (int) $row['id'] }}, 'financial_account_id')" x-cloak></span>
                        </td>
                        <td
                            class="mov-cell text-right {{ $row['amount_class'] }} {{ $canInlineEdit && $row['editable'] ? 'mov-cell-editable' : '' }}"
                            :class="cellClass({{ (int) $row['id'] }}, 'amount')"
                            data-field="amount"
                            @click.stop="startEdit({{ \Illuminate\Support\Js::from($row) }}, 'amount', $event)"
                        >
                            <template x-if="isEditing({{ (int) $row['id'] }}, 'amount')">
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    class="ar-input mov-editor text-right"
                                    x-model="draft"
                                    x-ref="editor"
                                    @keydown="onEditorKey($event)"
                                    @blur="commitEdit()"
                                >
                            </template>
                            <template x-if="!isEditing({{ (int) $row['id'] }}, 'amount')">
                                <span>
                                    <span x-text="displayOf({{ (int) $row['id'] }}, 'amount_display', @js($row['amount_display']))"></span>
                                    <span x-text="displayOf({{ (int) $row['id'] }}, 'currency_code', @js($row['currency_code']))"></span>
                                </span>
                            </template>
                            <span class="mov-saved" x-show="savedFlash[{{ (int) $row['id'] }}] === 'amount'" x-cloak>Guardado ✓</span>
                            <span class="mov-err" x-show="errorFor({{ (int) $row['id'] }}, 'amount')" x-text="errorFor({{ (int) $row['id'] }}, 'amount')" x-cloak></span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- Confirmación sensible --}}
        <div class="mov-modal" x-show="confirmOpen" x-cloak @keydown.escape.window="cancelConfirm()">
            <div class="mov-modal-backdrop" @click="cancelConfirm()"></div>
            <div class="mov-modal-card" role="dialog" aria-modal="true">
                <h2 class="font-semibold" x-text="confirmTitle"></h2>
                <p class="ar-muted mt-2 text-sm" x-text="confirmDetail"></p>
                <label class="ar-label mt-3" for="mov-edit-reason">Motivo</label>
                <textarea id="mov-edit-reason" class="ar-input" rows="2" x-model="confirmReason" placeholder="Obligatorio"></textarea>
                <p class="mov-err" x-show="confirmError" x-text="confirmError" x-cloak></p>
                <div class="mt-3 flex justify-end gap-2">
                    <button type="button" class="ar-btn ar-btn-secondary" @click="cancelConfirm()">Cancelar</button>
                    <button type="button" class="ar-btn ar-btn-primary" @click="confirmSensitive()">Confirmar</button>
                </div>
            </div>
        </div>

        {{-- FX al cambiar fecha --}}
        <div class="mov-modal" x-show="fxOpen" x-cloak @keydown.escape.window="cancelFx()">
            <div class="mov-modal-backdrop" @click="cancelFx()"></div>
            <div class="mov-modal-card" role="dialog" aria-modal="true">
                <h2 class="font-semibold">Cotización y fecha</h2>
                <p class="ar-muted mt-2 text-sm" x-text="fxMessage"></p>
                <div class="mt-3 space-y-2 text-sm">
                    <label class="flex items-center gap-2">
                        <input type="radio" value="recalculate" x-model="fxMode"> Recalcular con cotización histórica
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" value="keep" x-model="fxMode"> Conservar cotización actual
                    </label>
                </div>
                <p class="mov-err" x-show="fxError" x-text="fxError" x-cloak></p>
                <div class="mt-3 flex justify-end gap-2">
                    <button type="button" class="ar-btn ar-btn-secondary" @click="cancelFx()">Cancelar</button>
                    <button type="button" class="ar-btn ar-btn-primary" @click="confirmFx()">Continuar</button>
                </div>
            </div>
        </div>
    </div>

    <p class="ar-muted mt-2 text-xs">
        <strong>Código MOV</strong> abre el detalle.
        @if ($canInlineEdit)
            En escritorio, clic en celdas editables (fecha, descripción, cuentas, ámbito, importe) para corregir.
            Importe y cuenta financiera piden confirmación y motivo.
        @else
            Vista de solo lectura: ordená, filtrá y abrí el detalle.
        @endif
        En móvil: abrí el detalle para editar (Admin).
    </p>
    <div class="mt-4">{{ $movements->links() }}</div>

    <style>
        .mov-sort { color: inherit; text-decoration: none; font-weight: 600; white-space: nowrap; }
        .mov-sort:hover { color: var(--ar-brand); }
        .mov-code-link { color: var(--ar-brand); font-weight: 600; text-decoration: none; }
        .mov-code-link:hover { text-decoration: underline; }
        .mov-desc-link { color: inherit; text-decoration: underline; text-underline-offset: 2px; }
        .mov-desc-editable { display: inline-block; min-width: 4rem; }
        .mov-row:hover { background: var(--ar-surface-2, rgba(0,0,0,.04)); }
        .mov-row-editing { background: var(--ar-surface-2, rgba(0,0,0,.06)); }
        .mov-cell { position: relative; min-width: 7rem; }
        .mov-cell-editable {
            cursor: cell;
            outline: 1px dashed transparent;
            outline-offset: -2px;
            border-radius: 2px;
            transition: background .12s ease, outline-color .12s ease;
        }
        .mov-cell-editable:hover {
            background: rgba(14, 116, 144, 0.08);
            outline-color: var(--ar-brand, #0e7490);
        }
        .mov-cell-editable:hover::after {
            content: '✎';
            position: absolute;
            top: 2px;
            right: 4px;
            font-size: .65rem;
            opacity: .55;
            color: var(--ar-brand, #0e7490);
            pointer-events: none;
        }
        .mov-editor { min-width: 8rem; padding: .25rem .4rem; font-size: .875rem; }
        .mov-saved { display: block; font-size: .7rem; color: var(--ar-success, #15803d); margin-top: .15rem; }
        .mov-err { display: block; font-size: .7rem; color: var(--ar-danger); margin-top: .15rem; }
        .mov-omnibox {
            position: absolute; z-index: 40; left: 0; right: 0; max-height: 14rem; overflow: auto;
            margin-top: .15rem; border: 1px solid var(--ar-border); border-radius: .25rem;
            background: var(--ar-surface, #fff); list-style: none; padding: 0;
        }
        .mov-omnibox li { padding: .4rem .6rem; cursor: pointer; }
        .mov-omnibox-active, .mov-omnibox li:hover { background: var(--ar-surface-2, #f3f4f6); }
        .mov-modal { position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; }
        .mov-modal-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,.35); }
        .mov-modal-card {
            position: relative; z-index: 1; width: min(28rem, 92vw);
            background: var(--ar-surface, #fff); border: 1px solid var(--ar-border);
            border-radius: .5rem; padding: 1.25rem;
        }
        .mov-ms { position: relative; min-width: 9rem; }
        .mov-ms-wide { min-width: 14rem; }
        .mov-ms-btn {
            display: block; width: 100%; text-align: left; cursor: pointer;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .mov-ms-panel {
            position: absolute; z-index: 45; left: 0; min-width: 14rem; max-width: 22rem;
            max-height: 16rem; overflow: auto; margin-top: .2rem;
            border: 1px solid var(--ar-border); border-radius: .35rem;
            background: var(--ar-surface, #fff); padding: .35rem; box-shadow: 0 8px 20px rgba(0,0,0,.08);
        }
        .mov-ms-search { width: 100%; margin-bottom: .35rem; }
        .mov-ms-item {
            display: flex; align-items: flex-start; gap: .45rem;
            padding: .3rem .35rem; font-size: .85rem; cursor: pointer;
        }
        .mov-ms-item:hover { background: var(--ar-surface-2, #f3f4f6); }
        @media (max-width: 767px) {
            .mov-grid .mov-cell-editable { cursor: default; }
            .mov-grid .mov-cell-editable:hover { background: transparent; outline-color: transparent; }
            .mov-grid .mov-cell-editable:hover::after { content: none; }
            .mov-grid .mov-editor, .mov-grid .mov-omnibox { display: none !important; }
        }
    </style>

    <script>
        window.movementsGrid = function movementsGrid(cfg) {
            const sensitive = new Set(['amount', 'financial_account_id']);
            const isMobile = () => window.matchMedia('(max-width: 767px)').matches;

            return {
                canEdit: !!cfg.canEdit,
                concepts: cfg.concepts || [],
                recent: cfg.recent || [],
                frequent: cfg.frequent || [],
                accounts: cfg.accounts || [],
                csrf: cfg.csrf,
                rows: {},
                editing: null,
                draft: '',
                original: '',
                saving: false,
                savedFlash: {},
                errors: {},
                confirmOpen: false,
                confirmTitle: '',
                confirmDetail: '',
                confirmReason: '',
                confirmError: '',
                pending: null,
                fxOpen: false,
                fxMode: '',
                fxMessage: '',
                fxError: '',
                chartQuery: '',
                chartOpen: false,
                chartOptions: [],
                chartActive: 0,
                chartPicked: false,

                init() {
                    // noop
                },

                displayOf(id, key, fallback) {
                    if (this.rows[id] && this.rows[id][key] !== undefined && this.rows[id][key] !== null) {
                        return this.rows[id][key] === '' ? '—' : this.rows[id][key];
                    }
                    return fallback === '' || fallback === null ? '—' : fallback;
                },

                isEditing(id, field) {
                    return this.canEdit && !isMobile() && this.editing
                        && this.editing.id === id && this.editing.field === field;
                },

                cellClass(id, field) {
                    if (!this.canEdit || isMobile()) return '';
                    return 'mov-cell-editable';
                },

                errorFor(id, field) {
                    return this.errors[id + ':' + field] || '';
                },

                scopeOptionsFor(type) {
                    if (type === 'income') {
                        return [
                            { value: 'professional', label: 'Profesional' },
                            { value: 'financial', label: 'Financiero' },
                        ];
                    }
                    return [
                        { value: 'personal', label: 'Personal' },
                        { value: 'professional', label: 'Profesional' },
                        { value: 'mixed', label: 'Mixto' },
                    ];
                },

                onRowClick(ev, row) {
                    if (isMobile() || !this.canEdit) {
                        if (ev.target.closest('a')) return;
                        window.location = row.show_url;
                    }
                },

                startEdit(row, field, ev) {
                    if (!this.canEdit || isMobile()) {
                        if (!ev.target.closest('a')) window.location = row.show_url;
                        return;
                    }
                    if (!row.is_posted || row.is_transfer) {
                        if (field !== 'description') return;
                        if (!row.is_posted) return;
                    }
                    if (row.is_transfer && ['financial_account_id', 'amount', 'scope'].includes(field)) {
                        return;
                    }
                    if (this.editing && (this.editing.id !== row.id || this.editing.field !== field)) {
                        this.commitEdit(true);
                    }
                    this.clearError(row.id, field);
                    this.editing = { id: row.id, field, inline_url: row.inline_url, type: row.type, row };
                    this.original = this.valueFor(row, field);
                    this.draft = this.original;
                    if (field === 'chart_account_id') {
                        this.chartQuery = row.chart_label && row.chart_label !== '—' ? row.chart_label : '';
                        this.chartPicked = false;
                        this.filterCharts(true);
                        this.chartOpen = true;
                    }
                    this.$nextTick(() => {
                        const el = this.$refs.editor;
                        if (el) { el.focus(); if (el.select) el.select(); }
                    });
                },

                valueFor(row, field) {
                    const live = this.rows[row.id] || {};
                    if (field === 'movement_date') return live.movement_date || row.movement_date || '';
                    if (field === 'description') return live.description ?? row.description ?? '';
                    if (field === 'chart_account_id') return String(live.chart_account_id ?? row.chart_account_id ?? '');
                    if (field === 'scope') return live.scope || row.scope || '';
                    if (field === 'financial_account_id') return String(live.financial_account_id ?? row.financial_account_id ?? '');
                    if (field === 'amount') return String(live.amount ?? row.amount ?? '');
                    return '';
                },

                onEditorKey(e) {
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        this.cancelEdit();
                        return;
                    }
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        this.commitEdit();
                        return;
                    }
                    if (e.key === 'Tab') {
                        e.preventDefault();
                        this.commitEdit(false, e.shiftKey ? -1 : 1);
                    }
                },

                cancelEdit() {
                    this.editing = null;
                    this.draft = '';
                    this.chartOpen = false;
                },

                clearError(id, field) {
                    delete this.errors[id + ':' + field];
                },

                setError(id, field, msg) {
                    this.errors[id + ':' + field] = msg;
                },

                async commitEdit(skipAdvance, tabDir) {
                    if (!this.editing || this.saving) return;
                    const ed = this.editing;
                    let value = this.draft;
                    if (ed.field === 'chart_account_id' && !this.chartPicked) {
                        // sin selección nueva → cancelar
                        this.cancelEdit();
                        return;
                    }
                    if (String(value ?? '') === String(this.original ?? '')) {
                        this.cancelEdit();
                        return;
                    }
                    if (sensitive.has(ed.field)) {
                        this.openConfirm(ed, value);
                        return;
                    }
                    await this.persist(ed, value, '', '');
                    if (!skipAdvance && tabDir) this.advance(ed, tabDir);
                },

                openConfirm(ed, value) {
                    const from = this.original;
                    let detail = '';
                    if (ed.field === 'amount') {
                        detail = 'Cambiar importe: $ ' + from + ' → $ ' + value;
                    } else {
                        const aFrom = this.accounts.find(a => String(a.id) === String(from));
                        const aTo = this.accounts.find(a => String(a.id) === String(value));
                        detail = 'Cambiar cuenta financiera: '
                            + (aFrom?.name || from) + ' → ' + (aTo?.name || value);
                    }
                    this.pending = { ed, value };
                    this.confirmTitle = 'Confirmar cambio sensible';
                    this.confirmDetail = detail;
                    this.confirmReason = '';
                    this.confirmError = '';
                    this.confirmOpen = true;
                },

                cancelConfirm() {
                    this.confirmOpen = false;
                    this.pending = null;
                    this.cancelEdit();
                },

                async confirmSensitive() {
                    if (!this.pending) return;
                    if (!this.confirmReason.trim()) {
                        this.confirmError = 'El motivo es obligatorio.';
                        return;
                    }
                    const { ed, value } = this.pending;
                    this.confirmOpen = false;
                    await this.persist(ed, value, this.confirmReason.trim(), '');
                    this.pending = null;
                },

                cancelFx() {
                    this.fxOpen = false;
                    this.cancelEdit();
                },

                async confirmFx() {
                    if (!this.fxMode) {
                        this.fxError = 'Elegí recalcular o conservar.';
                        return;
                    }
                    if (!this.pending) return;
                    const { ed, value, reason } = this.pending;
                    this.fxOpen = false;
                    await this.persist(ed, value, reason || '', this.fxMode);
                    this.pending = null;
                },

                async persist(ed, value, reason, fxMode) {
                    this.saving = true;
                    this.clearError(ed.id, ed.field);
                    try {
                        const res = await fetch(ed.inline_url, {
                            method: 'PATCH',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': this.csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: JSON.stringify({
                                field: ed.field,
                                value: value,
                                edit_reason: reason || null,
                                fx_mode: fxMode || '',
                            }),
                        });
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok) {
                            if (data.needs_fx) {
                                this.pending = { ed, value, reason };
                                this.fxMessage = data.message || 'La fecha no coincide con la cotización.';
                                this.fxMode = '';
                                this.fxError = '';
                                this.fxOpen = true;
                                return;
                            }
                            this.setError(ed.id, ed.field, data.message || 'No se pudo guardar.');
                            this.editing = null;
                            return;
                        }
                        this.rows[ed.id] = Object.assign({}, this.rows[ed.id] || ed.row || {}, data.row || {});
                        this.savedFlash[ed.id] = ed.field;
                        setTimeout(() => { if (this.savedFlash[ed.id] === ed.field) delete this.savedFlash[ed.id]; }, 1600);
                        this.editing = null;
                        this.chartOpen = false;
                    } catch (err) {
                        this.setError(ed.id, ed.field, 'Error de red al guardar.');
                        this.editing = null;
                    } finally {
                        this.saving = false;
                    }
                },

                advance(ed, dir) {
                    const fields = ['movement_date', 'description', 'chart_account_id', 'scope', 'financial_account_id', 'amount'];
                    const tr = document.querySelector('tr.mov-row[data-id="' + ed.id + '"]');
                    if (!tr) return;
                    const idx = fields.indexOf(ed.field);
                    let nextField = fields[idx + dir];
                    let nextTr = tr;
                    if (!nextField) {
                        nextTr = dir > 0 ? tr.nextElementSibling : tr.previousElementSibling;
                        if (!nextTr || !nextTr.classList.contains('mov-row')) return;
                        nextField = dir > 0 ? fields[0] : fields[fields.length - 1];
                    }
                    const nextId = parseInt(nextTr.getAttribute('data-id'), 10);
                    const cell = nextTr.querySelector('[data-field="' + nextField + '"]');
                    if (cell) cell.click();
                    void nextId;
                },

                filterCharts(showRecent) {
                    const q = (this.chartQuery || '').trim().toLowerCase();
                    let list = [];
                    if (!q || showRecent) {
                        const seen = new Set();
                        [...(this.recent || []), ...(this.frequent || [])].forEach(c => {
                            if (!seen.has(c.id)) { seen.add(c.id); list.push({ ...c, badge: 'Reciente' }); }
                        });
                        if (q) {
                            list = this.concepts.filter(c =>
                                (c.path || '').toLowerCase().includes(q)
                                || (c.name || '').toLowerCase().includes(q)
                                || (c.code || '').toLowerCase().includes(q)
                            ).slice(0, 40);
                        } else if (!list.length) {
                            list = this.concepts.slice(0, 20);
                        }
                    } else {
                        list = this.concepts.filter(c =>
                            (c.path || '').toLowerCase().includes(q)
                            || (c.name || '').toLowerCase().includes(q)
                            || (c.code || '').toLowerCase().includes(q)
                        ).slice(0, 40);
                    }
                    this.chartOptions = list;
                    this.chartActive = 0;
                    this.chartOpen = true;
                },

                onChartKey(e) {
                    if (e.key === 'ArrowDown') { e.preventDefault(); this.chartActive = Math.min(this.chartActive + 1, this.chartOptions.length - 1); return; }
                    if (e.key === 'ArrowUp') { e.preventDefault(); this.chartActive = Math.max(this.chartActive - 1, 0); return; }
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        const opt = this.chartOptions[this.chartActive];
                        if (opt) this.pickChart(opt);
                        return;
                    }
                    if (e.key === 'Escape') { e.preventDefault(); this.cancelEdit(); return; }
                    if (e.key === 'Tab') {
                        e.preventDefault();
                        const opt = this.chartOptions[this.chartActive];
                        if (opt) this.pickChart(opt, e.shiftKey ? -1 : 1);
                    }
                },

                pickChart(opt, tabDir) {
                    this.chartPicked = true;
                    this.draft = String(opt.id);
                    this.chartQuery = opt.path || opt.name;
                    this.chartOpen = false;
                    this.commitEdit(false, tabDir || 0);
                },

                onChartBlur() {
                    setTimeout(() => {
                        if (this.editing && this.editing.field === 'chart_account_id' && !this.chartPicked) {
                            this.cancelEdit();
                        }
                    }, 180);
                },
            };
        }
    </script>
</x-app-layout>
