<?php

namespace App\Services\Equipment;

use App\Enums\EquipmentComponentStatus;
use App\Enums\EquipmentStatus;
use App\Enums\InventoryLotStatus;
use App\Models\Equipment;
use App\Models\EquipmentComponent;
use App\Models\EquipmentStatusLog;
use App\Models\EquipmentType;
use App\Models\InventoryLot;
use App\Models\InventorySerial;
use App\Models\Product;
use App\Services\AuditLogger;
use App\Services\Inventory\FifoService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\SerialInventoryService;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class EquipmentAssemblyService
{
    public function __construct(
        private readonly EquipmentTypeService $types,
        private readonly InventoryService $inventory,
        private readonly FifoService $fifo,
        private readonly SerialInventoryService $serials,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Armado atómico: consume stock FIFO (y seriales) y consolida costos reales.
     *
     * @param  array{
     *   equipment_type_id: int,
     *   name: string,
     *   code?: string|null,
     *   inventory_location_id?: int|null,
     *   notes?: string|null,
     *   components: list<array{
     *     product_id: int,
     *     quantity?: int|string,
     *     component_category_id?: int|null,
     *     inventory_serial_id?: int|null,
     *     serial_number?: string|null,
     *     inventory_lot_id?: int|null
     *   }>,
     *   force_fail?: bool
     * }  $data
     */
    public function assemble(array $data): Equipment
    {
        return DB::transaction(function () use ($data) {
            $type = EquipmentType::query()->lockForUpdate()->findOrFail($data['equipment_type_id']);
            if (! $type->is_active) {
                throw new InvalidArgumentException('El tipo de equipo no está activo.');
            }

            $components = $data['components'] ?? [];
            if ($components === []) {
                throw new InvalidArgumentException('El armado requiere al menos un componente.');
            }

            $code = trim((string) ($data['code'] ?? ''));
            if ($code === '') {
                $code = $this->types->nextCode($type);
            } elseif (Equipment::query()->where('code', $code)->exists()) {
                throw new InvalidArgumentException('El código de equipo ya existe.');
            }

            $equipment = Equipment::query()->create([
                'code' => $code,
                'equipment_type_id' => $type->id,
                'name' => trim((string) $data['name']),
                'status' => EquipmentStatus::Assembled,
                'assembled_at' => now(),
                'inventory_location_id' => $data['inventory_location_id'] ?? null,
                'total_cost' => '0',
                'total_cost_ars' => '0',
                'total_cost_usd' => '0',
                'user_id' => Auth::id() ?? throw new RuntimeException('Usuario requerido.'),
                'notes' => $data['notes'] ?? null,
            ]);

            $this->logStatus($equipment, null, EquipmentStatus::Assembled, 'Armado inicial');

            $sumCost = '0';
            $sumArs = '0.00';
            $sumUsd = '0.00';

            foreach ($components as $line) {
                $installed = $this->installComponent($equipment, $line, wrap: false);
                foreach ($installed as $component) {
                    $sumCost = Money::add($sumCost, (string) $component->total_cost, 6);
                    $sumArs = Money::add($sumArs, (string) $component->total_cost_ars);
                    $sumUsd = Money::add($sumUsd, (string) $component->total_cost_usd);
                }
            }

            if (! empty($data['force_fail'])) {
                throw new RuntimeException('Falla simulada en armado.');
            }

            $equipment->update([
                'total_cost' => $sumCost,
                'total_cost_ars' => $sumArs,
                'total_cost_usd' => $sumUsd,
            ]);

            $this->audit->log('equipment_assembled', $equipment, null, [
                'code' => $equipment->code,
                'total_cost_usd' => $sumUsd,
                'components' => count($components),
            ], 'Equipo armado');

            return $equipment->fresh(['components.product', 'components.serial', 'components.lot', 'type']);
        });
    }

    public function changeStatus(Equipment $equipment, EquipmentStatus $to, ?string $reason = null, bool $adminOverride = false): Equipment
    {
        return DB::transaction(function () use ($equipment, $to, $reason, $adminOverride) {
            $equipment = Equipment::query()->lockForUpdate()->findOrFail($equipment->id);
            $from = $equipment->status;

            if ($to === EquipmentStatus::Disassembled) {
                throw new InvalidArgumentException('Usá disassemble() para desarmar.');
            }

            if ($from === EquipmentStatus::Sold && ! $adminOverride) {
                throw new InvalidArgumentException('Equipo vendido: transición bloqueada sin procedimiento administrativo.');
            }

            if (! $from->canTransitionTo($to) && ! $adminOverride) {
                throw new InvalidArgumentException("Transición inválida: {$from->value} → {$to->value}.");
            }

            $equipment->update(['status' => $to]);
            $this->logStatus($equipment, $from, $to, $reason);
            $this->audit->log('equipment_status_changed', $equipment, ['status' => $from->value], [
                'status' => $to->value,
                'reason' => $reason,
            ], 'Cambio de estado de equipo');

            return $equipment->fresh();
        });
    }

    public function disassemble(Equipment $equipment, string $reason, bool $adminOverride = false): Equipment
    {
        return DB::transaction(function () use ($equipment, $reason, $adminOverride) {
            $equipment = Equipment::query()->lockForUpdate()->findOrFail($equipment->id);
            $from = $equipment->status;

            if (! $from->allowsDisassembly($adminOverride)) {
                throw new InvalidArgumentException('No se puede desarmar el equipo en estado '.$from->label().'.');
            }

            $installed = $equipment->components()->where('status', EquipmentComponentStatus::Installed->value)->lockForUpdate()->get();
            foreach ($installed as $component) {
                $this->returnComponentToStock($component, $reason, wrap: false);
            }

            $equipment->update([
                'status' => EquipmentStatus::Disassembled,
                'disassembled_at' => now(),
            ]);
            $this->logStatus($equipment, $from, EquipmentStatus::Disassembled, $reason);
            $this->audit->log('equipment_disassembled', $equipment, ['status' => $from->value], [
                'status' => 'disassembled',
                'reason' => $reason,
                'components_recovered' => $installed->count(),
            ], 'Equipo desarmado');

            return $equipment->fresh(['components']);
        });
    }

    /**
     * Reemplazo de componente: retorna el viejo al stock e instala el nuevo (consumo FIFO/serial).
     */
    public function replaceComponent(Equipment $equipment, EquipmentComponent $old, array $newLine, string $reason): EquipmentComponent
    {
        return DB::transaction(function () use ($equipment, $old, $newLine, $reason) {
            $equipment = Equipment::query()->lockForUpdate()->findOrFail($equipment->id);
            if (in_array($equipment->status, [EquipmentStatus::Disassembled, EquipmentStatus::Sold], true)) {
                throw new InvalidArgumentException('No se puede reemplazar componentes en este estado.');
            }

            $old = EquipmentComponent::query()->lockForUpdate()->findOrFail($old->id);
            if ((int) $old->equipment_id !== (int) $equipment->id || $old->status !== EquipmentComponentStatus::Installed) {
                throw new InvalidArgumentException('Componente no instalado en este equipo.');
            }

            $this->returnComponentToStock($old, $reason, wrap: false);

            $installed = $this->installComponent($equipment, array_merge($newLine, [
                'component_category_id' => $newLine['component_category_id'] ?? $old->component_category_id,
            ]), wrap: false);

            $new = $installed[0];
            $old->update(['replaced_by_component_id' => $new->id]);

            $this->recalculateTotals($equipment);

            $this->audit->log('equipment_component_replaced', $equipment, [
                'old_component_id' => $old->id,
                'old_serial' => $old->inventory_serial_id,
            ], [
                'new_component_id' => $new->id,
                'reason' => $reason,
            ], 'Reemplazo de componente');

            return $new;
        });
    }

    /**
     * @return list<EquipmentComponent>
     */
    private function installComponent(Equipment $equipment, array $line, bool $wrap): array
    {
        $product = Product::query()->lockForUpdate()->findOrFail($line['product_id']);
        if (! $product->tracksStock()) {
            throw new InvalidArgumentException('Solo productos físicos pueden armar equipos.');
        }

        $qtyRequested = (int) ($line['quantity'] ?? 1);
        if ($qtyRequested < 1) {
            throw new InvalidArgumentException('Cantidad de componente inválida.');
        }

        $created = [];

        if ($product->requires_serial) {
            if ($qtyRequested !== 1 && empty($line['serials'])) {
                // una línea = un serial; para varios seriales usar múltiples entradas o array serials
            }
            $serialEntries = [];
            if (! empty($line['inventory_serial_id']) || ! empty($line['serial_number'])) {
                $serialEntries[] = $line;
            } elseif (! empty($line['serials']) && is_array($line['serials'])) {
                foreach ($line['serials'] as $s) {
                    $serialEntries[] = is_array($s) ? $s : ['serial_number' => $s];
                }
            } else {
                throw new InvalidArgumentException('Producto serializado requiere serial: '.$product->sku);
            }

            foreach ($serialEntries as $entry) {
                $serial = null;
                if (! empty($entry['inventory_serial_id'])) {
                    $serial = InventorySerial::query()->lockForUpdate()->findOrFail($entry['inventory_serial_id']);
                } else {
                    $serial = $this->serials->findAvailable($product, (string) ($entry['serial_number'] ?? ''));
                    $serial = InventorySerial::query()->lockForUpdate()->findOrFail($serial->id);
                }
                $lot = InventoryLot::query()->lockForUpdate()->findOrFail($serial->inventory_lot_id);
                $movement = $this->inventory->consumeFromLot($product, $lot, '1', [
                    'inventory_serial_id' => $serial->id,
                    'reason' => 'Armado equipo '.$equipment->code,
                    'wrap_transaction' => false,
                ]);
                $created[] = $this->persistComponent($equipment, $product, $movement, $line, $serial);
            }

            return $created;
        }

        // No serializado: FIFO automático; una fila de componente por allocation
        if (! empty($line['inventory_lot_id'])) {
            $lot = InventoryLot::query()->lockForUpdate()->findOrFail($line['inventory_lot_id']);
            $movement = $this->inventory->consumeFromLot($product, $lot, (string) $qtyRequested, [
                'reason' => 'Armado equipo '.$equipment->code,
                'wrap_transaction' => false,
            ]);
            foreach ($movement->allocations as $alloc) {
                $created[] = $this->persistComponentFromAllocation($equipment, $product, $movement, $alloc, $line);
            }

            return $created;
        }

        $plan = $this->fifo->planConsumption($product->id, (string) $qtyRequested);
        foreach ($plan['allocations'] as $row) {
            $lot = InventoryLot::query()->lockForUpdate()->findOrFail($row['lot_id']);
            $movement = $this->inventory->consumeFromLot($product, $lot, $row['quantity'], [
                'reason' => 'Armado equipo '.$equipment->code,
                'wrap_transaction' => false,
            ]);
            foreach ($movement->allocations as $alloc) {
                $created[] = $this->persistComponentFromAllocation($equipment, $product, $movement, $alloc, $line);
            }
        }

        return $created;
    }

    private function persistComponent(
        Equipment $equipment,
        Product $product,
        $movement,
        array $line,
        InventorySerial $serial,
    ): EquipmentComponent {
        $alloc = $movement->allocations->first();

        return EquipmentComponent::query()->create([
            'equipment_id' => $equipment->id,
            'component_category_id' => $line['component_category_id'] ?? null,
            'product_id' => $product->id,
            'inventory_lot_id' => $serial->inventory_lot_id,
            'inventory_serial_id' => $serial->id,
            'inventory_movement_id' => $movement->id,
            'inventory_lot_allocation_id' => $alloc?->id,
            'quantity' => '1.0000',
            'unit_cost' => $alloc?->unit_cost ?? $movement->unit_cost,
            'currency_id' => $alloc?->currency_id ?? $movement->currency_id,
            'exchange_rate_value' => $alloc?->exchange_rate_value ?? $movement->exchange_rate_value,
            'unit_cost_ars' => $alloc?->unit_cost_ars ?? '0',
            'unit_cost_usd' => $alloc?->unit_cost_usd ?? '0',
            'total_cost' => $alloc?->total_cost ?? $movement->total_cost,
            'total_cost_ars' => $alloc?->total_cost_ars ?? $movement->total_cost_ars,
            'total_cost_usd' => $alloc?->total_cost_usd ?? $movement->total_cost_usd,
            'status' => EquipmentComponentStatus::Installed,
            'installed_at' => now(),
            'warranty_until' => $serial->warranty_until,
            'purchase_id' => $serial->purchase_id,
        ]);
    }

    private function persistComponentFromAllocation(
        Equipment $equipment,
        Product $product,
        $movement,
        $alloc,
        array $line,
    ): EquipmentComponent {
        return EquipmentComponent::query()->create([
            'equipment_id' => $equipment->id,
            'component_category_id' => $line['component_category_id'] ?? null,
            'product_id' => $product->id,
            'inventory_lot_id' => $alloc->inventory_lot_id,
            'inventory_serial_id' => null,
            'inventory_movement_id' => $movement->id,
            'inventory_lot_allocation_id' => $alloc->id,
            'quantity' => $alloc->quantity,
            'unit_cost' => $alloc->unit_cost,
            'currency_id' => $alloc->currency_id,
            'exchange_rate_value' => $alloc->exchange_rate_value,
            'unit_cost_ars' => $alloc->unit_cost_ars,
            'unit_cost_usd' => $alloc->unit_cost_usd,
            'total_cost' => $alloc->total_cost,
            'total_cost_ars' => $alloc->total_cost_ars,
            'total_cost_usd' => $alloc->total_cost_usd,
            'status' => EquipmentComponentStatus::Installed,
            'installed_at' => now(),
            'purchase_id' => $alloc->lot?->purchase_id,
        ]);
    }

    private function returnComponentToStock(EquipmentComponent $component, string $reason, bool $wrap): void
    {
        $run = function () use ($component, $reason) {
            $component = EquipmentComponent::query()->with('currency')->lockForUpdate()->findOrFail($component->id);
            if ($component->status !== EquipmentComponentStatus::Installed) {
                throw new InvalidArgumentException('El componente no está instalado.');
            }

            $product = Product::query()->lockForUpdate()->findOrFail($component->product_id);
            $qty = Money::normalize((string) $component->quantity, 4);
            $currencyCode = $component->currency?->code
                ?? \App\Models\Currency::query()->find($component->currency_id)?->code
                ?? 'USD';

            $this->inventory->returnRecovered($product, [
                'quantity' => $qty,
                'unit_cost' => (string) $component->unit_cost,
                'currency_code' => $currencyCode,
                'exchange_rate_value' => (string) ($component->exchange_rate_value ?? '1'),
                'reason' => 'Recuperación: '.$reason,
                'notes' => 'Equipo #'.$component->equipment_id.' componente #'.$component->id,
                'inventory_serial_id' => $component->inventory_serial_id,
                'purchase_id' => $component->purchase_id,
                'supplier_id' => null,
            ]);

            $component->update([
                'status' => EquipmentComponentStatus::Recovered,
                'removed_at' => now(),
                'removal_reason' => $reason,
            ]);
        };

        $wrap ? DB::transaction($run) : $run();
    }

    private function recalculateTotals(Equipment $equipment): void
    {
        $sumCost = '0';
        $sumArs = '0.00';
        $sumUsd = '0.00';
        foreach ($equipment->components()->where('status', EquipmentComponentStatus::Installed->value)->get() as $c) {
            $sumCost = Money::add($sumCost, (string) $c->total_cost, 6);
            $sumArs = Money::add($sumArs, (string) $c->total_cost_ars);
            $sumUsd = Money::add($sumUsd, (string) $c->total_cost_usd);
        }
        $equipment->update([
            'total_cost' => $sumCost,
            'total_cost_ars' => $sumArs,
            'total_cost_usd' => $sumUsd,
        ]);
    }

    private function logStatus(Equipment $equipment, ?EquipmentStatus $from, EquipmentStatus $to, ?string $reason): void
    {
        EquipmentStatusLog::query()->create([
            'equipment_id' => $equipment->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'user_id' => Auth::id(),
            'reason' => $reason,
        ]);
    }
}
