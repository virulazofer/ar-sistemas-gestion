<?php

namespace App\Support;

use App\Enums\ChartAccountType;

/**
 * Semántica visual de importes (11F-1 / 11F rebuild).
 *
 * ROJO = requiere atención · VERDE = favorable · NEUTRO = neutro.
 * No asumir siempre “positivo=verde / negativo=rojo”.
 * Egresos cotidianos: neutro (no alarma).
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

    /** Importes de egreso/gasto normal: siempre neutros (no alarmar). */
    public const MODE_EXPENSE = 'expense';

    /** Activos / disponibilidades: magnitud positiva favorable. */
    public const MODE_ASSET = 'asset';

    /** Pasivos exigibles: magnitud positiva = atención. */
    public const MODE_LIABILITY = 'liability';

    public const TONE_ATTENTION = 'attention';

    public const TONE_FAVORABLE = 'favorable';

    public const TONE_NEUTRAL = 'neutral';

    public static function modeForChartType(?ChartAccountType $type): string
    {
        return match ($type) {
            ChartAccountType::Expense => self::MODE_EXPENSE,
            ChartAccountType::Income => self::MODE_RESULT,
            ChartAccountType::Asset => self::MODE_ASSET,
            ChartAccountType::Liability => self::MODE_LIABILITY,
            ChartAccountType::Equity => self::MODE_RESULT,
            default => self::MODE_RESULT,
        };
    }

    public static function tone(string $amount, string $mode = self::MODE_RESULT): string
    {
        $normalized = Money::normalize($amount);

        if ($mode === self::MODE_EXPENSE) {
            return self::TONE_NEUTRAL;
        }

        if (Money::isZero($normalized)) {
            return self::TONE_NEUTRAL;
        }

        $positive = Money::isPositive($normalized);

        return match ($mode) {
            self::MODE_CLIENT_CC, self::MODE_LIABILITY => $positive ? self::TONE_ATTENTION : self::TONE_FAVORABLE,
            self::MODE_ASSET => $positive ? self::TONE_FAVORABLE : self::TONE_ATTENTION,
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
