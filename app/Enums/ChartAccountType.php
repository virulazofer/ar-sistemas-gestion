<?php

namespace App\Enums;

enum ChartAccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';
    case Result = 'result';

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Activos',
            self::Liability => 'Pasivos',
            self::Equity => 'Patrimonio',
            self::Income => 'Ingresos',
            self::Expense => 'Gastos',
            self::Result => 'Resultados',
        };
    }

    public static function labelFor(?string $value): string
    {
        if ($value === null || $value === '') {
            return 'Sin tipo';
        }

        $case = self::tryFrom($value);

        return $case?->label() ?? $value;
    }
}
