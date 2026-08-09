<?php

return [

    'default_base' => env('FINANCE_BASE_CURRENCY', 'ARS'),
    'default_quote' => env('FINANCE_QUOTE_CURRENCY', 'USD'),

    'dolarapi' => [
        'base_url' => env('DOLARAPI_BASE_URL', 'https://dolarapi.com/v1'),
        'official_path' => env('DOLARAPI_OFFICIAL_PATH', '/dolares/oficial'),
        'timeout' => (int) env('DOLARAPI_TIMEOUT', 8),
        // Venta oficial = fuente operativa del sistema; compra se almacena en histórico.
        'preferred_field' => env('DOLARAPI_PREFERRED_FIELD', 'venta'),
        'buy_field' => env('DOLARAPI_BUY_FIELD', 'compra'),
    ],

    'money_decimals' => 2,
    'rate_decimals' => 6,

    'account_types' => [
        'cash' => 'Efectivo',
        'bank' => 'Banco',
        'wallet' => 'Billetera',
        'other' => 'Otra',
    ],

    'scopes' => [
        'personal' => 'Personal',
        'professional' => 'Profesional',
    ],

    'movement_types' => [
        'income' => 'Ingreso',
        'expense' => 'Gasto',
        'transfer_out' => 'Transferencia salida',
        'transfer_in' => 'Transferencia entrada',
    ],
];
