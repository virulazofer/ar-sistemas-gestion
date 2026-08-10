<?php

namespace App\Support;

/**
 * Semántica visual de importes (11F-1).
 *
 * ROJO = requiere atención · VERDE = favorable · NEUTRO = neutro.
 * No asumir siempre “positivo=verde / negativo=rojo”.
 */
final class UiSemantics
{
    /** Resultados económicos/financieros: + favorable, − atención, 0 neutro. */
    public const MODE_RESULT = 'result';

    /**
     * CC clientes (perspectiva negocio / a cobrar):
     * saldo > 0 (nos deben) → atención · = 0 → neutro · < 0 (a favor) → favorable.
     * El importe debe venir ya en convención de presentación (+ = deuda a cobrar).
     */
    public const MODE_CLIENT_CC = 'client_cc';

    public const TONE_ATTENTION = 'attention';

    public const TONE_FAVORABLE = 'favorable';

    public const TONE_NEUTRAL = 'neutral';

    public static function tone(string $amount, string $mode = self::MODE_RESULT): string
    {
        $normalized = Money::normalize($amount);

        if (Money::isZero($normalized)) {
            return self::TONE_NEUTRAL;
        }

        $positive = Money::isPositive($normalized);

        return match ($mode) {
            self::MODE_CLIENT_CC => $positive ? self::TONE_ATTENTION : self::TONE_FAVORABLE,
            default => $positive ? self::TONE_FAVORABLE : self::TONE_ATTENTION,
        };
    }

    /**
     * Clases CSS semánticas centralizadas (semantic-amount--*).
     */
    public static function cssClass(string $amount, string $mode = self::MODE_RESULT): string
    {
        return 'semantic-amount semantic-amount--'.self::tone($amount, $mode);
    }

    /**
     * Alias KPI del dashboard de gestión (mismos colores, nombres legacy).
     */
    public static function kpiClass(string $amount, string $mode = self::MODE_RESULT): string
    {
        return match (self::tone($amount, $mode)) {
            self::TONE_ATTENTION => 'ar-kpi-negative',
            self::TONE_FAVORABLE => 'ar-kpi-positive',
            default => 'ar-kpi-zero',
        };
    }

    /**
     * Convierte saldo ledger (perspectiva cliente: − = deuda) a saldo CC de presentación
     * (+ = nos deben / a cobrar). No altera DB.
     */
    public static function clientCcDisplayBalance(string $signedLedgerBalance): string
    {
        return Money::mul(Money::normalize($signedLedgerBalance), '-1');
    }
}
