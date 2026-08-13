<?php

namespace App\Enums;

enum ChartAccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';
    /** @deprecated Raíz 6 eliminada; impuestos viven en Activo/Pasivo/Egresos. */
    case Result = 'result';

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Activo',
            self::Liability => 'Pasivo',
            self::Equity => 'Patrimonio Neto',
            self::Income => 'Ingresos',
            self::Expense => 'Egresos',
            self::Result => 'Resultados (legado)',
        };
    }

    public function rootCode(): ?string
    {
        return match ($this) {
            self::Asset => '1',
            self::Liability => '2',
            self::Equity => '3',
            self::Income => '4',
            self::Expense => '5',
            self::Result => null,
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

    /** @return list<self> */
    public static function structuralRoots(): array
    {
        return [self::Asset, self::Liability, self::Equity, self::Income, self::Expense];
    }
}
