<?php

namespace App\Enums;

enum DocumentalStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Associated = 'associated';
    case NotRequired = 'not_required';
    case Review = 'review';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Sin comprobante asociado',
            self::Pending => 'Pendiente de comprobante',
            self::Associated => 'Comprobante asociado',
            self::NotRequired => 'No requiere comprobante',
            self::Review => 'A revisar',
        };
    }

    public function needsAttention(): bool
    {
        return in_array($this, [self::None, self::Pending, self::Review], true);
    }
}
