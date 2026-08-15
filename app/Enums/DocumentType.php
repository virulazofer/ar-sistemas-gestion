<?php

namespace App\Enums;

enum DocumentType: string
{
    case Factura = 'factura';
    case Ticket = 'ticket';
    case Remito = 'remito';
    case Otro = 'otro';
    case Adjunto = 'adjunto';

    public function label(): string
    {
        return match ($this) {
            self::Factura => 'Factura',
            self::Ticket => 'Ticket',
            self::Remito => 'Remito',
            self::Otro => 'Otro',
            self::Adjunto => 'Adjunto',
        };
    }
}
