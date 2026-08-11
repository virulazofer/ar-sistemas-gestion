<?php

namespace App\Services\Imports\Historical;

/**
 * Semántica confirmada del histórico 2026 para ventas / CC / utilidad / cobro.
 *
 * Utilidad = Venta - Merca OUT + Merca IN (resultado económico, NO caja).
 * CC IN = aumenta deuda cliente; CC OUT = reduce deuda (pueden no ser el mismo día).
 * Cobro financiero solo si hay Ingresos Excel con medio identificable — nunca inventar desde Utilidad/SubCuenta.
 *
 * Cuando el usuario confirma la operación real, esa interpretación tiene prioridad sobre
 * valores inconsistentes del Excel, conservando trazabilidad del original.
 */
class HistoricalSaleSemantics
{
    /**
     * @param  array<string, float>  $amounts
     * @return array<string, mixed>
     */
    public function analyzeSale(
        array $amounts,
        string $cuenta,
        string $subcuenta,
        ?array $accountDef,
        ?string $client,
        ?int $sourceRow = null,
        ?string $concepto = null,
    ): array {
        $venta = (float) ($amounts['venta'] ?? 0);
        $mercaOut = (float) ($amounts['merca_out'] ?? 0);
        $mercaIn = (float) ($amounts['merca_in'] ?? 0);
        $utilidad = (float) ($amounts['ut_ventas'] ?? 0);
        $excelCcIn = (float) ($amounts['cc_in'] ?? 0);
        $excelCcOut = (float) ($amounts['cc_out'] ?? 0);
        $ingresos = (float) ($amounts['ingresos'] ?? 0);
        $egresos = (float) ($amounts['egresos'] ?? 0);

        $expectedUtilidad = round($venta - $mercaOut + $mercaIn, 2);
        $utilidadOk = $venta <= 0 || abs($expectedUtilidad - $utilidad) < 1.0 || $utilidad <= 0;

        $hasDocumentedCash = $ingresos > 0.0001 && $accountDef !== null;

        if ($venta <= 0.0001) {
            return [
                'is_sale' => false,
                'sale_kind' => 'none',
                'flags' => [],
                'components' => null,
                'finance_income' => 0.0,
                'finance_expense' => 0.0,
                'cc_charge' => 0.0,
                'cc_payment' => 0.0,
                'excel_cc_in' => $excelCcIn,
                'excel_cc_out' => $excelCcOut,
                'notes' => [],
                'would_generate' => [],
                'corrections' => [],
            ];
        }

        $flags = ['venta_economica', 'utilidad_no_caja'];
        $corrections = [];
        $ccIn = $excelCcIn;
        $ccOut = $excelCcOut;
        $saleKind = 'none';
        $notes = [
            'Venta = precio total; Merca OUT = costo entregado; Merca IN = valor recibido; Utilidad = resultado económico.',
            'Utilidad NO genera movimiento de caja/banco.',
            sprintf(
                'Chequeo: Venta - Merca OUT + Merca IN = %.2f (Utilidad Excel %.2f)%s',
                $expectedUtilidad,
                $utilidad,
                $utilidadOk ? '' : ' — revisar'
            ),
        ];

        // 1) Override confirmado por usuario (prioridad sobre Excel inconsistente)
        $override = $this->matchConfirmedCorrection($sourceRow, $concepto, $venta);
        if ($override) {
            $ccIn = (float) $override['interpret']['cc_in'];
            $ccOut = (float) $override['interpret']['cc_out'];
            $saleKind = (string) $override['interpret']['sale_kind'];
            $flags[] = 'valor_historico_corregido_por_interpretacion';
            if ($ccIn > 0 && $ccOut > 0) {
                $flags[] = 'cc_in_out_mismo_registro';
                $flags[] = 'cc_fechas_no_asumir_mismo_dia';
            }
            $corrections[] = [
                'field' => 'cc_in',
                'excel' => $excelCcIn,
                'interpreted' => $ccIn,
                'delta' => round($ccIn - $excelCcIn, 2),
                'reason' => $override['interpret']['reason'],
            ];
            $corrections[] = [
                'field' => 'cc_out',
                'excel' => $excelCcOut,
                'interpreted' => $ccOut,
                'delta' => round($ccOut - $excelCcOut, 2),
                'reason' => $override['interpret']['reason'],
            ];
            $notes[] = 'valor histórico corregido por interpretación confirmada';
            $notes[] = $override['interpret']['reason'];
        } elseif (config('historical_sale_corrections.auto_cc_equals_margin_not_venta', true)
            && $this->ccLooksLikeMarginNotVenta($excelCcIn, $venta, $expectedUtilidad, $utilidad)
        ) {
            // 2) Auto: CC cargado con utilidad/margen en lugar de deuda = venta
            $ccIn = $venta;
            $flags[] = 'valor_historico_corregido_por_interpretacion';
            $flags[] = 'cc_cargado_como_utilidad';
            $corrections[] = [
                'field' => 'cc_in',
                'excel' => $excelCcIn,
                'interpreted' => $ccIn,
                'delta' => round($ccIn - $excelCcIn, 2),
                'reason' => 'CC IN Excel ≈ utilidad/margen; interpretado como deuda = Venta.',
            ];
            if ($excelCcOut > 0 && abs($excelCcOut - $excelCcIn) < 1.0) {
                $ccOut = $venta;
                $corrections[] = [
                    'field' => 'cc_out',
                    'excel' => $excelCcOut,
                    'interpreted' => $ccOut,
                    'delta' => round($ccOut - $excelCcOut, 2),
                    'reason' => 'CC OUT Excel igualaba CC IN (utilidad); cancelación interpretada por Venta.',
                ];
            }
            $notes[] = 'valor histórico corregido por interpretación confirmada';
            $notes[] = 'Patrón detectado: CC Excel = utilidad/margen, no deuda cliente (=Venta).';
        }

        // Clasificar kind con montos ya interpretados
        if ($saleKind === 'none') {
            if ($ccIn > 0 && $ccOut > 0) {
                $saleKind = 'credito_luego_cancelado';
                $flags[] = 'cc_in_out_mismo_registro';
                $flags[] = 'cc_fechas_no_asumir_mismo_dia';
            } elseif ($ccIn > 0 && $ingresos <= 0) {
                $saleKind = 'credito_abierto';
            } elseif ($hasDocumentedCash && $ccIn <= 0) {
                $saleKind = 'contado_documentado';
            } elseif ($ccIn <= 0 && $ingresos <= 0) {
                $saleKind = 'cobro_o_cc_omitida';
                $flags[] = 'cc_omitida_probable';
                $flags[] = 'cobro_desconocido';
            } else {
                $saleKind = 'mixta_revision';
                $flags[] = 'cobro_desconocido';
            }
        }

        if ($accountDef && $ingresos <= 0 && $utilidad > 0) {
            $flags[] = 'no_asumir_cobro_por_subcuenta';
        }
        if (! $utilidadOk && $utilidad > 0) {
            $flags[] = 'utilidad_inconsistente';
        }
        if ($mercaOut > 0 || $mercaIn > 0) {
            $flags[] = 'merca_analisis_only';
        }

        if ($saleKind === 'credito_luego_cancelado') {
            $notes[] = 'CC IN + CC OUT: venta a crédito y cancelación posterior; NO asumir mismo día real.';
            $notes[] = 'Cobro financiero desconocido si no hay Ingresos documentados — no inventar banco.';
        }
        if (in_array('cc_omitida_probable', $flags, true)) {
            $notes[] = 'Venta sin cobro financiero identificable ni CC IN interpretado: posible CC omitida.';
        }

        $would = [
            'VENTA (bruta): '.$venta,
            'COSTO MERCADERÍA (Merca OUT): '.$mercaOut,
            'MERCADERÍA RECIBIDA (Merca IN): '.$mercaIn,
            'UTILIDAD/RESULTADO: '.$utilidad.' (no caja)',
            'CC IN Excel: '.$excelCcIn.' → interpretado: '.$ccIn,
            'CC OUT Excel: '.$excelCcOut.' → interpretado: '.$ccOut,
        ];
        if ($hasDocumentedCash) {
            $would[] = 'COBRO FINANCIERO documentado: '.$ingresos.' en '.($accountDef['name'] ?? $subcuenta);
        } else {
            $would[] = 'COBRO FINANCIERO: desconocido / no documentado';
        }

        return [
            'is_sale' => true,
            'sale_kind' => $saleKind,
            'flags' => array_values(array_unique($flags)),
            'components' => [
                'VENTA' => $venta,
                'COSTO_MERCADERIA' => $mercaOut,
                'MERCADERIA_RECIBIDA' => $mercaIn,
                'UTILIDAD' => $utilidad,
                'UTILIDAD_CALCULADA' => $expectedUtilidad,
                'CC_IN_EXCEL' => $excelCcIn,
                'CC_OUT_EXCEL' => $excelCcOut,
                'CC_IN_INTERPRETADO' => $ccIn,
                'CC_OUT_INTERPRETADO' => $ccOut,
                'COBRO_FINANCIERO' => $hasDocumentedCash ? $ingresos : 0.0,
                'COBRO_DOCUMENTADO' => $hasDocumentedCash,
            ],
            'finance_income' => $hasDocumentedCash ? $ingresos : 0.0,
            'finance_expense' => $egresos > 0 ? $egresos : 0.0,
            'cc_charge' => $ccIn,
            'cc_payment' => $ccOut,
            'excel_cc_in' => $excelCcIn,
            'excel_cc_out' => $excelCcOut,
            'notes' => $notes,
            'would_generate' => $would,
            'corrections' => $corrections,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function analyzePersonalRecovery(
        string $concepto,
        string $cuenta,
        string $subcuenta,
        array $amounts,
        ?array $accountDef,
    ): ?array {
        foreach (config('historical_sale_corrections.personal_recoveries', []) as $rule) {
            $regex = $rule['concepto_regex'] ?? null;
            if (! $regex || ! preg_match($regex, $concepto)) {
                continue;
            }
            $egresos = (float) ($amounts['egresos'] ?? 0);
            $ingresos = (float) ($amounts['ingresos'] ?? 0);
            $amount = abs($egresos) > 0.0001 ? abs($egresos) : $ingresos;
            if ($amount <= 0.0001) {
                return null;
            }
            $hasAccount = $accountDef !== null;
            $notes = [
                $rule['note'] ?? 'Reintegro / recupero de gasto personal.',
                'No es ingreso profesional, venta, CC, cliente ni proveedor.',
                'Reduce el gasto neto de la categoría '.($rule['category'] ?? $cuenta).'.',
            ];
            if (! $hasAccount) {
                $notes[] = 'Medio de recepción histórico no determinado — no se inventa cuenta financiera.';
            }

            return [
                'kind' => $rule['kind'] ?? 'reintegro_gasto_personal',
                'flags' => array_values(array_filter([
                    'reintegro_gasto_personal',
                    $hasAccount ? 'reintegro_con_cuenta' : 'medio_historico_no_determinado',
                ])),
                'amount' => $amount,
                'finance_income' => $hasAccount ? $amount : 0.0,
                'finance_expense' => 0.0,
                'net_expense_reduction' => $amount,
                'category' => $rule['category'] ?? $cuenta,
                'account' => $hasAccount ? ($accountDef['name'] ?? $subcuenta) : null,
                'excel_egresos' => $egresos,
                'notes' => $notes,
                'would_generate' => array_values(array_filter([
                    'REINTEGRO / RECUPERO DE GASTO PERSONAL: '.$amount,
                    $hasAccount ? ('Entrada financiera '.$amount.' en '.($accountDef['name'] ?? $subcuenta)) : 'Medio no determinado',
                    'Reduce gasto neto '.($rule['category'] ?? $cuenta).' en '.$amount,
                ])),
            ];
        }

        return null;
    }

    public function isOpeningOrCarryforward(string $concepto, string $cuenta, string $subcuenta): bool
    {
        $blob = mb_strtolower($concepto.' '.$cuenta.' '.$subcuenta);

        return str_contains($blob, 'saldo')
            || str_contains($blob, 'apertura')
            || str_contains($blob, 'arrastre')
            || str_contains($blob, 'ejercicio anterior')
            || str_contains($blob, 'saldo inicial')
            || str_contains($blob, 'cc inicial');
    }

    /**
     * Saldo / ajuste de apertura de CC confirmado explícitamente por el usuario.
     * Usa el CC IN del Excel (no inventa importe). CC OUT en la misma fila no es cobro.
     *
     * @param  array<string, float>  $amounts
     * @return array<string, mixed>|null
     */
    public function analyzeConfirmedOpeningCcBalance(
        ?int $sourceRow,
        ?string $concepto,
        array $amounts,
        ?string $client = null,
    ): ?array {
        $rule = $this->matchConfirmedOpeningCcBalance($sourceRow, $concepto, $amounts);
        if (! $rule) {
            return null;
        }

        $excelCcIn = (float) ($amounts['cc_in'] ?? 0);
        $excelCcOut = (float) ($amounts['cc_out'] ?? 0);
        $ccIn = $excelCcIn; // no inventar: importe = Excel documentado
        $ccOut = 0.0;
        $resolvedClient = (string) ($rule['client'] ?? $client ?? '');
        $reason = (string) ($rule['interpret']['reason'] ?? 'Saldo de apertura de CC confirmado por el usuario.');

        $corrections = [];
        if (abs($excelCcOut - $ccOut) > 0.0001) {
            $corrections[] = [
                'field' => 'cc_out',
                'excel' => $excelCcOut,
                'interpreted' => $ccOut,
                'delta' => round($ccOut - $excelCcOut, 2),
                'reason' => 'CC OUT en fila de apertura no es cobro; saldo inicial deudor = CC IN.',
            ];
        }

        return [
            'kind' => (string) ($rule['interpret']['kind'] ?? 'saldo_apertura_cc'),
            'client' => $resolvedClient !== '' ? $resolvedClient : null,
            'flags' => [
                'cc_apertura_confirmada',
                'confirmed_opening_cc_balance',
                'cc_movimiento',
            ],
            'cc_charge' => $ccIn,
            'cc_payment' => $ccOut,
            'finance_income' => 0.0,
            'finance_expense' => 0.0,
            'excel_cc_in' => $excelCcIn,
            'excel_cc_out' => $excelCcOut,
            'is_opening_adjustment' => true,
            'corrections' => $corrections,
            'components' => [
                'TIPO' => 'SALDO / AJUSTE DE APERTURA DE CUENTA CORRIENTE',
                'CLIENTE' => $resolvedClient !== '' ? $resolvedClient : null,
                'CC_IN_INICIAL' => $ccIn,
                'CC_IN_EXCEL' => $excelCcIn,
                'CC_OUT_EXCEL' => $excelCcOut,
                'CC_OUT_INTERPRETADO' => $ccOut,
                'VENTA' => 0.0,
                'COBRO_FINANCIERO' => 0.0,
            ],
            'notes' => [
                'Saldo / ajuste de apertura de cuenta corriente confirmado por el usuario.',
                $reason,
                'Establece saldo inicial deudor del cliente; no genera venta, ingreso financiero, cobro ni movimiento de caja/banco.',
                'Dato original Excel conservado; flag cc_apertura_confirmada / confirmed_opening_cc_balance.',
            ],
            'would_generate' => array_values(array_filter([
                'SALDO APERTURA CC (deuda inicial): '.$ccIn
                    .($resolvedClient !== '' ? ' → '.$resolvedClient : ''),
                'NO venta 2026',
                'NO ingreso financiero / cobro / caja-banco',
                $excelCcOut > 0.0001
                    ? 'Excel CC OUT '.$excelCcOut.' conservado en original; interpretado 0 (no cobro)'
                    : null,
            ])),
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, float>  $amounts
     * @return array<string, mixed>|null
     */
    public function matchConfirmedOpeningCcBalance(
        ?int $sourceRow,
        ?string $concepto,
        array $amounts,
    ): ?array {
        $excelCcIn = (float) ($amounts['cc_in'] ?? 0);
        if ($excelCcIn <= 0.0001) {
            return null; // no inventar importe
        }

        foreach (config('historical_sale_corrections.confirmed_opening_cc_balances', []) as $rule) {
            $needle = (string) ($rule['concepto_contains'] ?? '');
            $conceptoOk = $needle === '' || ($concepto !== null && stripos($concepto, $needle) !== false);
            if (! $conceptoOk) {
                continue;
            }
            if (isset($rule['cc_in']) && abs((float) $rule['cc_in'] - $excelCcIn) > 1.0) {
                continue;
            }
            if ($sourceRow !== null && (int) ($rule['source_row'] ?? 0) === $sourceRow) {
                return $rule;
            }
            if ($needle !== '' && $conceptoOk) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Fecha vacía al final del bloque mensual → último día del mes del bloque.
     *
     * @return array{suggested:?string,reason:?string,rule:?string,auto_safe:bool,source:?string}
     */
    public function suggestMonthEndClosureDate(
        ?string $iso,
        ?string $monthContext,
        ?int $sourceRow = null,
        int $year = 2026,
    ): array {
        if ($iso && preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) {
            return ['suggested' => null, 'reason' => null, 'rule' => null, 'auto_safe' => false, 'source' => null];
        }

        $rule = (string) config('historical_date_closure.month_end_closure_rule', 'fecha_inferida_por_cierre_mensual');
        $confirmed = config('historical_date_closure.month_end_closure_confirmed_rows', []);
        if ($sourceRow !== null && isset($confirmed[$sourceRow]['iso'])) {
            return [
                'suggested' => (string) $confirmed[$sourceRow]['iso'],
                'reason' => 'fecha inferida por cierre mensual (fila confirmada; bloque '
                    .($confirmed[$sourceRow]['month'] ?? '?').')',
                'rule' => $rule,
                'auto_safe' => true,
                'source' => 'confirmed_row',
            ];
        }

        if (! $monthContext) {
            return ['suggested' => null, 'reason' => null, 'rule' => null, 'auto_safe' => false, 'source' => null];
        }

        $map = config('historical_date_closure.month_name_to_number', []);
        $key = mb_strtolower(trim($monthContext));
        if (! isset($map[$key])) {
            return ['suggested' => null, 'reason' => null, 'rule' => null, 'auto_safe' => false, 'source' => null];
        }

        $month = (int) $map[$key];
        $lastDay = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
            ->modify('last day of this month')
            ->format('d');
        $suggested = sprintf('%04d-%02d-%02d', $year, $month, $lastDay);

        return [
            'suggested' => $suggested,
            'reason' => "fecha inferida por cierre mensual (bloque {$key} → último día {$suggested})",
            'rule' => $rule,
            'auto_safe' => true,
            'source' => 'month_block',
        ];
    }

    /**
     * @return array{suggested:?string,reason:?string,auto_safe:bool}
     */
    public function suggestDateCorrection(
        ?string $iso,
        string $concepto,
        string $cuenta,
        string $subcuenta,
        ?string $monthContext,
    ): array {
        if (! $iso || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) {
            return ['suggested' => null, 'reason' => 'Fecha no parseable', 'auto_safe' => false];
        }
        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        $opening = $this->isOpeningOrCarryforward($concepto, $cuenta, $subcuenta);

        if ($monthContext && str_starts_with(mb_strtolower($monthContext), 'enero') && $month === 12) {
            $suggested = ($day === 1)
                ? '2026-01-12'
                : sprintf('2026-01-%02d', min(max($day, 1), 28));

            return [
                'suggested' => $suggested,
                'reason' => "Bloque enero: {$iso} con mes 12; proponer enero 2026. Original conservado.",
                'auto_safe' => false,
            ];
        }

        if ($year === 2025) {
            if ($opening) {
                return [
                    'suggested' => null,
                    'reason' => 'Saldo/arrastre de ejercicio anterior — NO convertir automáticamente a 2026',
                    'auto_safe' => false,
                ];
            }

            return [
                'suggested' => sprintf('2026-%02d-%02d', $month, $day),
                'reason' => 'No hay movimientos operativos reales 2025 en esta planilla; proponer 2026 conservando mes/día',
                'auto_safe' => false,
            ];
        }

        return ['suggested' => null, 'reason' => null, 'auto_safe' => false];
    }

    /**
     * Ajuste / saldo de apertura de mercadería confirmado (no compra/venta/caja/stock).
     *
     * @param  array<string, float>  $amounts
     * @return array<string, mixed>|null
     */
    public function analyzeConfirmedOpeningMercaBalance(
        ?int $sourceRow,
        ?string $concepto,
        array $amounts = [],
    ): ?array {
        $rule = $this->matchByRowConcept(
            config('historical_sale_corrections.confirmed_opening_merca_balances', []),
            $sourceRow,
            $concepto,
        );
        if (! $rule) {
            return null;
        }

        $interp = $rule['interpret'] ?? [];
        $reason = (string) ($interp['reason'] ?? 'Saldo de apertura de mercadería confirmado.');

        return [
            'kind' => (string) ($interp['kind'] ?? 'saldo_apertura_mercaderia'),
            'client' => null,
            'flags' => array_values(array_unique(array_merge(
                $interp['flags'] ?? ['cc_apertura_mercaderia_confirmada', 'confirmed_opening_merca_balance'],
                ['valor_historico_corregido_por_interpretacion'],
            ))),
            'cc_charge' => 0.0,
            'cc_payment' => 0.0,
            'finance_income' => 0.0,
            'finance_expense' => 0.0,
            'economic_venta' => 0.0,
            'excel_cc_in' => (float) ($amounts['cc_in'] ?? 0),
            'excel_cc_out' => (float) ($amounts['cc_out'] ?? 0),
            'is_opening_adjustment' => true,
            'corrections' => [],
            'components' => [
                'TIPO' => 'SALDO / AJUSTE DE APERTURA DE MERCADERÍA',
                'VENTA' => 0.0,
                'COBRO_FINANCIERO' => 0.0,
                'STOCK_FISICO' => false,
                'CUENTA_SALDO_INICIAL' => false,
            ],
            'notes' => [
                'Ajuste / saldo de apertura de mercadería confirmado por el usuario.',
                $reason,
                'No genera compra 2026, venta, caja/banco, cuenta "Saldo Inicial" ni stock físico.',
                'Dato original Excel conservado; flag cc_apertura_mercaderia_confirmada.',
            ],
            'would_generate' => [
                'SALDO / AJUSTE APERTURA MERCADERÍA (análisis)',
                'NO compra 2026',
                'NO venta',
                'NO caja/banco',
                'NO crear cuenta Saldo Inicial',
                'NO stock físico',
            ],
            'reason' => $reason,
        ];
    }

    /**
     * Venta con CC/cobro confirmados cuando Excel omitió el registro.
     *
     * @param  array<string, float>  $amounts
     * @return array<string, mixed>|null
     */
    public function analyzeConfirmedSaleResolution(
        ?int $sourceRow,
        ?string $concepto,
        array $amounts,
        ?array $accountDef = null,
    ): ?array {
        $venta = (float) ($amounts['venta'] ?? 0);
        $rule = null;
        foreach (config('historical_sale_corrections.confirmed_sale_resolutions', []) as $candidate) {
            $needle = (string) ($candidate['concepto_contains'] ?? '');
            $conceptoOk = $needle === '' || ($concepto !== null && stripos($concepto, $needle) !== false);
            if (! $conceptoOk) {
                continue;
            }
            // Solo fila Excel exacta (evita aplicar a otras ventas del mismo cliente).
            if ($sourceRow !== null && (int) ($candidate['source_row'] ?? 0) === $sourceRow) {
                if (isset($candidate['venta']) && $venta > 0 && abs((float) $candidate['venta'] - $venta) > 1.0) {
                    continue;
                }
                $rule = $candidate;
                break;
            }
        }
        if (! $rule) {
            return null;
        }

        $interp = $rule['interpret'] ?? [];
        $mercaOut = (float) ($amounts['merca_out'] ?? 0);
        $mercaIn = (float) ($amounts['merca_in'] ?? 0);
        $utilidad = (float) ($amounts['ut_ventas'] ?? 0);
        $expectedUtilidad = round($venta - $mercaOut + $mercaIn, 2);
        $ccIn = (float) ($interp['cc_in'] ?? 0);
        $ccOut = (float) ($interp['cc_out'] ?? 0);
        $financeIncome = (float) ($interp['finance_income'] ?? 0);
        $alias = $interp['finance_account_alias'] ?? null;
        $accountName = null;
        if ($alias) {
            $def = config('historical_import.financial_aliases.'.$alias);
            $accountName = is_array($def) ? (string) ($def['name'] ?? $alias) : (string) $alias;
        }
        $saleKind = (string) ($interp['sale_kind'] ?? 'credito_abierto');
        $reason = (string) ($interp['reason'] ?? 'Venta resuelta por decisión confirmada.');
        $flags = array_values(array_unique(array_merge(
            ['venta_economica', 'utilidad_no_caja', 'valor_historico_corregido_por_interpretacion'],
            $interp['flags'] ?? [],
            $mercaOut > 0 || $mercaIn > 0 ? ['merca_analisis_only'] : [],
        )));

        $would = [
            'VENTA (bruta): '.$venta,
            'COSTO MERCADERÍA (Merca OUT): '.$mercaOut,
            'MERCADERÍA RECIBIDA (Merca IN): '.$mercaIn,
            'UTILIDAD/RESULTADO: '.$utilidad.' (no caja; calc '.$expectedUtilidad.')',
            'CC IN interpretado: '.$ccIn.' (Excel '.(float) ($amounts['cc_in'] ?? 0).')',
            'CC OUT interpretado: '.$ccOut.' (Excel '.(float) ($amounts['cc_out'] ?? 0).')',
        ];
        if ($financeIncome > 0.0001 && $accountName) {
            $would[] = 'COBRO FINANCIERO confirmado: '.$financeIncome.' en '.$accountName;
        } else {
            $would[] = 'COBRO FINANCIERO: no inventado (solo CC si aplica)';
        }

        $corrections = [];
        $excelCcIn = (float) ($amounts['cc_in'] ?? 0);
        if (abs($ccIn - $excelCcIn) > 0.0001) {
            $corrections[] = [
                'field' => 'cc_in',
                'excel' => $excelCcIn,
                'interpreted' => $ccIn,
                'delta' => round($ccIn - $excelCcIn, 2),
                'reason' => $reason,
            ];
        }

        return [
            'kind' => 'sale_'.$saleKind,
            'sale_kind' => $saleKind,
            'client' => $interp['client'] ?? null,
            'flags' => $flags,
            'cc_charge' => $ccIn,
            'cc_payment' => $ccOut,
            'finance_income' => $financeIncome,
            'finance_expense' => 0.0,
            'finance_account_alias' => $alias,
            'finance_account_name' => $accountName,
            'check_duplicate_income' => (bool) ($interp['check_duplicate_income'] ?? false),
            'excel_cc_in' => $excelCcIn,
            'excel_cc_out' => (float) ($amounts['cc_out'] ?? 0),
            'economic_venta' => $venta,
            'economic_utilidad' => $utilidad > 0 ? $utilidad : $expectedUtilidad,
            'merca_out' => $mercaOut,
            'merca_in' => $mercaIn,
            'corrections' => $corrections,
            'components' => [
                'VENTA' => $venta,
                'COSTO_MERCADERIA' => $mercaOut,
                'MERCADERIA_RECIBIDA' => $mercaIn,
                'UTILIDAD' => $utilidad,
                'UTILIDAD_CALCULADA' => $expectedUtilidad,
                'CC_IN_INTERPRETADO' => $ccIn,
                'CC_OUT_INTERPRETADO' => $ccOut,
                'COBRO_FINANCIERO' => $financeIncome,
                'CUENTA_COBRO' => $accountName,
            ],
            'notes' => [
                'Venta económica con resolución confirmada por el usuario.',
                $reason,
                'Utilidad NO es cobro; cobro financiero solo si está confirmado/documentado.',
                'Dato original Excel conservado.',
            ],
            'would_generate' => $would,
            'reason' => $reason,
        ];
    }

    /**
     * Cancelación CC / cobro confirmado sin venta nueva.
     *
     * @param  array<string, float>  $amounts
     * @return array<string, mixed>|null
     */
    public function analyzeConfirmedCcSettlement(
        ?int $sourceRow,
        ?string $concepto,
        array $amounts,
        ?string $subcuenta = null,
    ): ?array {
        $rule = $this->matchByRowConcept(
            config('historical_sale_corrections.confirmed_cc_settlements', []),
            $sourceRow,
            $concepto,
        );
        if (! $rule) {
            return null;
        }

        $interp = $rule['interpret'] ?? [];
        $excelCcOut = (float) ($amounts['cc_out'] ?? 0);
        $ccOut = (float) ($interp['cc_out'] ?? $excelCcOut);
        $financeIncome = (float) ($interp['finance_income'] ?? 0);
        $alias = $interp['finance_account_alias'] ?? null;
        $linkIfUnequivocal = (bool) ($interp['link_account_if_unequivocal'] ?? false);
        $accountName = null;
        if ($alias) {
            $def = config('historical_import.financial_aliases.'.$alias);
            if (is_array($def)) {
                $accountName = (string) ($def['name'] ?? $alias);
            }
            if ($linkIfUnequivocal) {
                $sub = trim((string) ($subcuenta ?? ''));
                $subMatchesAlias = $sub !== '' && strcasecmp($sub, (string) $alias) === 0;
                $subDef = $sub !== '' ? (config('historical_import.financial_aliases.'.$sub) ?? null) : null;
                $subUnequivocalSame = is_array($subDef)
                    && strcasecmp((string) ($subDef['alias'] ?? $sub), (string) $alias) === 0;
                if (! $subMatchesAlias && ! $subUnequivocalSame) {
                    // No inventar medio de cobro si SubCuenta no es inequívoca.
                    $financeIncome = 0.0;
                    $accountName = null;
                    $alias = null;
                }
            }
        }
        $reason = (string) ($interp['reason'] ?? 'Cancelación CC confirmada.');
        $mercaOut = (float) ($amounts['merca_out'] ?? 0);
        $mercaIn = (float) ($amounts['merca_in'] ?? 0);

        return [
            'kind' => (string) ($interp['kind'] ?? 'cc_cancelacion_deuda'),
            'client' => $interp['client'] ?? null,
            'preserve_concepto' => (bool) ($interp['preserve_concepto'] ?? false),
            'flags' => array_values(array_unique(array_merge(
                $interp['flags'] ?? ['valor_historico_corregido_por_interpretacion'],
                $mercaOut > 0 || $mercaIn > 0 ? ['merca_analisis_only'] : [],
                ['cc_movimiento'],
            ))),
            'cc_charge' => 0.0,
            'cc_payment' => $ccOut,
            'finance_income' => $financeIncome,
            'finance_expense' => 0.0,
            'finance_account_alias' => $alias,
            'finance_account_name' => $accountName,
            'check_duplicate_income' => (bool) ($interp['check_duplicate_income'] ?? false),
            'economic_venta' => (float) ($interp['economic_venta'] ?? 0),
            'economic_utilidad' => 0.0,
            'merca_out' => $mercaOut,
            'merca_in' => $mercaIn,
            'excel_cc_in' => (float) ($amounts['cc_in'] ?? 0),
            'excel_cc_out' => $excelCcOut,
            'corrections' => [],
            'components' => [
                'TIPO' => 'CANCELACIÓN DEUDA CC',
                'CLIENTE' => $interp['client'] ?? null,
                'CC_OUT' => $ccOut,
                'COBRO_FINANCIERO' => $financeIncome,
                'CUENTA_COBRO' => $accountName,
                'VENTA_NUEVA' => 0.0,
            ],
            'notes' => [
                'Cancelación de deuda CC confirmada por el usuario.',
                $reason,
                'No es venta nueva.',
                'Dato original / concepto Excel conservado.',
            ],
            'would_generate' => array_values(array_filter([
                'CC OUT (cancela deuda): '.$ccOut
                    .(! empty($interp['client']) ? ' → '.$interp['client'] : ''),
                $financeIncome > 0.0001 && $accountName
                    ? 'COBRO FINANCIERO: '.$financeIncome.' en '.$accountName
                    : 'COBRO FINANCIERO: no inventado / pendiente de vínculo inequívoco',
                'NO venta nueva',
                $mercaOut > 0 || $mercaIn > 0 ? 'Merca IN/OUT solo análisis — no stock' : null,
            ])),
            'reason' => $reason,
        ];
    }

    /**
     * CC IN a cliente confirmado (SubCuenta = cliente, no financiera).
     *
     * @param  array<string, float>  $amounts
     * @return array<string, mixed>|null
     */
    public function analyzeConfirmedClientCcCharge(
        ?int $sourceRow,
        ?string $concepto,
        array $amounts,
    ): ?array {
        $rule = $this->matchByRowConcept(
            config('historical_sale_corrections.confirmed_client_cc_charges', []),
            $sourceRow,
            $concepto,
        );
        if (! $rule) {
            return null;
        }

        $interp = $rule['interpret'] ?? [];
        $excelCcIn = (float) ($amounts['cc_in'] ?? 0);
        $ccIn = (float) ($interp['cc_in'] ?? $excelCcIn);
        $client = (string) ($interp['client'] ?? '');
        $reason = (string) ($interp['reason'] ?? 'CC cargo a cliente confirmado.');

        return [
            'kind' => (string) ($interp['kind'] ?? 'cc_cargo_cliente'),
            'client' => $client !== '' ? $client : null,
            'flags' => array_values(array_unique(array_merge(
                $interp['flags'] ?? ['cliente_cintas_confirmado'],
                ['cc_movimiento', 'valor_historico_corregido_por_interpretacion'],
            ))),
            'cc_charge' => $ccIn,
            'cc_payment' => 0.0,
            'finance_income' => 0.0,
            'finance_expense' => 0.0,
            'excel_cc_in' => $excelCcIn,
            'excel_cc_out' => (float) ($amounts['cc_out'] ?? 0),
            'corrections' => [],
            'components' => [
                'TIPO' => 'CC IN CLIENTE',
                'CLIENTE' => $client,
                'CC_IN' => $ccIn,
            ],
            'notes' => [
                'Cargo CC a cliente confirmado (no cuenta financiera).',
                $reason,
                'No crear cuenta financiera a partir de SubCuenta cliente.',
            ],
            'would_generate' => [
                'CC cargo (cliente debe más) '.$ccIn.($client !== '' ? ' → '.$client : ''),
                'NO crear cuenta financiera "'.($client !== '' ? $client : 'SubCuenta').'"',
            ],
            'reason' => $reason,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function matchCardPaymentAccountOverride(?int $sourceRow, ?string $concepto): ?array
    {
        // Solo por fila exacta: varios "Pago VISA" comparten concepto.
        return $this->matchByRowConcept(
            config('historical_sale_corrections.confirmed_card_payment_account_overrides', []),
            $sourceRow,
            $concepto,
            requireSourceRow: true,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function matchCardPaymentAmountUnknown(?int $sourceRow, ?string $concepto): ?array
    {
        // Solo por fila exacta: no mezclar con otros pagos VISA.
        return $this->matchByRowConcept(
            config('historical_sale_corrections.confirmed_card_payment_amount_unknown', []),
            $sourceRow,
            $concepto,
            requireSourceRow: true,
        );
    }

    /**
     * Match confirmado: preferir fila Excel exacta.
     * Fallback por concepto SOLO si la regla no declara source_row (evitar colisiones
     * tipo "DAASA Cable" vs "DAASA Cable de red").
     *
     * @param  list<array<string, mixed>>  $rules
     * @return array<string, mixed>|null
     */
    private function matchByRowConcept(
        array $rules,
        ?int $sourceRow,
        ?string $concepto,
        bool $requireSourceRow = true,
    ): ?array {
        $byConcept = null;
        foreach ($rules as $rule) {
            $needle = (string) ($rule['concepto_contains'] ?? '');
            $conceptoOk = $needle === '' || ($concepto !== null && stripos($concepto, $needle) !== false);
            if (! $conceptoOk) {
                continue;
            }
            if ($sourceRow !== null && (int) ($rule['source_row'] ?? 0) === $sourceRow) {
                return $rule;
            }
            if ($requireSourceRow) {
                continue;
            }
            if (! isset($rule['source_row']) && $needle !== '' && $conceptoOk && $byConcept === null) {
                $byConcept = $rule;
            }
        }

        return $requireSourceRow ? null : $byConcept;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function matchConfirmedCorrection(?int $sourceRow, ?string $concepto, float $venta): ?array
    {
        foreach (config('historical_sale_corrections.confirmed_sale_corrections', []) as $rule) {
            if (isset($rule['venta']) && abs((float) $rule['venta'] - $venta) > 1.0) {
                continue;
            }
            $needle = (string) ($rule['concepto_contains'] ?? '');
            $conceptoOk = $needle === '' || ($concepto !== null && stripos($concepto, $needle) !== false);
            if (! $conceptoOk) {
                continue;
            }
            // Prefer exact Excel row; allow concepto+venta fingerprint if row differs (tests / sheet shifts).
            if ($sourceRow !== null && (int) ($rule['source_row'] ?? 0) === $sourceRow) {
                return $rule;
            }
            if ($needle !== '' && $conceptoOk) {
                return $rule;
            }
        }

        return null;
    }

    private function ccLooksLikeMarginNotVenta(float $ccIn, float $venta, float $expectedUtilidad, float $utilidadExcel): bool
    {
        if ($ccIn <= 0 || $venta <= 0 || abs($ccIn - $venta) < 1.0) {
            return false;
        }
        $margin = $utilidadExcel > 0 ? $utilidadExcel : $expectedUtilidad;

        return $margin > 0 && abs($ccIn - $margin) < 1.0;
    }

    private function looksLikeClientName(string $subcuenta, string $cuenta): bool
    {
        if (in_array($cuenta, ['CC', 'Ventas', 'Abonos'], true)) {
            $financial = config('historical_import.financial_aliases', []);

            return $subcuenta !== '' && ! isset($financial[$subcuenta]);
        }

        return false;
    }
}
