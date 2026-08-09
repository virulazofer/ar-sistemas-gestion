<?php

namespace App\Enums;

enum AccountType: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Wallet = 'wallet';
    case Other = 'other';

    public function label(): string
    {
        return config('finance.account_types.'.$this->value, $this->value);
    }
}
