<?php

namespace App\Enums;

enum UnitStatus: string
{
    case Available = 'available';
    case InUse = 'in_use';
    case Reserved = 'reserved';
    case Sold = 'sold';
    case Repair = 'repair';
    case Scrapped = 'scrapped';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Disponible',
            self::InUse => 'En uso',
            self::Reserved => 'Reservada',
            self::Sold => 'Vendida',
            self::Repair => 'Reparación',
            self::Scrapped => 'Baja',
        };
    }
}
