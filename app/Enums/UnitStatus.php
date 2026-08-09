<?php

namespace App\Enums;

enum UnitStatus: string
{
    case Available = 'available';
    case InUse = 'in_use';
    case Reserved = 'reserved';
    case Sold = 'sold';
    case Scrapped = 'scrapped';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Disponible',
            self::InUse => 'En uso',
            self::Reserved => 'Reservado',
            self::Sold => 'Vendido',
            self::Scrapped => 'Baja',
        };
    }
}
