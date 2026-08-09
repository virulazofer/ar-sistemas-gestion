<?php

namespace App\Enums;

enum QuotationStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Converted = 'converted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Sent => 'Enviado',
            self::Accepted => 'Aceptado',
            self::Rejected => 'Rechazado',
            self::Expired => 'Vencido',
            self::Converted => 'Convertido',
            self::Cancelled => 'Cancelado',
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Sent, self::Accepted, self::Expired], true);
    }

    public function canConvert(): bool
    {
        return in_array($this, [self::Accepted, self::Sent], true);
    }
}
