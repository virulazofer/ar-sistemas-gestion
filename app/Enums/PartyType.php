<?php

namespace App\Enums;

enum PartyType: string
{
    case Particular = 'particular';
    case Empresa = 'empresa';

    public function label(): string
    {
        return match ($this) {
            self::Particular => 'Particular',
            self::Empresa => 'Empresa',
        };
    }

    public function requiresDni(): bool
    {
        return $this === self::Particular;
    }

    public function requiresCuitAndBusinessName(): bool
    {
        return $this === self::Empresa;
    }
}
