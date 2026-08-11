<?php

namespace App\Enums;

enum CommercialVoucherType: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Factura',
            self::CreditNote => 'Nota de crédito',
            self::DebitNote => 'Nota de débito',
            self::Other => 'Otro comprobante',
        };
    }
}
