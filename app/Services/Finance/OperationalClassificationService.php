<?php

namespace App\Services\Finance;

use App\Enums\MovementType;
use App\Models\Movement;
use Illuminate\Database\Eloquent\Builder;

/**
 * Clasificación operativa única: NATURALEZA (INGRESO|EGRESO) → CATEGORÍA → SUBCATEGORÍA.
 * El plan de cuentas es patrimonial/financiero; no es copia del árbol cat/sub.
 */
class OperationalClassificationService
{
    /**
     * Movimiento incompleto operativamente (falta categoría).
     * Tener cat/sub correcta NO exige chart_account_id para considerarse clasificado.
     */
    public function isIncomplete(Movement $movement): bool
    {
        $type = $movement->type instanceof MovementType
            ? $movement->type
            : MovementType::tryFrom((string) $movement->getRawOriginal('type'));

        if (! $type || $type->isTransfer()) {
            return false;
        }

        return $movement->category_id === null;
    }

    public function isComplete(Movement $movement): bool
    {
        $type = $movement->type instanceof MovementType
            ? $movement->type
            : MovementType::tryFrom((string) $movement->getRawOriginal('type'));

        if (! $type || $type->isTransfer()) {
            return true;
        }

        return $movement->category_id !== null;
    }

    /**
     * Base query: ingresos/egresos posted.
     */
    public function incomeExpenseBase(): Builder
    {
        return Movement::query()
            ->posted()
            ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value]);
    }

    /**
     * Pendientes operativos: sin categoría (naturaleza ya viene del type).
     */
    public function pendingQuery(): Builder
    {
        return $this->incomeExpenseBase()->whereNull('category_id');
    }

    /**
     * @return array{total: int, classified: int, pending: int, percent: float, missing_chart_optional: int}
     */
    public function progress(): array
    {
        $base = $this->incomeExpenseBase();
        $total = (clone $base)->count();
        $pending = (clone $base)->whereNull('category_id')->count();
        $classified = max(0, $total - $pending);
        $percent = $total > 0 ? round(($classified / $total) * 100, 1) : 100.0;
        // Métrica secundaria: cat/sub OK pero sin cuenta contable (no cuenta como incompleto).
        $missingChartOptional = (clone $base)
            ->whereNotNull('category_id')
            ->whereNull('chart_account_id')
            ->count();

        return [
            'total' => $total,
            'classified' => $classified,
            'pending' => $pending,
            'percent' => $percent,
            'missing_chart_optional' => $missingChartOptional,
        ];
    }

    public function countPending(): int
    {
        return $this->pendingQuery()->count();
    }

    public function naturalezaLabel(Movement|string|MovementType $type): string
    {
        $value = $type instanceof Movement
            ? ($type->type instanceof MovementType ? $type->type->value : (string) $type->getRawOriginal('type'))
            : ($type instanceof MovementType ? $type->value : (string) $type);

        return match (strtolower($value)) {
            'income' => 'INGRESO',
            'expense' => 'EGRESO',
            default => strtoupper($value),
        };
    }
}
