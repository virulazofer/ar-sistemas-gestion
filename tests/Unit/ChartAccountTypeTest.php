<?php

use App\Enums\ChartAccountType;

test('labels de plan de cuentas en español', function () {
    expect(ChartAccountType::Income->label())->toBe('Ingresos');
    expect(ChartAccountType::Expense->label())->toBe('Egresos');
    expect(ChartAccountType::Asset->label())->toBe('Activo');
    expect(ChartAccountType::Liability->label())->toBe('Pasivo');
    expect(ChartAccountType::Equity->label())->toBe('Patrimonio Neto');
    expect(ChartAccountType::Result->label())->toBe('Resultados (legado)');

    expect(ChartAccountType::labelFor('income'))->toBe('Ingresos');
    expect(ChartAccountType::labelFor('unknown'))->toBe('unknown');
    expect(ChartAccountType::structuralRoots())->toHaveCount(5);
});
