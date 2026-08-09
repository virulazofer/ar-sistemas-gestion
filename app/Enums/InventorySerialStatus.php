<?php

namespace App\Enums;

enum InventorySerialStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Consumed = 'consumed';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Disponible',
            self::Reserved => 'Reservado',
            self::Consumed => 'Consumido',
            self::Returned => 'Devuelto / recuperado',
        };
    }
}
