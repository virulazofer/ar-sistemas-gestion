<?php

return [
    /**
     * Precedencia (mayor a menor):
     * 1. Override por fila (usuario)
     * 2. Cliente / contexto profesional inequívoco
     * 3. Regla específica por concepto
     * 4. Regla por categoría
     * 5. Sin evidencia → null (permanece ámbito dudoso)
     *
     * Nunca usar titular/cuenta financiera como determinante.
     */
    'known_clients' => [
        'lidercar', 'kaisha', 'nqn', 'daasa', 'nuts', 'serpa',
    ],

    'professional_context_patterns' => [
        '/\bcliente\b/u',
        '/visita\s*t[eé]cnica/u',
        '/\binstalaci[oó]n\b/u',
        '/\breparaci[oó]n\b/u',
        '/\btrabajo\b/u',
        '/evento\s*profesional/u',
        '/viaje\s*profesional/u',
        '/envio\s+de\s+equipo/u',
        '/env[ií]o\s+equipo/u',
        '/env[ií]o\s+pc\b/u',
        '/transporte\s+de\s+equipo/u',
    ],

    'peaje_estacionamiento_patterns' => [
        '/\bpeaje/u',
        '/\bausa\b/u',
        '/estacionamiento/u',
        '/\bblinkay\b/u',
        '/parking/u',
    ],

    'envio_equipo_patterns' => [
        '/env[ií]o\s+equipo/u',
        '/envio\s+de\s+equipo/u',
        '/env[ií]o\s+pc\b/u',
        '/transporte\s+.*equipo/u',
    ],

    /**
     * Decisiones explícitas por fila Excel (1-based).
     * Prevalecen sobre reglas reutilizables.
     */
    'row_overrides' => [
        358 => ['scope' => 'professional', 'reason' => 'Usuario: Taxi aeropuerto → PROFESIONAL (decisión explícita, no regla Ezeiza global)'],
        706 => ['scope' => 'professional', 'reason' => 'Usuario: Peajes Ezeiza → PROFESIONAL (decisión explícita)'],
        708 => ['scope' => 'professional', 'reason' => 'Usuario: Estacionamiento Ezeiza → PROFESIONAL (decisión explícita)'],
        439 => ['scope' => 'personal', 'reason' => 'Usuario: Desayuno Fer → PERSONAL'],
        517 => ['scope' => 'personal', 'reason' => 'Usuario: Almuerzo viernes → PERSONAL'],
        604 => ['scope' => 'professional', 'reason' => 'Usuario: Cena KFC → PROFESIONAL'],
        611 => ['scope' => 'professional', 'reason' => 'Usuario: Cena con León → PROFESIONAL'],
        440 => ['scope' => 'professional', 'reason' => 'Usuario: Jumbo (fila en Viáticos) → PROFESIONAL (solo esa fila)'],
        786 => ['scope' => 'personal', 'reason' => 'Usuario: Uber Nelly → PERSONAL (no regla global Uber)'],
        231 => ['scope' => 'professional', 'reason' => 'Usuario: Merienda y estacionamiento (mixto) → PROFESIONAL'],
        482 => ['scope' => 'professional', 'reason' => 'Usuario: Sausalito mixto → PROFESIONAL'],
        337 => ['scope' => 'personal', 'reason' => 'Usuario: Uber y Chacharramendi → PERSONAL (componente Chacharramendi; sin split de importes)'],
        385 => ['scope' => 'professional', 'reason' => 'Usuario: Confitería Lidercar → PROFESIONAL'],
        426 => ['scope' => 'professional', 'reason' => 'Usuario: Envío equipo NQN → PROFESIONAL'],
        632 => ['scope' => 'professional', 'reason' => 'Usuario: Envío PC a Kaisha → PROFESIONAL'],
    ],
];
