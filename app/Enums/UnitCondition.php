<?php

namespace App\Enums;

enum UnitCondition: string
{
    case New = 'new';
    case OpenBox = 'open_box';
    case Used = 'used';
    case Refurbished = 'refurbished';
    case Unsellable = 'unsellable';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Nuevo',
            self::OpenBox => 'Open Box',
            self::Used => 'Usado',
            self::Refurbished => 'Reacondicionado',
            self::Unsellable => 'No vendible',
        };
    }
}
