<?php

namespace App\Enums;

enum ImportReviewStatus: string
{
    case Green = 'green';
    case Yellow = 'yellow';
    case Red = 'red';
    case Excluded = 'excluded';
    case Accepted = 'accepted';

    public function label(): string
    {
        return match ($this) {
            self::Green => 'Verde',
            self::Yellow => 'Amarillo',
            self::Red => 'Rojo',
            self::Excluded => 'Excluido',
            self::Accepted => 'Aceptado',
        };
    }
}
