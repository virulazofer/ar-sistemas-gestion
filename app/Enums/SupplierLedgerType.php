<?php

namespace App\Enums;

enum SupplierLedgerType: string
{
    case Charge = 'charge';
    case Payment = 'payment';
    case Credit = 'credit';
    case Adjustment = 'adjustment';
    case CreditApplication = 'credit_application';

    public function label(): string
    {
        return match ($this) {
            self::Charge => 'Cargo / Obligación',
            self::Payment => 'Pago',
            self::Credit => 'Crédito a favor',
            self::Adjustment => 'Ajuste',
            self::CreditApplication => 'Aplicación de crédito',
        };
    }
}
