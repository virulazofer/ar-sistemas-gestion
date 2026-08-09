<?php

namespace App\Enums;

enum ClientLedgerType: string
{
    case Charge = 'charge';
    case Payment = 'payment';
    case Credit = 'credit';
    case Adjustment = 'adjustment';
    case CreditApplication = 'credit_application';

    public function label(): string
    {
        return match ($this) {
            self::Charge => 'Cargo',
            self::Payment => 'Pago',
            self::Credit => 'Crédito a favor',
            self::Adjustment => 'Ajuste',
            self::CreditApplication => 'Aplicación de crédito',
        };
    }

    /**
     * Multiplicador del importe absoluto sobre el saldo del cliente.
     * Negativo = aumenta deuda; positivo = a favor del cliente.
     */
    public function defaultSignMultiplier(): int
    {
        return match ($this) {
            self::Charge, self::CreditApplication => -1,
            self::Payment, self::Credit => 1,
            self::Adjustment => 0, // requiere signo explícito
        };
    }
}
