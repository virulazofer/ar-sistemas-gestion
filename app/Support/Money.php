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
}
