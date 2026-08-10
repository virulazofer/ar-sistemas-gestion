<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public static function normalize(string|float|int $amount, int $decimals = 2): string
    {
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException('Importe inválido.');
        }

        return number_format((float) $amount, $decimals, '.', '');
    }

    public static function add(string $a, string $b, int $decimals = 2): string
    {
        return self::normalize(bcadd($a, $b, $decimals + 2), $decimals);
    }

    public static function sub(string $a, string $b, int $decimals = 2): string
    {
        return self::normalize(bcsub($a, $b, $decimals + 2), $decimals);
    }

    public static function mul(string $a, string $b, int $decimals = 2): string
    {
        return self::normalize(bcmul($a, $b, $decimals + 4), $decimals);
    }

    public static function div(string $a, string $b, int $decimals = 2): string
    {
        if (bccomp($b, '0', 8) === 0) {
            throw new InvalidArgumentException('División por cero.');
        }

        return self::normalize(bcdiv($a, $b, $decimals + 4), $decimals);
    }

    public static function compare(string $a, string $b, int $decimals = 2): int
    {
        return bccomp(self::normalize($a, $decimals), self::normalize($b, $decimals), $decimals);
    }

    public static function isPositive(string $amount): bool
    {
        return self::compare($amount, '0') > 0;
    }

    public static function isZero(string $amount): bool
    {
        return self::compare($amount, '0') === 0;
    }

    public static function isNegative(string $amount): bool
    {
        return self::compare($amount, '0') < 0;
    }

    /**
     * Formato AR: "$ 1.234.567,89" / "U$S 1.234.567,89". No mezcla monedas.
     */
    public static function formatAr(string $amount, string $currency = 'ARS'): string
    {
        $normalized = self::normalize($amount);
        $formatted = number_format((float) $normalized, 2, ',', '.');

        return strtoupper($currency) === 'USD'
            ? 'U$S '.$formatted
            : '$ '.$formatted;
    }

    /**
     * Variación % vs base. Null si no hay base comparable (evita /0).
     */
    public static function percentChange(?string $current, ?string $previous): ?string
    {
        if ($previous === null || $current === null) {
            return null;
        }

        $prev = self::normalize($previous);
        $curr = self::normalize($current);

        if (self::isZero($prev)) {
            return null;
        }

        $delta = self::sub($curr, $prev);
        $pct = self::mul(self::div($delta, $prev, 6), '100', 2);

        return $pct;
    }
}
