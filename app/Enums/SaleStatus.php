<?php

namespace App\Enums;

enum SaleStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Confirmed => 'Confirmada',
            self::Voided => 'Anulada',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
