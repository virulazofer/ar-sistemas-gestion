<?php

namespace App\Enums;

enum CommercialChargeStatus: string
{
    case Pending = 'pending';
    case Partial = 'partial';
    case Collected = 'collected';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Partial => 'Parcialmente cobrado',
            self::Collected => 'Cobrado',
            self::Voided => 'Anulado',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Partial], true);
    }
}
