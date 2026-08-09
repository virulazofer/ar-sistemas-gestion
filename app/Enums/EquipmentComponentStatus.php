<?php

namespace App\Enums;

enum EquipmentComponentStatus: string
{
    case Installed = 'installed';
    case Removed = 'removed';
    case Recovered = 'recovered';

    public function label(): string
    {
        return match ($this) {
            self::Installed => 'Instalado',
            self::Removed => 'Retirado',
            self::Recovered => 'Recuperado a stock',
        };
    }
}
