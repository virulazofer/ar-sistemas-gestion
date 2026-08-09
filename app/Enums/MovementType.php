<?php

namespace App\Enums;

enum MovementType: string
{
    case Income = 'income';
    case Expense = 'expense';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';

    public function isTransfer(): bool
    {
        return $this === self::TransferOut || $this === self::TransferIn;
    }

    public function signedMultiplier(): int
    {
        return match ($this) {
            self::Income, self::TransferIn => 1,
            self::Expense, self::TransferOut => -1,
        };
    }

    public function label(): string
    {
        return config('finance.movement_types.'.$this->value, $this->value);
    }
}
