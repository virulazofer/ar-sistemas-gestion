<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Cancelled = 'cancelled';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activo',
            self::Paused => 'Pausado',
            self::Cancelled => 'Cancelado',
            self::Ended => 'Finalizado',
        };
    }

    public function generatesCharges(): bool
    {
        return $this === self::Active;
    }
}
