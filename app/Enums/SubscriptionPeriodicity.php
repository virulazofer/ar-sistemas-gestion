<?php

namespace App\Enums;

enum SubscriptionPeriodicity: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Semiannual = 'semiannual';
    case Annual = 'annual';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Mensual',
            self::Quarterly => 'Trimestral',
            self::Semiannual => 'Semestral',
            self::Annual => 'Anual',
        };
    }

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Semiannual => 6,
            self::Annual => 12,
        };
    }
}
