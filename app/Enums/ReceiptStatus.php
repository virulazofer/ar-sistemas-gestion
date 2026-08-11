<?php

namespace App\Enums;

enum ReceiptStatus: string
{
    case Posted = 'posted';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Posted => 'Registrado',
            self::Voided => 'Anulado',
        };
    }
}
