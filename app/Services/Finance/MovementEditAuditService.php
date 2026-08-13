<?php

namespace App\Services\Finance;

use App\Models\Movement;
use App\Models\MovementEditAudit;
use Illuminate\Support\Facades\Auth;

/**
 * Auditoría delta ligera por campo. No dispara AuditLogger (evita bucles).
 */
class MovementEditAuditService
{
    /**
     * @param  list<array{field: string, old: mixed, new: mixed, reason?: ?string}>  $deltas
     */
    public function recordDeltas(Movement $movement, array $deltas): void
    {
        if ($deltas === []) {
            return;
        }

        $now = now();
        $userId = Auth::id();
        $rows = [];

        foreach ($deltas as $delta) {
            $rows[] = [
                'entity_type' => 'movement',
                'movement_id' => $movement->id,
                'movement_code' => $movement->code,
                'field' => (string) $delta['field'],
                'old_value' => $this->stringify($delta['old'] ?? null),
                'new_value' => $this->stringify($delta['new'] ?? null),
                'user_id' => $userId,
                'reason' => isset($delta['reason']) ? (string) $delta['reason'] : null,
                'created_at' => $now,
            ];
        }

        MovementEditAudit::query()->insert($rows);
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }
}
