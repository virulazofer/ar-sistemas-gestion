<?php

namespace App\Services\Finance;

use App\Enums\MovementScope;
use App\Enums\MovementType;
use App\Models\ChartAccount;
use InvalidArgumentException;

/**
 * Ámbito/Origen independiente del plan de cuentas.
 * INGRESO: Profesional | Financiero
 * EGRESO: Personal | Profesional | Mixto
 */
class ScopeOriginRules
{
    public function allowedForType(string|MovementType $type): array
    {
        $t = $type instanceof MovementType ? $type : MovementType::from($type);

        return match ($t) {
            MovementType::Income => MovementScope::valuesForIncome(),
            MovementType::Expense => MovementScope::valuesForExpense(),
            default => MovementScope::valuesForExpense(),
        };
    }

    public function assertAllowed(string|MovementType $type, string $scope): void
    {
        $allowed = $this->allowedForType($type);
        if (! in_array($scope, $allowed, true)) {
            $t = $type instanceof MovementType ? $type->value : $type;
            throw new InvalidArgumentException(
                $t === 'income'
                    ? 'Los ingresos solo admiten origen Profesional o Financiero (no Personal/Mixto).'
                    : 'Los egresos admiten ámbito Personal, Profesional o Mixto.'
            );
        }
    }

    public function isHistoricallyCompatible(string $type, string $scope): string
    {
        $allowed = $this->allowedForType($type);
        if (in_array($scope, $allowed, true)) {
            return 'A'; // compatible
        }

        if ($type === 'income' && $scope === MovementScope::Personal->value) {
            return 'C'; // ambiguo — no convertir en silencio
        }

        if ($type === 'income' && $scope === MovementScope::Mixed->value) {
            return 'C';
        }

        return 'C';
    }

    public function suggestFromChartAccount(?ChartAccount $account): ?string
    {
        if (! $account) {
            return null;
        }

        if ($account->suggested_scope) {
            return $account->suggested_scope;
        }

        $code = (string) $account->code;
        if (str_starts_with($code, '4.3') || str_starts_with($code, '4.3.')) {
            return MovementScope::Financial->value;
        }
        if (str_starts_with($code, '4.')) {
            return MovementScope::Professional->value;
        }
        if (str_starts_with($code, '5.4')) {
            return MovementScope::Personal->value;
        }

        return null;
    }

    public function fieldLabelForType(string|MovementType $type): string
    {
        $t = $type instanceof MovementType ? $type : MovementType::from($type);

        return $t === MovementType::Income ? 'Origen' : 'Ámbito';
    }
}
