<?php

/**
 * Correcciones confirmadas por el usuario sobre filas históricas.
 * La interpretación corregida tiene prioridad sobre valores inconsistentes del Excel,
 * conservando siempre el original para auditoría.
 */
return [
    /**
     * Match por fila Excel 1-based + fingerprint de venta (archivo GASTOS MENSUALES 2026).
     * source_row = número de fila REAL de la hoja Movimientos.
     */
    'confirmed_sale_corrections' => [
        [
            'source_row' => 533,
            'concepto_contains' => 'Lidercar - Reparacion PC - SSD',
            'venta' => 130000.0,
            'interpret' => [
                'cc_in' => 130000.0,
                'cc_out' => 130000.0,
                'finance_income' => 0.0,
                'sale_kind' => 'credito_luego_cancelado',
                'reason' => 'Confirmado usuario: idem fila 388. Excel cargó utilidad (110000) en CC; deuda real = venta 130000.',
            ],
        ],
        [
            'source_row' => 534,
            'concepto_contains' => 'Lidercar - Reparacion PC - Fernando Fuente',
            'venta' => 60000.0,
            'interpret' => [
                'cc_in' => 60000.0,
                'cc_out' => 0.0,
                'finance_income' => 0.0,
                'sale_kind' => 'credito_abierto',
                'reason' => 'Patrón CC=utilidad (40000=Venta-Merca). Interpretar deuda = venta 60000; sin evidencia de cancelación.',
                'needs_user_ack' => false,
            ],
        ],
    ],

    /**
     * Saldos / ajustes de apertura de cuenta corriente confirmados por el usuario.
     * Establecen deuda inicial del cliente; NO son venta, cobro, ingreso ni caja.
     * Fingerprint: fila Excel + concepto + CC IN (no inventar importe).
     */
    'confirmed_opening_cc_balances' => [
        [
            'source_row' => 5,
            'concepto_contains' => 'DAASA CC Inicial',
            'client' => 'DAASA',
            'cc_in' => 50000.0,
            'interpret' => [
                'kind' => 'saldo_apertura_cc',
                'cc_in' => 50000.0,
                'cc_out' => 0.0,
                'finance_income' => 0.0,
                'finance_expense' => 0.0,
                'is_opening_adjustment' => true,
                'reason' => 'Confirmado usuario: deuda que DAASA mantenía al comienzo del período. Saldo/ajuste de apertura de CC (CC IN inicial). No es venta 2026, ingreso financiero, cobro ni movimiento de caja/banco. Excel CC OUT en la misma fila no se interpreta como cobro.',
            ],
        ],
    ],

    /**
     * Ajuste / saldo de apertura de mercadería (no compra 2026, no venta, no caja/banco, no stock físico).
     * No crear cuenta financiera "Saldo Inicial".
     */
    'confirmed_opening_merca_balances' => [
        [
            'source_row' => 15,
            'concepto_contains' => 'Saldo de mercadería 2025',
            'interpret' => [
                'kind' => 'saldo_apertura_mercaderia',
                'flags' => [
                    'cc_apertura_mercaderia_confirmada',
                    'confirmed_opening_merca_balance',
                    'valor_historico_corregido_por_interpretacion',
                ],
                'reason' => 'Confirmado usuario: ajuste/saldo de apertura de mercadería 2025. No es compra 2026, venta, movimiento de caja/banco ni stock físico. No crear cuenta "Saldo Inicial".',
            ],
        ],
    ],

    /**
     * Ventas con cobro/CC confirmados explícitamente (Excel omitió CC o cobro).
     * Conserva Venta/Merca/Utilidad económicas; cobro financiero separado si está documentado/confirmado.
     */
    'confirmed_sale_resolutions' => [
        [
            'source_row' => 177,
            'concepto_contains' => 'Cintas Industriales - Actualización Bibi',
            'venta' => 560000.0,
            'interpret' => [
                'client' => 'Cintas',
                'cc_in' => 560000.0,
                'cc_out' => 0.0,
                'finance_income' => 0.0,
                'finance_account_alias' => null,
                'sale_kind' => 'credito_abierto',
                'flags' => [
                    'cc_in_inferido_cintas',
                    'cliente_cintas_confirmado',
                    'valor_historico_corregido_por_interpretacion',
                ],
                'reason' => 'Confirmado usuario: venta 560000 a CC cliente Cintas. CC IN omitido en Excel; no inventar cobro financiero. Pagos posteriores = CC OUT.',
            ],
        ],
        [
            'source_row' => 236,
            'concepto_contains' => 'DAASA gabinete',
            'venta' => 133000.0,
            'interpret' => [
                'client' => 'DAASA',
                'cc_in' => 0.0,
                'cc_out' => 0.0,
                'finance_income' => 133000.0,
                'finance_account_alias' => 'Patagonia',
                'sale_kind' => 'contado_documentado',
                'check_duplicate_income' => true,
                'flags' => [
                    'cobro_confirmado_patagonia',
                    'valor_historico_corregido_por_interpretacion',
                ],
                'reason' => 'Confirmado usuario: venta 133000; cobro 133000 Banco Patagonia / Fernando. Utilidad ≠ cobro. Mantener Venta/Merca/Utilidad económicas; cobro = flujo financiero separado.',
            ],
        ],
        [
            'source_row' => 254,
            'concepto_contains' => 'Xiaomi reloj Grimbo',
            'venta' => 78000.0,
            'interpret' => [
                'client' => null,
                'cc_in' => 0.0,
                'cc_out' => 0.0,
                'finance_income' => 78000.0,
                'finance_account_alias' => 'FT',
                'sale_kind' => 'contado_documentado',
                'check_duplicate_income' => true,
                'flags' => [
                    'cobro_confirmado_ft',
                    'valor_historico_corregido_por_interpretacion',
                ],
                'reason' => 'Confirmado usuario: venta 78000; cobro 78000 en FT / Efectivo ARS Fernando. Venta/costo/utilidad separados del flujo financiero. SubCuenta Excel (MP Fer) no prevalece sobre cobro confirmado.',
            ],
        ],
    ],

    /**
     * Cancelaciones / cobros de CC confirmados (sin venta nueva en la fila).
     */
    'confirmed_cc_settlements' => [
        [
            'source_row' => 466,
            'concepto_contains' => 'Hugo Ferreyra',
            'interpret' => [
                'kind' => 'cc_cancelacion_con_cobro',
                'client' => 'DAASA',
                'preserve_concepto' => true,
                'cc_out' => 1308450.0,
                'finance_income' => 1308450.0,
                'finance_account_alias' => 'Patagonia',
                'check_duplicate_income' => true,
                'flags' => [
                    'cobro_confirmado_patagonia',
                    'cc_cancelacion_daasa_confirmada',
                    'valor_historico_corregido_por_interpretacion',
                ],
                'reason' => 'Confirmado usuario: concepto original Hugo Ferreyra conservado. Interpretación: venta facturada a DAASA; DAASA canceló; cobro Banco Patagonia. CC OUT 1308450 + cobro 1308450. Si existe ingreso Excel equivalente, no duplicar.',
            ],
        ],
        [
            'source_row' => 637,
            'concepto_contains' => 'DAASA Cable',
            'interpret' => [
                'kind' => 'cc_cancelacion_deuda',
                'client' => 'DAASA',
                'cc_out' => 464000.0,
                'economic_venta' => 0.0,
                'finance_income' => 464000.0,
                'finance_account_alias' => 'Patagonia',
                'link_account_if_unequivocal' => true,
                'check_duplicate_income' => true,
                'flags' => [
                    'cc_cancelacion_daasa_confirmada',
                    'cobro_confirmado_patagonia',
                    'valor_historico_corregido_por_interpretacion',
                ],
                'reason' => 'Confirmado usuario: cancelación deuda CC OUT 464000 cliente DAASA. NO nueva venta. Cuenta receptora Patagonia inequívoca en SubCuenta Excel; no inventar otro medio.',
            ],
        ],
    ],

    /**
     * CC IN a cliente confirmado (SubCuenta es cliente, no cuenta financiera).
     */
    'confirmed_client_cc_charges' => [
        [
            'source_row' => 536,
            'concepto_contains' => 'Cintas Industriales - Reparación MUCAD',
            'interpret' => [
                'kind' => 'cc_cargo_cliente',
                'client' => 'Cintas',
                'cc_in' => 200000.0,
                'flags' => [
                    'cliente_cintas_confirmado',
                    'valor_historico_corregido_por_interpretacion',
                ],
                'reason' => 'Confirmado usuario: "Cintas" = CLIENTE, no cuenta financiera. CC IN 200000. No crear cuenta financiera "Cintas".',
            ],
        ],
    ],

    /**
     * Pago de resumen tarjeta: cuenta pagadora confirmada (SubCuenta Excel = tarjeta).
     */
    'confirmed_card_payment_account_overrides' => [
        [
            'source_row' => 131,
            'concepto_contains' => 'Pago VISA',
            'payment_account_alias' => 'Patagonia',
            'flags' => ['pago_tarjeta_cuenta_patagonia'],
            'reason' => 'Confirmado usuario: pago de resumen VISA desde Banco Patagonia / Fernando. ↓ Patagonia, ↓ pasivo tarjeta. NO gasto nuevo.',
        ],
    ],

    /**
     * Pago de resumen con semántica confirmada pero importe desconocido → PENDIENTE (no amarillo).
     * No inventar/inferir/promediar/copiar/excluir importe.
     */
    'confirmed_card_payment_amount_unknown' => [
        [
            'source_row' => 478,
            'concepto_contains' => 'Pago VISA',
            'flags' => [
                'importe_pago_tarjeta_desconocido',
                'pago_resumen_tarjeta_confirmado',
            ],
            'reason' => 'Confirmado usuario: naturaleza = pago de resumen. Importe desconocido. Conservar fecha/datos; decision_required=false; import_ready=false; pending_complete.',
        ],
    ],

    /**
     * Detección automática: CC IN ≈ utilidad (o Venta−Merca) pero ≠ Venta.
     * Se aplica si no hay override confirmado.
     */
    'auto_cc_equals_margin_not_venta' => true,

    /**
     * Reintegros / recupero de gasto personal (no ingreso profesional).
     */
    'personal_recoveries' => [
        [
            'concepto_regex' => '/santi\s+aporta\s+almuerzo/iu',
            'kind' => 'reintegro_gasto_personal',
            'category' => 'Comidas',
            'note' => 'Santi (hijo): aporte/reintegro por comida compartida. Reduce gasto neto Comidas. No es ingreso profesional/venta/CC.',
        ],
    ],
];
