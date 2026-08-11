<?php

/**
 * Cierre autorizado Etapa 11E — decisiones explícitas del usuario.
 * No reinterpretar; solo aplicar estas filas/reconstrucciones.
 */
return [
    'authorization_token' => env('HISTORICAL_IMPORT_AUTHORIZATION', 'ETAPA-11E-CIERRE-AUTORIZADO'),
    'authorization_label' => 'Usuario autorizó importación histórica a staging (Etapa 11E) solo si pasa el gate.',

    /**
     * Completar placeholders existentes (NO duplicar).
     * source_row Excel => datos autorizados.
     */
    'placeholder_completions' => [
        590 => [
            'concepto_hint' => 'Youtube',
            'date' => '2026-05-31',
            'amount' => 6799.0,
            'category' => 'Servicios',
            'account' => 'MC',
            'origen' => 'completar_pendiente_autorizado_usuario',
            'regla' => 'importe_historico_constante',
        ],
        592 => [
            'concepto_hint' => 'Mercantil Andina',
            'date' => '2026-05-31',
            'amount' => 96684.84,
            'category' => 'Seguros',
            'account' => 'MC',
            'origen' => 'completar_pendiente_autorizado_usuario',
            'regla' => 'importe_historico_maximo',
        ],
        717 => [
            'concepto_hint' => 'Mercantil Andina',
            'date' => '2026-06-30',
            'amount' => 96684.84,
            'category' => 'Seguros',
            'account' => 'MC',
            'origen' => 'completar_pendiente_autorizado_usuario',
            'regla' => 'importe_historico_maximo',
        ],
        718 => [
            'concepto_hint' => 'Meli',
            'date' => '2026-06-30',
            'amount' => 3490.0,
            'category' => 'Servicios',
            'account' => 'MC',
            'origen' => 'completar_pendiente_autorizado_usuario',
            'regla' => 'importe_historico_constante',
        ],
        719 => [
            'concepto_hint' => 'YouTube',
            'date' => '2026-06-30',
            'amount' => 6799.0,
            'category' => 'Servicios',
            'account' => 'MC',
            'origen' => 'completar_pendiente_autorizado_usuario',
            'regla' => 'importe_historico_constante',
        ],
        721 => [
            'concepto_hint' => 'Spotify',
            'date' => '2026-06-30',
            'amount' => 4981.49,
            'category' => 'Servicios',
            'account' => 'MC',
            'origen' => 'completar_pendiente_autorizado_usuario',
            'regla' => 'importe_historico_constante',
        ],
        817 => [
            'concepto_hint' => 'Falta el seguro del auto',
            'date' => '2026-07-31',
            'amount' => 96684.84,
            'category' => 'Seguros',
            'account' => 'MC',
            'origen' => 'completar_pendiente_autorizado_usuario',
            'regla' => 'importe_historico_maximo',
        ],
    ],

    /**
     * Placeholders redundantes → EXCLUIR (conservar trazabilidad).
     */
    'redundant_placeholder_exclusions' => [
        589 => [
            'concepto_hint' => 'Spotify',
            'reason' => 'placeholder_redundante_movimiento_original_existente',
        ],
        591 => [
            'concepto_hint' => 'Meli',
            'reason' => 'placeholder_redundante_movimiento_original_existente',
        ],
    ],

    /**
     * Pendientes que NO bloquean import y NO se importan.
     */
    'non_importable_pendings' => [
        478 => [
            'concepto_hint' => 'Pago VISA',
            'root_cause' => 'importe_pago_tarjeta_desconocido',
            'decision_required' => false,
            'import_ready' => false,
            'note' => 'NO inventar importe; NO importar 0.',
        ],
        588 => [
            'concepto_hint' => 'AUSA',
            'root_cause' => 'pendiente_completar',
            'decision_required' => false,
            'import_ready' => false,
            'note' => 'No recurrente; insuficiente; NO inferir; NO importar 0.',
        ],
    ],

    /**
     * 17 reconstrucciones históricas aprobadas (último día del mes).
     * Antes de crear: verificar no exista equivalente del mes.
     */
    'authorized_reconstructions' => [
        ['service' => 'youtube', 'label' => 'YouTube', 'ym' => '2026-01', 'amount' => 6799.0, 'account' => 'MC', 'category' => 'Servicios'],
        ['service' => 'youtube', 'label' => 'YouTube', 'ym' => '2026-03', 'amount' => 6799.0, 'account' => 'MC', 'category' => 'Servicios'],
        ['service' => 'youtube', 'label' => 'YouTube', 'ym' => '2026-07', 'amount' => 6799.0, 'account' => 'MC', 'category' => 'Servicios'],
        ['service' => 'spotify', 'label' => 'Spotify', 'ym' => '2026-01', 'amount' => 4981.49, 'account' => 'MC', 'category' => 'Servicios'],
        ['service' => 'spotify', 'label' => 'Spotify', 'ym' => '2026-03', 'amount' => 4981.49, 'account' => 'MC', 'category' => 'Servicios'],
        ['service' => 'spotify', 'label' => 'Spotify', 'ym' => '2026-07', 'amount' => 4981.49, 'account' => 'MC', 'category' => 'Servicios'],
        ['service' => 'mubi', 'label' => 'MUBI', 'ym' => '2026-01', 'amount' => 9999.0, 'account' => 'VISA', 'category' => 'Servicios'],
        ['service' => 'mubi', 'label' => 'MUBI', 'ym' => '2026-02', 'amount' => 9999.0, 'account' => 'VISA', 'category' => 'Servicios'],
        ['service' => 'mubi', 'label' => 'MUBI', 'ym' => '2026-03', 'amount' => 9999.0, 'account' => 'VISA', 'category' => 'Servicios'],
        ['service' => 'mubi', 'label' => 'MUBI', 'ym' => '2026-05', 'amount' => 9999.0, 'account' => 'VISA', 'category' => 'Servicios'],
        ['service' => 'meli', 'label' => 'Meli', 'ym' => '2026-01', 'amount' => 3490.0, 'account' => 'MC', 'category' => 'Servicios'],
        ['service' => 'meli', 'label' => 'Meli', 'ym' => '2026-02', 'amount' => 3490.0, 'account' => 'MC', 'category' => 'Servicios'],
        ['service' => 'pedidos_ya_premium', 'label' => 'Pedidos Ya! Premium', 'ym' => '2026-01', 'amount' => 2999.0, 'account' => 'MC', 'category' => 'Servicios'],
        ['service' => 'pedidos_ya_premium', 'label' => 'Pedidos Ya! Premium', 'ym' => '2026-02', 'amount' => 2999.0, 'account' => 'MC', 'category' => 'Servicios'],
        ['service' => 'pedidos_ya_premium', 'label' => 'Pedidos Ya! Premium', 'ym' => '2026-05', 'amount' => 2999.0, 'account' => 'MC', 'category' => 'Servicios'],
        ['service' => 'pedidos_ya_premium', 'label' => 'Pedidos Ya! Premium', 'ym' => '2026-06', 'amount' => 2999.0, 'account' => 'MC', 'category' => 'Servicios'],
        ['service' => 'pedidos_ya_premium', 'label' => 'Pedidos Ya! Premium', 'ym' => '2026-07', 'amount' => 2999.0, 'account' => 'MC', 'category' => 'Servicios'],
    ],

    'reconstruction_origen' => 'reconstruccion_historica_aprobada_usuario',
    'reconstruction_source_row_base' => 910000,
];
