<?php

return [
    'cutover_date' => env('HISTORICAL_CUTOVER_DATE', '2026-08-15'),
    'period_from' => env('HISTORICAL_PERIOD_FROM', '2026-01-01'),
    'period_to' => env('HISTORICAL_PERIOD_TO', '2026-08-14'),
    'movements_sheet' => 'Movimientos',
    'movements_header_row' => 3, // 1-based row with FECHA/CONCEPTO/Cuenta/SubCuenta
    'movements_data_start_row' => 4,

    'catalog_supplier_name' => 'INVID',

    'account_holders' => [
        ['code' => 'fernando', 'name' => 'Fernando'],
        ['code' => 'gabi', 'name' => 'Gabi'],
    ],

    /**
     * Excel SubCuenta (medio financiero) → cuenta AR Sistemas.
     * Cuenta (columna C) = categoría económica.
     */
    'financial_aliases' => [
        'MP Fer' => ['name' => 'Mercado Pago Fernando', 'type' => 'wallet', 'currency' => 'ARS', 'holder' => 'fernando', 'alias' => 'MP Fer'],
        'MP Gabi' => ['name' => 'Mercado Pago Gabi', 'type' => 'wallet', 'currency' => 'ARS', 'holder' => 'gabi', 'alias' => 'MP Gabi'],
        'FT' => ['name' => 'Efectivo ARS Fernando', 'type' => 'cash', 'currency' => 'ARS', 'holder' => 'fernando', 'alias' => 'FT'],
        'Dolar FT' => ['name' => 'Efectivo USD Fernando', 'type' => 'cash', 'currency' => 'USD', 'holder' => 'fernando', 'alias' => 'Dolar FT'],
        'Dolar MP' => ['name' => 'Mercado Pago USD', 'type' => 'wallet', 'currency' => 'USD', 'holder' => 'fernando', 'alias' => 'Dolar MP'],
        'DNI Gabi' => ['name' => 'Cuenta DNI Banco Provincia Gabi', 'type' => 'bank', 'currency' => 'ARS', 'holder' => 'gabi', 'alias' => 'DNI Gabi'],
        'CuentaDNI Gabi' => ['name' => 'Cuenta DNI Banco Provincia Gabi', 'type' => 'bank', 'currency' => 'ARS', 'holder' => 'gabi', 'alias' => 'DNI Gabi'],
        'Ciudad' => ['name' => 'Banco Ciudad Gabi', 'type' => 'bank', 'currency' => 'ARS', 'holder' => 'gabi', 'alias' => 'Ciudad'],
        'Brubank Gabi' => ['name' => 'Brubank Gabi', 'type' => 'bank', 'currency' => 'ARS', 'holder' => 'gabi', 'alias' => 'Brubank Gabi'],
        'Patagonia' => ['name' => 'Banco Patagonia Fernando', 'type' => 'bank', 'currency' => 'ARS', 'holder' => 'fernando', 'alias' => 'Patagonia'],
        'VISA' => ['name' => 'VISA Fernando', 'type' => 'credit_card', 'currency' => 'ARS', 'holder' => 'fernando', 'alias' => 'VISA', 'liability' => true],
        'MC' => ['name' => 'Mastercard Fernando', 'type' => 'credit_card', 'currency' => 'ARS', 'holder' => 'fernando', 'alias' => 'MC', 'liability' => true],
        'MCMP' => ['name' => 'Mastercard Mercado Pago Fernando', 'type' => 'credit_card', 'currency' => 'ARS', 'holder' => 'fernando', 'alias' => 'MCMP', 'liability' => true],
    ],

    'category_defaults' => [
        'Abonos' => ['default_scope' => 'professional', 'direction' => 'income'],
        'Ventas' => ['default_scope' => 'professional', 'direction' => 'income'],
        'Instalaciones' => ['default_scope' => 'professional', 'direction' => 'income'],
        'Reparaciones' => ['default_scope' => 'professional', 'direction' => 'income'],
        'Remotos' => ['default_scope' => 'professional', 'direction' => 'income'],
        'Mercaderias' => ['default_scope' => 'professional', 'direction' => 'expense'],
        'Servicios' => ['default_scope' => 'both', 'direction' => 'expense'],
        'Impuestos' => ['default_scope' => 'both', 'direction' => 'expense'],
        'Alquileres' => ['default_scope' => 'professional', 'direction' => 'expense'],
        'Comidas' => ['default_scope' => 'personal', 'direction' => 'expense', 'ambiguous_scope' => true],
        'Super' => ['default_scope' => 'personal', 'direction' => 'expense'],
        'Salud' => ['default_scope' => 'personal', 'direction' => 'expense'],
        'Viaticos' => ['default_scope' => 'both', 'direction' => 'expense', 'ambiguous_scope' => true],
        'Mascotas' => ['default_scope' => 'personal', 'direction' => 'expense'],
        'Auto' => ['default_scope' => 'personal', 'direction' => 'expense'],
        'Gastos Fer' => ['default_scope' => 'personal', 'direction' => 'expense'],
        'Gastos Gabi' => ['default_scope' => 'personal', 'direction' => 'expense'],
        'Seguros' => ['default_scope' => 'both', 'direction' => 'expense'],
        'Sueldos' => ['default_scope' => 'professional', 'direction' => 'expense'],
        'Intereses ganados' => ['default_scope' => 'both', 'direction' => 'income'],
        'MyU' => ['default_scope' => 'personal', 'direction' => 'expense'],
        'Miranda' => ['default_scope' => 'personal', 'direction' => 'expense'],
        'CC' => ['default_scope' => 'professional', 'direction' => 'cc'],
    ],

    'relevant_suppliers' => [
        'INVID', 'Telecentro', 'Personal', 'Tuenti', 'EDENOR', 'LatinCloud',
        'Fibertel', 'Movistar', 'Claro', 'AFIP', 'ARCA',
    ],

    'occasional_commerce_patterns' => [
        '/super/i', '/carrefour/i', '/disco/i', '/coto/i', '/dia\b/i',
        '/pedidos?\s*ya/i', '/rappi/i', '/mp\s*delivery/i', '/kiosco/i',
        '/farmacity/i', '/jumbo/i', '/vea\b/i',
    ],

    'client_known_aliases' => [
        'daasa' => 'DAASA',
        'daasa server' => 'DAASA',
        'lidercar' => 'Lidercar',
        'nuts' => 'Nuts',
        'kaisha' => 'Kaisha',
        'marinkovic' => 'Marinkovic',
        'fba' => 'FBA',
        'guillermo' => 'Guillermo',
    ],
];
