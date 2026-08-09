<?php

use App\Enums\ChartAccountType;

test('labels de plan de cuentas en español', function () {
    expect(ChartAccountType::Income->label())->toBe('Ingresos');
    expect(ChartAccountType::Expense->label())->toBe('Gastos');
    expect(ChartAccountType::Asset->label())->toBe('Activos');
    expect(ChartAccountType::Liability->label())->toBe('Pasivos');
    expect(ChartAccountType::Equity->label())->toBe('Patrimonio');
    expect(ChartAccountType::Result->label())->toBe('Resultados');

    expect(ChartAccountType::labelFor('income'))->toBe('Ingresos');
    expect(ChartAccountType::labelFor('unknown'))->toBe('unknown');
});
