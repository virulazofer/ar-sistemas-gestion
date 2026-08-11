<?php

return [
    /**
     * Fechas vacías al final del bloque mensual → último día del mes del bloque.
     * Confirmadas explícitamente por el usuario (fila Excel 1-based).
     */
    'month_end_closure_confirmed_rows' => [
        124 => ['iso' => '2026-01-31', 'month' => 'enero', 'concepto' => 'Mercantil Andina'],
        345 => ['iso' => '2026-03-31', 'month' => 'marzo', 'concepto' => 'Mercantil Andina'],
        460 => ['iso' => '2026-04-30', 'month' => 'abril', 'concepto' => 'Youtube'],
        461 => ['iso' => '2026-04-30', 'month' => 'abril', 'concepto' => 'Meli'],
        462 => ['iso' => '2026-04-30', 'month' => 'abril', 'concepto' => 'Mercantil Andina'],
        720 => ['iso' => '2026-06-30', 'month' => 'junio', 'concepto' => 'Mubi'],
        818 => ['iso' => '2026-07-31', 'month' => 'julio', 'concepto' => 'peajes'],
    ],

    'month_end_closure_rule' => 'fecha_inferida_por_cierre_mensual',
    'month_end_closure_label' => 'fecha inferida por cierre mensual',

    'month_name_to_number' => [
        'enero' => 1,
        'febrero' => 2,
        'marzo' => 3,
        'abril' => 4,
        'mayo' => 5,
        'junio' => 6,
        'julio' => 7,
        'agosto' => 8,
        'septiembre' => 9,
        'octubre' => 10,
        'noviembre' => 11,
        'diciembre' => 12,
    ],
];
