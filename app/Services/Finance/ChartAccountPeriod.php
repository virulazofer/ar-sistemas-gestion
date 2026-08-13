<?php

namespace App\Services\Finance;

use Carbon\Carbon;

/**
 * Selector de período del Plan de cuentas.
 */
final class ChartAccountPeriod
{
    public const THIS_MONTH = 'this_month';

    public const LAST_MONTH = 'last_month';

    public const THIS_YEAR = 'this_year';

    public const CUSTOM = 'custom';

    /**
     * @return array{preset:string,from:?string,to:?string,label:string}
     */
    public static function resolve(?string $preset, ?string $from, ?string $to, ?Carbon $now = null): array
    {
        $now = $now ?? now();
        $preset = in_array($preset, [self::THIS_MONTH, self::LAST_MONTH, self::THIS_YEAR, self::CUSTOM], true)
            ? $preset
            : self::THIS_MONTH;

        return match ($preset) {
            self::LAST_MONTH => [
                'preset' => self::LAST_MONTH,
                'from' => $now->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                'to' => $now->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
                'label' => 'Mes anterior',
            ],
            self::THIS_YEAR => [
                'preset' => self::THIS_YEAR,
                'from' => $now->copy()->startOfYear()->toDateString(),
                'to' => $now->copy()->endOfYear()->toDateString(),
                'label' => 'Este año',
            ],
            self::CUSTOM => [
                'preset' => self::CUSTOM,
                'from' => $from ?: null,
                'to' => $to ?: null,
                'label' => 'Personalizado',
            ],
            default => [
                'preset' => self::THIS_MONTH,
                'from' => $now->copy()->startOfMonth()->toDateString(),
                'to' => $now->copy()->endOfMonth()->toDateString(),
                'label' => 'Este mes',
            ],
        };
    }

    /** @return list<array{value:string,label:string}> */
    public static function options(): array
    {
        return [
            ['value' => self::THIS_MONTH, 'label' => 'Este mes'],
            ['value' => self::LAST_MONTH, 'label' => 'Mes anterior'],
            ['value' => self::THIS_YEAR, 'label' => 'Este año'],
            ['value' => self::CUSTOM, 'label' => 'Personalizado'],
        ];
    }
}
