<?php

return [
    /**
     * Filas Excel (1-based, hoja Movimientos) excluidas del preview por decisión del usuario.
     * No se importan; quedan con status excluded y trazabilidad.
     */
    'user_excluded_source_rows' => [
        819 => [
            'reason' => 'Excluida por decisión del usuario (fila vacía Super/MP Gabi importe 0).',
            'concepto_hint' => '',
            'cuenta_hint' => 'Super',
            'subcuenta_hint' => 'MP Gabi',
        ],
    ],
];
