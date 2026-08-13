<?php

namespace App\Enums;

enum MovementScope: string
{
    case Personal = 'personal';
    case Professional = 'professional';
    case Mixed = 'mixed';
    case Financial = 'financial';

    public function label(): string
    {
        return config('finance.scopes.'.$this->value, $this->value);
    }

    /** Ámbitos válidos para egresos. */
    public static function forExpense(): array
    {
        return [self::Personal, self::Professional, self::Mixed];
    }

    /** Orígenes válidos para ingresos (nueva carga). */
    public static function forIncome(): array
    {
        return [self::Professional, self::Financial];
    }

    /** @return list<string> */
    public static function valuesForExpense(): array
    {
        return array_map(fn (self $s) => $s->value, self::forExpense());
    }

    /** @return list<string> */
    public static function valuesForIncome(): array
    {
        return array_map(fn (self $s) => $s->value, self::forIncome());
    }
}
