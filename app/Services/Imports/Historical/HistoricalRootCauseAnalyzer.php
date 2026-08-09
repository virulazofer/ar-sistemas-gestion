<?php

namespace App\Services\Imports\Historical;

/**
 * Agrupa filas Amarillo/Rojo por causa raíz y atribuye diferencias de conciliación.
 */
class HistoricalRootCauseAnalyzer
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{yellow: list<array>, red: list<array>}
     */
    public function groupByRootCause(array $rows): array
    {
        $yellow = [];
        $red = [];

        foreach ($rows as $row) {
            $status = $row['review_status'] ?? '';
            if (! in_array($status, ['yellow', 'red'], true)) {
                continue;
            }
            $cause = $row['root_cause'] ?? $this->inferRootCause($row);
            $bucket = $status === 'red' ? 'red' : 'yellow';
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
            'ingresos' => ['excel_key' => 'ingresos', 'interp_key' => 'finance_income', 'rows' => [], 'total_gap' => 0.0],
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

    public function label(string $cause): string
    {
        return match ($cause) {
            'operacion_venta_compleja' => 'Operación venta compleja',
            'operacion_venta_compleja_resuelta' => 'Venta compleja resuelta (preview)',
            'merca_cobro_cc' => 'Mercadería + cobro + CC',
            'operacion_compleja_otra' => 'Operación compleja (otra)',
            'fecha_invalida_sospechosa' => 'Fecha inválida/sospechosa',
            'fecha_dudosa' => 'Fecha dudosa',
            'alias_cliente' => 'Alias / cliente ambiguo',
            'cuenta_desconocida' => 'Cuenta financiera desconocida',
            'ambito_dudoso' => 'Ámbito Personal/Profesional dudoso',
            'cc_combinado_ingreso' => 'CC combinado con ingreso financiero',
            'pago_tarjeta' => 'Pago / movimiento de tarjeta',
            'pago_tarjeta_resuelto' => 'Tarjeta resuelta (preview)',
            'proveedor_dudoso' => 'Proveedor dudoso',
            'cc_simple_revision' => 'Movimiento CC a revisar',
            'merca_analisis' => 'Mercadería (solo análisis)',
            'inconsistencia_financiera' => 'Inconsistencia financiera',
            'excluida' => 'Fila excluida',
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
                'description' => 'Gasto en VISA/MC/MCMP aumenta pasivo; pago de resumen = transferencia banco→tarjeta (no nuevo gasto).',
                'unequivocal' => true,
            ],
            'operacion_venta_compleja' => [
                'key' => 'manual_complex_sale',
                'type' => 'manual',
                'description' => 'Revisión humana fila a fila; no auto-confirmar ventas con CC+merca+utilidad.',
                'unequivocal' => false,
            ],
            'fecha_invalida_sospechosa', 'fecha_dudosa' => [
                'key' => 'date_review',
                'type' => 'manual',
                'description' => 'No corregir año automáticamente; confirmar o excluir.',
                'unequivocal' => false,
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
            'cc_combinado_ingreso', 'pago_tarjeta' => 'Bajo — alineado a reglas de negocio confirmadas.',
            'cuenta_desconocida' => 'Medio — depende del alias exacto; una mala cuenta contamina saldos.',
            'ambito_dudoso' => 'Medio — Comidas/Viáticos pueden ser profesionales en visitas técnicas.',
            'alias_cliente' => 'Alto si se fusiona por similaridad baja; preferir decisión humana.',
            'operacion_venta_compleja', 'merca_cobro_cc' => 'Alto — datos incompletos; no masificar.',
            'fecha_invalida_sospechosa', 'fecha_dudosa' => 'Alto — cambiar fechas altera períodos y conciliación.',
            default => 'Medio/Alto — requiere revisión.',
        };
    }
}
