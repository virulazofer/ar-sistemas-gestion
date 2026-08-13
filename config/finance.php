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

    'argentinadatos' => [
        'base_url' => env('ARGENTINADATOS_BASE_URL', 'https://api.argentinadatos.com/v1'),
        'oficial_path' => env('ARGENTINADATOS_OFICIAL_PATH', '/cotizaciones/dolares/oficial'),
        'timeout' => (int) env('ARGENTINADATOS_TIMEOUT', 30),
    ],

    'money_decimals' => 2,
    'rate_decimals' => 6,

    'account_types' => [
        'cash' => 'Efectivo',
        'bank' => 'Banco',
        'wallet' => 'Billetera',
        'credit_card' => 'Tarjeta de crédito',
        'other' => 'Otra',
    ],

    'scopes' => [
        'personal' => 'Personal',
        'professional' => 'Profesional',
        'mixed' => 'Mixto',
        'financial' => 'Financiero',
    ],

    /*
    |--------------------------------------------------------------------------
    | Plan de cuentas — ubicación contable de cuentas financieras
    |--------------------------------------------------------------------------
    */
    'financial_account_chart_codes' => [
        'cash' => '1.1.1',
        'bank' => '1.1.2',
        'wallet' => '1.1.3',
        'credit_card' => '2.1',
        'other' => '1.1.1',
    ],


    'movement_types' => [
        'income' => \App\Support\UiLabels::get('income', 'Ingresos'),
        'expense' => \App\Support\UiLabels::get('expense', 'Egresos'),
        'transfer_out' => \App\Support\UiLabels::get('transfer_out', 'Transferencia salida'),
        'transfer_in' => \App\Support\UiLabels::get('transfer_in', 'Transferencia entrada'),
    ],
];
