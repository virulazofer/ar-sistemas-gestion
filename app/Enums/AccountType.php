<?php

namespace App\Enums;

enum AccountType: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Wallet = 'wallet';
    case CreditCard = 'credit_card';
    case Other = 'other';

    public function label(): string
    {
        return config('finance.account_types.'.$this->value, $this->value);
    }

    public function isLiability(): bool
    {
        return $this === self::CreditCard;
    }
}
