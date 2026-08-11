<?php

namespace App\Services\Imports\Historical;

/**
 * Agrupa filas Amarillo/Rojo por causa raíz y atribuye diferencias de conciliación.
 */
class HistoricalRootCauseAnalyzer
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{yellow: list<array>, red: list<array>, pending_complete: list<array>}
     */
    public function groupByRootCause(array $rows): array
    {
        $yellow = [];
        $red = [];
        $pending_complete = [];

        foreach ($rows as $row) {
            $status = $row['review_status'] ?? '';
            if (! in_array($status, ['yellow', 'red', 'pending_complete'], true)) {
                continue;
            }
            $cause = $row['root_cause'] ?? $this->inferRootCause($row);
            $bucket = $status === 'red' ? 'red' : ($status === 'pending_complete' ? 'pending_complete' : 'yellow');
            if (! isset(${$bucket}[$cause])) {
                ${$bucket}[$cause] = [
                    'cause' => $cause,
                    'label' => $this->label($cause),
                    'count' => 0,
                    'rows' => [],
                    'amount_sum' => [
                        'ingresos' => 0.0,
                        'egresos' => 0.0,
                        'cc_in' => 0.0,
                        'cc_out' => 0.0,
                        'venta' => 0.0,
                    ],
                    'example' => null,
                    'current_interpretation' => null,
                    'proposed_rule' => $this->proposedRule($cause),
                    'mass_apply_risk' => $this->risk($cause),
                ];
            }
            ${$bucket}[$cause]['count']++;
            ${$bucket}[$cause]['rows'][] = (int) ($row['source_row'] ?? 0);
            foreach (['ingresos', 'egresos', 'cc_in', 'cc_out', 'venta'] as $k) {
                ${$bucket}[$cause]['amount_sum'][$k] += (float) ($row['amounts'][$k] ?? 0);
            }
            if (${$bucket}[$cause]['example'] === null) {
                ${$bucket}[$cause]['example'] = [
                    'source_row' => $row['source_row'] ?? null,
                    'date' => $row['date'] ?? null,
                    'concepto' => $row['concepto'] ?? null,
                    'cuenta' => $row['excel_cuenta_category'] ?? null,
                    'subcuenta' => $row['excel_subcuenta_account'] ?? null,
                    'amounts' => $row['amounts'] ?? [],
                    'flags' => $row['flags'] ?? [],
                ];
                ${$bucket}[$cause]['current_interpretation'] = $row['interpretation']['notes'][0]
                    ?? ($row['interpretation']['would_generate'][0] ?? 'Sin interpretación');
            }
        }

        $sort = function (array $groups): array {
            $list = array_values($groups);
            usort($list, fn ($a, $b) => $b['count'] <=> $a['count']);

            return $list;
        };

        return [
            'yellow' => $sort($yellow),
            'red' => $sort($red),
            'pending_complete' => $sort($pending_complete),
        ];
    }

    /**
     * Explica diffs Excel vs AR sumando filas que aportan al Excel pero no a la interpretación.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function attributeDifferences(array $rows): array
    {
        $gaps = [
            'cobros_documentados' => ['excel_key' => 'ingresos', 'interp_key' => 'finance_income', 'rows' => [], 'total_gap' => 0.0],
            'cc_in' => ['excel_key' => 'cc_in', 'interp_key' => 'cc_charge', 'rows' => [], 'total_gap' => 0.0],
            'cc_out' => ['excel_key' => 'cc_out', 'interp_key' => 'cc_payment', 'rows' => [], 'total_gap' => 0.0],
            'egresos' => ['excel_key' => 'egresos', 'interp_key' => 'finance_expense', 'rows' => [], 'total_gap' => 0.0],
        ];

        foreach ($rows as $row) {
            foreach ($gaps as $name => &$gap) {
                $excel = (float) ($row['amounts'][$gap['excel_key']] ?? 0);
                $interp = (float) ($row['interpretation'][$gap['interp_key']] ?? 0);
                $delta = round($excel - $interp, 2);
                if (abs($delta) < 0.01) {
                    continue;
                }
                $gap['total_gap'] += $delta;
                $gap['rows'][] = [
                    'source_row' => $row['source_row'] ?? null,
                    'date' => $row['date'] ?? null,
                    'concepto' => mb_substr((string) ($row['concepto'] ?? ''), 0, 80),
                    'cuenta' => $row['excel_cuenta_category'] ?? null,
                    'subcuenta' => $row['excel_subcuenta_account'] ?? null,
                    'review_status' => $row['review_status'] ?? null,
                    'root_cause' => $row['root_cause'] ?? null,
                    'excel' => $excel,
                    'interpreted' => $interp,
                    'gap' => $delta,
                ];
            }
            unset($gap);
        }

        foreach ($gaps as &$gap) {
            usort($gap['rows'], fn ($a, $b) => abs($b['gap']) <=> abs($a['gap']));
            $gap['top_rows'] = array_slice($gap['rows'], 0, 15);
            $gap['total_gap'] = round($gap['total_gap'], 2);
            unset($gap['rows']);
        }
        unset($gap);

        return $gaps;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function inferRootCause(array $row): string
    {
        $flags = $row['flags'] ?? [];
        $status = $row['review_status'] ?? 'yellow';
        $amounts = $row['amounts'] ?? [];

        if (in_array('venta_compleja_resuelta', $flags, true)) {
            return 'operacion_venta_compleja_resuelta';
        }
        if (in_array('tarjeta_resuelta', $flags, true)) {
            return 'pago_tarjeta_resuelto';
        }
        if (in_array('importe_pago_tarjeta_desconocido', $flags, true)) {
            return 'importe_pago_tarjeta_desconocido';
        }
        if (in_array('pago_resumen_tarjeta_confirmado', $flags, true)
            || (($row['interpretation']['kind'] ?? '') === 'card_statement_payment')
        ) {
            if (in_array('pago_tarjeta_sin_importe', $flags, true)) {
                return 'pago_tarjeta_sin_importe';
            }
            if (in_array('pago_tarjeta_sin_tarjeta', $flags, true)
                || in_array('pago_tarjeta_sin_cuenta_pago', $flags, true)
            ) {
                return 'pago_tarjeta_datos_incompletos';
            }
            if (in_array('pago_tarjeta_cuenta_patagonia', $flags, true)) {
                return 'pago_resumen_tarjeta';
            }

            return 'pago_resumen_tarjeta';
        }
        if (in_array('reintegro_gasto_personal', $flags, true)) {
            return 'reintegro_gasto_personal';
        }
        if (in_array('cc_apertura_confirmada', $flags, true)
            || in_array('confirmed_opening_cc_balance', $flags, true)
            || (($row['interpretation']['kind'] ?? '') === 'saldo_apertura_cc')
        ) {
            return 'cc_apertura_confirmada';
        }
        if (in_array('cc_apertura_mercaderia_confirmada', $flags, true)
            || in_array('confirmed_opening_merca_balance', $flags, true)
            || (($row['interpretation']['kind'] ?? '') === 'saldo_apertura_mercaderia')
        ) {
            return 'cc_apertura_mercaderia_confirmada';
        }
        if (in_array('cc_cancelacion_daasa_confirmada', $flags, true)
            || in_array(($row['interpretation']['kind'] ?? ''), [
                'cc_cancelacion_con_cobro',
                'cc_cancelacion_deuda',
            ], true)
        ) {
            return 'cc_cancelacion_confirmada';
        }
        if (in_array('cliente_cintas_confirmado', $flags, true)
            && (($row['interpretation']['kind'] ?? '') === 'cc_cargo_cliente')
        ) {
            return 'cc_cargo_cliente_confirmado';
        }
        if (in_array('cobro_confirmado_patagonia', $flags, true)
            || in_array('cobro_confirmado_ft', $flags, true)
            || in_array('cc_in_inferido_cintas', $flags, true)
        ) {
            return 'venta_cobro_confirmado';
        }
        if (in_array('cc_omitida_probable', $flags, true)) {
            return 'cc_omitida_probable';
        }
        if (in_array('valor_historico_corregido_por_interpretacion', $flags, true)
            && in_array('cc_in_out_mismo_registro', $flags, true)) {
            return 'venta_cc_corregida_credito_cancelado';
        }
        if (in_array('valor_historico_corregido_por_interpretacion', $flags, true)) {
            return 'venta_cc_corregida_por_interpretacion';
        }
        if (in_array('cc_in_out_mismo_registro', $flags, true)) {
            return 'venta_credito_luego_cancelada';
        }
        if (in_array('venta_economica', $flags, true)) {
            if (in_array('cobro_desconocido', $flags, true)) {
                return 'venta_cobro_desconocido';
            }

            return 'venta_economica_reclasificada';
        }
        if (in_array('operacion_compleja', $flags, true)) {
            if (($amounts['venta'] ?? 0) > 0 && (($amounts['cc_in'] ?? 0) > 0 || ($amounts['merca_out'] ?? 0) > 0)) {
                return 'operacion_venta_compleja';
            }
            if (($amounts['merca_in'] ?? 0) > 0 && ($amounts['cc_out'] ?? 0) > 0) {
                return 'merca_cobro_cc';
            }
            if (($amounts['cc_out'] ?? 0) > 0 && ($amounts['ingresos'] ?? 0) > 0) {
                return 'cc_combinado_ingreso';
            }

            return 'operacion_compleja_otra';
        }

        if (in_array('fecha_sospechosa', $flags, true)) {
            return $status === 'red' ? 'fecha_invalida_sospechosa' : 'fecha_dudosa';
        }
        if (in_array('pendiente_completar', $flags, true) || $status === 'pending_complete') {
            return 'pendiente_completar';
        }
        if (in_array('fecha_inferida_cierre_mensual', $flags, true)) {
            return 'fecha_inferida_cierre_mensual';
        }
        if (in_array('fecha_aplicada_preview', $flags, true)) {
            return 'fecha_aplicada_preview';
        }
        if (in_array('asiento_intereses_ganados', $flags, true)) {
            return 'asiento_intereses_ganados';
        }
        if (in_array('fecha_apertura_revision', $flags, true) || in_array('fecha_corregible', $flags, true)) {
            return 'fecha_corregible';
        }
        if (in_array('cliente_ambiguo', $flags, true)) {
            return 'alias_cliente';
        }
        if (in_array('cuenta_desconocida', $flags, true)) {
            return 'cuenta_desconocida';
        }
        if (in_array('ambito_dudoso', $flags, true)) {
            return 'ambito_dudoso';
        }
        if (in_array('cc_combinado_ingreso', $flags, true) || (
            in_array('cc_movimiento', $flags, true) && ($amounts['cc_out'] ?? 0) > 0 && ($amounts['ingresos'] ?? 0) > 0
        )) {
            return 'cc_combinado_ingreso';
        }
        if (in_array('pago_tarjeta_posible', $flags, true)) {
            return 'pago_tarjeta';
        }
        if (in_array('proveedor_dudoso', $flags, true)) {
            return 'proveedor_dudoso';
        }
        if (in_array('cc_movimiento', $flags, true)) {
            return 'cc_simple_revision';
        }
        if (in_array('merca_analisis_only', $flags, true)) {
            return 'merca_analisis';
        }

        return 'otra_causa';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function saleSemanticsReport(array $rows): array
    {
        $sales = array_values(array_filter($rows, fn ($r) => ($r['amounts']['venta'] ?? 0) > 0.0001));
        $byKind = [];
        $unknownCash = [];
        $ccOmitted = [];
        $reclassified = [];

        foreach ($sales as $row) {
            $kind = $row['sale_kind'] ?? ($row['interpretation']['kind'] ?? 'unknown');
            $byKind[$kind] = ($byKind[$kind] ?? 0) + 1;
            $entry = [
                'source_row' => $row['source_row'] ?? null,
                'date' => $row['date'] ?? null,
                'concepto' => mb_substr((string) ($row['concepto'] ?? ''), 0, 80),
                'subcuenta' => $row['excel_subcuenta_account'] ?? null,
                'sale_kind' => $kind,
                'venta' => (float) ($row['amounts']['venta'] ?? 0),
                'merca_out' => (float) ($row['amounts']['merca_out'] ?? 0),
                'merca_in' => (float) ($row['amounts']['merca_in'] ?? 0),
                'utilidad' => (float) ($row['amounts']['ut_ventas'] ?? 0),
                'cc_in_excel' => (float) ($row['interpretation']['excel_cc_in'] ?? $row['amounts']['cc_in'] ?? 0),
                'cc_out_excel' => (float) ($row['interpretation']['excel_cc_out'] ?? $row['amounts']['cc_out'] ?? 0),
                'cc_in_interpretado' => (float) ($row['interpretation']['cc_charge'] ?? 0),
                'cc_out_interpretado' => (float) ($row['interpretation']['cc_payment'] ?? 0),
                'cc_in' => (float) ($row['amounts']['cc_in'] ?? 0),
                'cc_out' => (float) ($row['amounts']['cc_out'] ?? 0),
                'ingresos' => (float) ($row['amounts']['ingresos'] ?? 0),
                'finance_income' => (float) ($row['interpretation']['finance_income'] ?? 0),
                'corrections' => $row['interpretation']['corrections'] ?? [],
                'flags' => $row['flags'] ?? [],
                'review_status' => $row['review_status'] ?? null,
            ];
            $reclassified[] = $entry;
            if (in_array('cobro_desconocido', $row['flags'] ?? [], true)) {
                $unknownCash[] = $entry;
            }
            if (in_array('cc_omitida_probable', $row['flags'] ?? [], true)) {
                $ccOmitted[] = $entry;
            }
        }

        $personalRecoveries = [];
        foreach ($rows as $row) {
            if (($row['interpretation']['kind'] ?? '') === 'reintegro_gasto_personal'
                || in_array('reintegro_gasto_personal', $row['flags'] ?? [], true)) {
                $personalRecoveries[] = [
                    'source_row' => $row['source_row'] ?? null,
                    'concepto' => mb_substr((string) ($row['concepto'] ?? ''), 0, 80),
                    'excel_egresos' => (float) ($row['amounts']['egresos'] ?? 0),
                    'finance_income' => (float) ($row['interpretation']['finance_income'] ?? 0),
                    'net_expense_reduction' => (float) ($row['interpretation']['net_expense_reduction'] ?? 0),
                    'account' => $row['interpretation']['components']['CUENTA'] ?? $row['excel_subcuenta_account'] ?? null,
                    'flags' => $row['flags'] ?? [],
                ];
            }
        }

        $dates = [];
        foreach ($rows as $row) {
            if (! empty($row['suggested_date']) || in_array('fecha_sospechosa', $row['flags'] ?? [], true)
                || in_array('fecha_apertura_revision', $row['flags'] ?? [], true)
                || in_array('fecha_corregible', $row['flags'] ?? [], true)) {
                $dates[] = [
                    'source_row' => $row['source_row'] ?? null,
                    'date_original' => $row['date_original'] ?? $row['date'] ?? null,
                    'suggested_date' => $row['suggested_date'] ?? null,
                    'reason' => $row['date_suggestion_reason'] ?? null,
                    'concepto' => mb_substr((string) ($row['concepto'] ?? ''), 0, 60),
                    'month_context' => $row['month_context'] ?? null,
                    'opening' => in_array('fecha_apertura_revision', $row['flags'] ?? [], true),
                ];
            }
        }

        return [
            'sales_total' => count($sales),
            'by_kind' => $byKind,
            'reclassified' => $reclassified,
            'unknown_cash' => $unknownCash,
            'cc_omitted_probable' => $ccOmitted,
            'personal_recoveries' => $personalRecoveries,
            'dates_correctable' => $dates,
        ];
    }

    public function label(string $cause): string
    {
        return match ($cause) {
            'operacion_venta_compleja' => 'Operación venta compleja',
            'operacion_venta_compleja_resuelta' => 'Venta compleja resuelta (preview)',
            'venta_economica_reclasificada' => 'Venta económica reclasificada',
            'venta_credito_luego_cancelada' => 'Venta a crédito + CC OUT posterior (mismo registro)',
            'venta_cc_corregida_credito_cancelado' => 'Venta CC corregida (Excel utilidad→deuda=venta) + cancelación',
            'venta_cc_corregida_por_interpretacion' => 'Venta CC corregida por interpretación confirmada',
            'venta_cobro_desconocido' => 'Venta con cobro desconocido',
            'cc_omitida_probable' => 'Posible CC omitida',
            'merca_cobro_cc' => 'Mercadería + cobro + CC',
            'operacion_compleja_otra' => 'Operación compleja (otra)',
            'reintegro_gasto_personal' => 'Reintegro / recupero de gasto personal (no inconsistencia)',
            'fecha_invalida_sospechosa' => 'Fecha inválida/sospechosa',
            'fecha_dudosa' => 'Fecha dudosa',
            'fecha_corregible' => 'Fecha corregible (propuesta 2026 / enero)',
            'fecha_aplicada_preview' => 'Fecha propuesta aplicada en preview',
            'fecha_inferida_cierre_mensual' => 'Fecha inferida por cierre mensual',
            'asiento_intereses_ganados' => 'Asiento Intereses ganados (conservar aunque importe 0)',
            'pendiente_completar' => 'Pendiente de completar (anotación incompleta)',
            'alias_cliente' => 'Alias / cliente ambiguo',
            'cuenta_desconocida' => 'Cuenta financiera desconocida',
            'ambito_dudoso' => 'Ámbito Personal/Profesional dudoso',
            'cc_combinado_ingreso' => 'CC combinado con ingreso financiero',
            'pago_tarjeta' => 'Pago / movimiento de tarjeta',
            'pago_tarjeta_resuelto' => 'Tarjeta resuelta (preview)',
            'pago_resumen_tarjeta' => 'Pago de resumen de tarjeta (regla aprobada)',
            'pago_tarjeta_sin_importe' => 'Pago de resumen sin importe documentado',
            'importe_pago_tarjeta_desconocido' => 'Pago de resumen: importe desconocido (pendiente)',
            'pago_tarjeta_datos_incompletos' => 'Pago de resumen: falta tarjeta o cuenta de pago',
            'proveedor_dudoso' => 'Proveedor dudoso',
            'cc_simple_revision' => 'Movimiento CC a revisar',
            'cc_apertura_confirmada' => 'Saldo / ajuste de apertura de CC (confirmado)',
            'cc_apertura_mercaderia_confirmada' => 'Saldo / ajuste de apertura de mercadería (confirmado)',
            'cc_cancelacion_confirmada' => 'Cancelación de deuda CC confirmada',
            'cc_cargo_cliente_confirmado' => 'CC IN a cliente confirmado',
            'venta_cobro_confirmado' => 'Venta con CC/cobro confirmado',
            'merca_analisis' => 'Mercadería (solo análisis)',
            'inconsistencia_financiera' => 'Inconsistencia financiera',
            'excluida' => 'Fila excluida',
            'excluida_por_usuario' => 'Excluida por decisión del usuario',
            default => 'Otra causa',
        };
    }

    /**
     * @return array{key: string, type: string, description: string, unequivocal: bool}
     */
    public function proposedRule(string $cause): array
    {
        return match ($cause) {
            'cc_combinado_ingreso' => [
                'key' => 'cc_out_with_income',
                'type' => 'interpretation',
                'description' => 'Tratar CC OUT + ingreso como cobro vinculado a caja (sin duplicar ingreso). Clasificar Amarillo, no Rojo.',
                'unequivocal' => true,
            ],
            'cuenta_desconocida' => [
                'key' => 'account_alias',
                'type' => 'account_alias',
                'description' => 'Mapear SubCuenta → cuenta financiera + titular (ej. Patagonia → Banco Patagonia / Fernando).',
                'unequivocal' => false,
            ],
            'ambito_dudoso' => [
                'key' => 'scope_default',
                'type' => 'scope_default',
                'description' => 'Asignar ámbito default por categoría (Comidas→Personal) dejando override manual.',
                'unequivocal' => false,
            ],
            'alias_cliente' => [
                'key' => 'client_alias',
                'type' => 'client_alias',
                'description' => 'Fusionar alias ortográficos solo con score alto; resto “posible duplicado”.',
                'unequivocal' => false,
            ],
            'pago_tarjeta' => [
                'key' => 'card_liability',
                'type' => 'interpretation',
                'description' => 'Compra con egresos en VISA/MC/MCMP aumenta pasivo. Distinto de pagos_tc (pago de resumen).',
                'unequivocal' => false,
            ],
            'pago_resumen_tarjeta' => [
                'key' => 'pago_resumen_tarjeta',
                'type' => 'interpretation',
                'description' => 'pagos_tc = pago mensual del resumen al banco: disminuye pasivo tarjeta + cuenta de pago; no segundo gasto.',
                'unequivocal' => true,
            ],
            'pago_tarjeta_sin_importe' => [
                'key' => 'pago_resumen_sin_importe',
                'type' => 'manual',
                'description' => 'No inventar importe. Completar monto documentado, indicar otra fila, o excluir.',
                'unequivocal' => false,
            ],
            'importe_pago_tarjeta_desconocido' => [
                'key' => 'importe_pago_tarjeta_desconocido',
                'type' => 'annotation',
                'description' => 'Naturaleza confirmada (pago resumen); importe desconocido. Pendiente; no inventar; decision_required=false.',
                'unequivocal' => true,
            ],
            'pago_tarjeta_datos_incompletos' => [
                'key' => 'pago_resumen_datos',
                'type' => 'manual',
                'description' => 'Identificar tarjeta (VISA/MC/MCMP) y/o cuenta de pago; no preguntar Compra vs Pago.',
                'unequivocal' => false,
            ],
            'cc_apertura_mercaderia_confirmada' => [
                'key' => 'confirmed_opening_merca_balance',
                'type' => 'interpretation',
                'description' => 'Saldo apertura mercadería; no compra/venta/caja/stock ni cuenta Saldo Inicial.',
                'unequivocal' => true,
            ],
            'cc_cancelacion_confirmada', 'cc_cargo_cliente_confirmado', 'venta_cobro_confirmado' => [
                'key' => 'confirmed_cc_sale_resolution',
                'type' => 'interpretation',
                'description' => 'CC/venta/cobro resuelto por decisión explícita; conservar original; no inventar duplicados.',
                'unequivocal' => true,
            ],
            'operacion_venta_compleja' => [
                'key' => 'manual_complex_sale',
                'type' => 'manual',
                'description' => 'Revisión humana fila a fila; no auto-confirmar ventas con CC+merca+utilidad.',
                'unequivocal' => false,
            ],
            'venta_cc_corregida_credito_cancelado', 'venta_cc_corregida_por_interpretacion' => [
                'key' => 'confirmed_sale_cc_correction',
                'type' => 'interpretation',
                'description' => 'CC Excel ≈ utilidad; deuda interpretada = Venta. Conservar Excel original; no forzar diff a cero.',
                'unequivocal' => true,
            ],
            'cc_apertura_confirmada' => [
                'key' => 'confirmed_opening_cc_balance',
                'type' => 'interpretation',
                'description' => 'Saldo inicial deudor de CC confirmado; no venta/cobro/caja. Conservar Excel; CC OUT en misma fila no es cobro.',
                'unequivocal' => true,
            ],
            'reintegro_gasto_personal' => [
                'key' => 'personal_recovery',
                'type' => 'interpretation',
                'description' => 'Egreso negativo = reintegro personal: reduce gasto neto; entrada financiera solo si hay cuenta identificable.',
                'unequivocal' => true,
            ],
            'fecha_invalida_sospechosa', 'fecha_dudosa' => [
                'key' => 'date_review',
                'type' => 'manual',
                'description' => 'No corregir año automáticamente; confirmar o excluir.',
                'unequivocal' => false,
            ],
            'pendiente_completar' => [
                'key' => 'pending_complete_annotation',
                'type' => 'annotation',
                'description' => 'Conservar como pendiente de completar; no importar, no eliminar, no afectar conciliación. Feature “Pendientes de carga” en etapa posterior.',
                'unequivocal' => true,
            ],
            'fecha_aplicada_preview' => [
                'key' => 'date_applied_preview',
                'type' => 'interpretation',
                'description' => 'Fecha propuesta aplicada en preview; conservar date_original.',
                'unequivocal' => true,
            ],
            'fecha_inferida_cierre_mensual' => [
                'key' => 'month_end_closure',
                'type' => 'interpretation',
                'description' => 'Sin fecha al final del bloque mensual → último día del mes; conservar fecha original vacía.',
                'unequivocal' => true,
            ],
            'asiento_intereses_ganados' => [
                'key' => 'keep_zero_interest',
                'type' => 'interpretation',
                'description' => 'Conservar asiento Intereses ganados aunque importe = 0; no pending ni exclusión.',
                'unequivocal' => true,
            ],
            default => [
                'key' => 'manual_review',
                'type' => 'manual',
                'description' => 'Revisión manual; no aplicar regla masiva sin evidencia.',
                'unequivocal' => false,
            ],
        };
    }

    public function risk(string $cause): string
    {
        return match ($cause) {
            'cc_combinado_ingreso', 'pago_tarjeta', 'pago_resumen_tarjeta',
            'venta_cc_corregida_credito_cancelado', 'venta_cc_corregida_por_interpretacion',
            'cc_apertura_confirmada', 'cc_apertura_mercaderia_confirmada',
            'cc_cancelacion_confirmada', 'cc_cargo_cliente_confirmado', 'venta_cobro_confirmado',
            'importe_pago_tarjeta_desconocido',
            'reintegro_gasto_personal', 'pendiente_completar', 'fecha_aplicada_preview',
            'fecha_inferida_cierre_mensual', 'asiento_intereses_ganados' => 'Bajo — interpretación confirmada / inequívoca.',
            'pago_tarjeta_sin_importe', 'pago_tarjeta_datos_incompletos' => 'Medio — falta dato humano; no inventar.',
            'cuenta_desconocida' => 'Medio — depende del alias exacto; una mala cuenta contamina saldos.',
            'ambito_dudoso' => 'Medio — Comidas/Viáticos pueden ser profesionales en visitas técnicas.',
            'alias_cliente' => 'Alto si se fusiona por similaridad baja; preferir decisión humana.',
            'operacion_venta_compleja', 'merca_cobro_cc' => 'Alto — datos incompletos; no masificar.',
            'fecha_invalida_sospechosa', 'fecha_dudosa' => 'Alto — cambiar fechas altera períodos y conciliación.',
            default => 'Medio/Alto — requiere revisión.',
        };
    }
}
