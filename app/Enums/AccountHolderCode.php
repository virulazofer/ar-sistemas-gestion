<?php

namespace App\Enums;

enum AccountHolderCode: string
{
    case Fernando = 'fernando';
    case Gabi = 'gabi';

    public function label(): string
    {
        return match ($this) {
            self::Fernando => 'Fernando',
            self::Gabi => 'Gabi',
        };
    }
}
