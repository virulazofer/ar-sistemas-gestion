<?php

namespace App\Enums;

enum ProductType: string
{
    case Physical = 'physical';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Physical => 'Producto físico',
            self::Service => 'Servicio',
        };
    }

    public function tracksStock(): bool
    {
        return $this === self::Physical;
    }
}
