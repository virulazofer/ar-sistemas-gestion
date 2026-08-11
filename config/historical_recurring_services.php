<?php

return [
    /**
     * Servicios recurrentes mensuales confirmados por el usuario (preview histórico).
     * AUSA NO es recurrente: peaje por uso; no proponer faltantes.
     */
    'services' => [
        'youtube' => [
            'label' => 'YouTube',
            'aliases' => ['youtube', 'youtube premium', 'youtube+'],
            'account_hint' => 'MC',
            'category_hint' => 'Servicios',
            'fixed_amount' => null,
            'user_confirmed_recurring' => true,
            'propose_missing' => true,
        ],
        'spotify' => [
            'label' => 'Spotify',
            'aliases' => ['spotify'],
            'account_hint' => 'MC',
            'category_hint' => 'Servicios',
            'fixed_amount' => null,
            'user_confirmed_recurring' => true,
            'propose_missing' => true,
        ],
        'mubi' => [
            'label' => 'MUBI',
            'aliases' => ['mubi'],
            'account_hint' => 'VISA',
            'category_hint' => 'Servicios',
            'fixed_amount' => null,
            'user_confirmed_recurring' => true,
            'propose_missing' => true,
        ],
        'meli' => [
            'label' => 'Meli / Mercado Libre (suscripción)',
            'aliases' => ['meli', 'mercado libre', 'ml'],
            'account_hint' => 'MC',
            'category_hint' => 'Servicios',
            'fixed_amount' => null,
            'require_service_context' => true,
            'user_confirmed_recurring' => true,
            'propose_missing' => true,
        ],
        'mercantil_andina' => [
            'label' => 'Mercantil Andina',
            'aliases' => ['mercantil andina', 'falta el seguro del auto', 'seguro del auto'],
            'alias_concepts' => [
                'falta el seguro del auto' => 'Usuario: “Falta el seguro del auto” = Mercantil Andina',
            ],
            'account_hint' => 'MC',
            'category_hint' => 'Seguros',
            'fixed_amount' => null,
            'amount_rule_override' => 'importe_historico_maximo',
            'user_confirmed_recurring' => true,
            'propose_missing' => true,
            'auto_create' => false,
        ],
        'pedidos_ya_premium' => [
            'label' => 'Pedidos Ya! Premium',
            'aliases' => [
                'pedidos ya! premium',
                'pedidosya premium',
                'pedidos ya premium',
                'pedidos ya plus',
                'pedidosya plus',
            ],
            'account_hint' => 'MC',
            'category_hint' => 'Servicios',
            'fixed_amount' => 2999.0,
            'fixed_amount_reason' => 'recurrente mensual confirmado por usuario (ARS 2999 / MC)',
            'frequency' => 'monthly',
            'exclude_food_orders' => true,
            'user_confirmed_recurring' => true,
            'propose_missing' => true,
        ],
    ],

    /**
     * Tracked for existing rows only — NEVER propose missing months.
     */
    'non_recurring_tracked' => [
        'ausa' => [
            'label' => 'AUSA (peaje por uso)',
            'note' => 'No es suscripción mensual. Conservar movimientos reales; no inventar meses faltantes. Fila 588 pendiente no se completa por recurrencia.',
        ],
    ],

    'combo_covers' => [
        'youtube_spotify' => ['youtube', 'spotify'],
    ],
];
