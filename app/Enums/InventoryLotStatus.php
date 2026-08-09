<?php

namespace App\Enums;

enum InventoryLotStatus: string
{
    case Open = 'open';
    case Depleted = 'depleted';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierto',
            self::Depleted => 'Agotado',
            self::Voided => 'Anulado',
        };
    }
}
