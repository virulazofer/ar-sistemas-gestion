<?php

namespace App\Services\Inventory;

use App\Enums\UnitCondition;
use App\Enums\UnitStatus;
use App\Models\InventoryUnit;
use App\Models\InventoryUnitEvent;
use App\Models\Product;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InventoryUnitService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(Product $product, array $data): InventoryUnit
    {
        return DB::transaction(function () use ($product, $data) {
            $condition = UnitCondition::from($data['condition'] ?? UnitCondition::New->value);
            $status = UnitStatus::from($data['status'] ?? UnitStatus::Available->value);
            $code = trim((string) ($data['internal_code'] ?? ''));
            if ($code === '') {
                $code = $this->nextInternalCode();
            }

            $unit = InventoryUnit::query()->create([
                'product_id' => $product->id,
                'internal_code' => $code,
                'manufacturer_serial' => $data['manufacturer_serial'] ?? null,
                'condition' => $condition->value,
                'status' => $status->value,
                'inventory_lot_id' => $data['inventory_lot_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            $this->recordEvent($unit, 'created', null, $condition, null, $status, $data['notes'] ?? 'Alta de unidad');

            $this->audit->log('inventory_unit_created', $unit, null, [
                'product_id' => $product->id,
                'internal_code' => $unit->internal_code,
            ], 'Unidad de inventario creada');

            return $unit;
        });
    }

    public function transition(
        InventoryUnit $unit,
        ?UnitCondition $toCondition = null,
        ?UnitStatus $toStatus = null,
        ?string $notes = null,
    ): InventoryUnit {
        return DB::transaction(function () use ($unit, $toCondition, $toStatus, $notes) {
            $fromCondition = $unit->condition;
            $fromStatus = $unit->status;
            $updates = [];

            if ($toCondition && $toCondition !== $fromCondition) {
                $updates['condition'] = $toCondition->value;
            }
            if ($toStatus && $toStatus !== $fromStatus) {
                $updates['status'] = $toStatus->value;
                if ($toStatus === UnitStatus::InUse && $unit->first_used_at === null) {
                    $updates['first_used_at'] = now();
                }
            }
            if ($notes !== null) {
                $updates['notes'] = $notes;
            }

            if ($updates === []) {
                throw new InvalidArgumentException('No hay cambios de condición/estado.');
            }

            $unit->update($updates);
            $fresh = $unit->fresh();

            $this->recordEvent(
                $fresh,
                'transition',
                $fromCondition,
                $fresh->condition,
                $fromStatus,
                $fresh->status,
                $notes
            );

            $this->audit->log('inventory_unit_transition', $fresh, [
                'condition' => $fromCondition?->value,
                'status' => $fromStatus?->value,
            ], [
                'condition' => $fresh->condition->value,
                'status' => $fresh->status->value,
            ], 'Cambio de condición/estado de unidad');

            return $fresh;
        });
    }

    public function nextInternalCode(): string
    {
        $last = InventoryUnit::query()->orderByDesc('id')->value('internal_code');
        $n = 1;
        if (is_string($last) && preg_match('/UNI-(\d+)/', $last, $m)) {
            $n = ((int) $m[1]) + 1;
        }

        return sprintf('UNI-%06d', $n);
    }

    private function recordEvent(
        InventoryUnit $unit,
        string $type,
        ?UnitCondition $fromCondition,
        ?UnitCondition $toCondition,
        ?UnitStatus $fromStatus,
        ?UnitStatus $toStatus,
        ?string $notes,
    ): void {
        InventoryUnitEvent::query()->create([
            'inventory_unit_id' => $unit->id,
            'event_type' => $type,
            'from_condition' => $fromCondition?->value,
            'to_condition' => $toCondition?->value,
            'from_status' => $fromStatus?->value,
            'to_status' => $toStatus?->value,
            'notes' => $notes,
            'user_id' => Auth::id(),
            'occurred_at' => now(),
        ]);
    }
}
