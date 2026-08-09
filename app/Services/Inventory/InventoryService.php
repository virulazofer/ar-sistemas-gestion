<?php

namespace App\Services\Inventory;

use App\Enums\InventoryLotStatus;
use App\Enums\InventoryMovementType;
use App\Enums\MovementStatus;
use App\Models\Currency;
use App\Models\InventoryLocation;
use App\Models\InventoryLot;
use App\Models\InventoryLotAllocation;
use App\Models\InventoryMovement;
use App\Models\InventorySerial;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class InventoryService
{
    public function __construct(
        private readonly FifoService $fifo,
        private readonly StockBalanceService $balances,
        private readonly SerialInventoryService $serials,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Ingreso desde líneas de compra con product_id (productos físicos).
     * Una sola operación por línea pendiente; no duplica stock.
     *
     * @return list<InventoryMovement>
     */
    public function receiveFromPurchase(Purchase $purchase, bool $wrapTransaction = true): array
    {
        $callback = function () use ($purchase) {
            $purchase = Purchase::query()->with(['items.product', 'supplier'])->lockForUpdate()->findOrFail($purchase->id);
            if (! $purchase->isPosted()) {
                throw new InvalidArgumentException('Solo compras confirmadas pueden ingresar stock.');
            }

            $movements = [];
            foreach ($purchase->items as $item) {
                if (! $item->product_id) {
                    continue;
                }
                $product = Product::query()->lockForUpdate()->find($item->product_id);
                if (! $product || ! $product->tracksStock()) {
                    continue;
                }
                $pending = Money::normalize((string) $item->qty_pending_stock, 4);
                if (Money::compare($pending, '0', 4) <= 0) {
                    continue;
                }

                $movements[] = $this->receivePurchaseItem($purchase, $item, $product, $pending);
            }

            return $movements;
        };

        return $wrapTransaction ? DB::transaction($callback) : $callback();
    }

    /**
     * Ingreso manual (sin compra).
     */
    public function receive(Product $product, array $data): InventoryMovement
    {
        return DB::transaction(function () use ($product, $data) {
            $product = $this->lockPhysicalProduct($product->id);
            $qty = $this->positiveQty($data['quantity'] ?? null);
            $location = $this->resolveLocation($data['inventory_location_id'] ?? $product->inventory_location_id);
            $costs = $this->resolveCosts($data);

            $lot = InventoryLot::query()->create([
                'product_id' => $product->id,
                'inventory_location_id' => $location->id,
                'supplier_id' => $data['supplier_id'] ?? null,
                'purchase_id' => null,
                'purchase_item_id' => null,
                'received_at' => $data['received_at'] ?? now(),
                'qty_received' => $qty,
                'qty_remaining' => $qty,
                'unit_cost' => $costs['unit_cost'],
                'currency_id' => $costs['currency_id'],
                'exchange_rate_id' => $costs['exchange_rate_id'],
                'exchange_rate_value' => $costs['exchange_rate_value'],
                'unit_cost_ars' => $costs['unit_cost_ars'],
                'unit_cost_usd' => $costs['unit_cost_usd'],
                'status' => InventoryLotStatus::Open,
                'notes' => $data['notes'] ?? null,
            ]);

            $movement = $this->createMovement($product, InventoryMovementType::Receipt, [
                'quantity' => $qty,
                'reason' => $data['reason'] ?? 'Ingreso manual',
                'notes' => $data['notes'] ?? null,
                'inventory_location_id' => $location->id,
                'inventory_lot_id' => $lot->id,
                'movement_date' => $data['movement_date'] ?? now()->toDateString(),
                'unit_cost' => $costs['unit_cost'],
                'currency_id' => $costs['currency_id'],
                'exchange_rate_value' => $costs['exchange_rate_value'],
                'total_cost' => Money::normalize(bcmul($qty, $costs['unit_cost'], 10), 6),
                'total_cost_ars' => Money::normalize(bcmul($qty, $costs['unit_cost_ars'], 10), 2),
                'total_cost_usd' => Money::normalize(bcmul($qty, $costs['unit_cost_usd'], 10), 2),
                'force_fail' => ! empty($data['force_fail']),
            ]);

            $this->balances->applyDelta($product, $movement->signed_qty_on_hand, $movement->signed_qty_reserved);

            if ($product->requires_serial) {
                $serialList = $data['serials'] ?? [];
                if (count($serialList) !== (int) round((float) $qty)) {
                    throw new InvalidArgumentException('Productos serializados requieren un serial por unidad ingresada.');
                }
                $this->serials->registerForLot($lot, $product, $serialList, [
                    'supplier_id' => $data['supplier_id'] ?? null,
                    'purchase_id' => $data['purchase_id'] ?? null,
                ]);
            }

            $this->audit->log('inventory_receipt', $movement, null, [
                'product_id' => $product->id,
                'qty' => $qty,
                'lot_id' => $lot->id,
            ], 'Ingreso de stock');

            return $movement->fresh(['lot', 'allocations']);
        });
    }

    public function issue(Product $product, array $data): InventoryMovement
    {
        return $this->fifoOutbound($product, InventoryMovementType::Issue, $data);
    }

    public function consume(Product $product, array $data): InventoryMovement
    {
        if ($product->requires_serial && empty($data['inventory_serial_id']) && empty($data['serial_number']) && empty($data['serials'])) {
            throw new InvalidArgumentException('El producto requiere número de serie para consumirse.');
        }

        return $this->fifoOutbound($product, InventoryMovementType::Consume, $data);
    }

    /**
     * Consumo desde un lote concreto (FIFO+serial). Usado por armado de equipos.
     */
    public function consumeFromLot(Product $product, InventoryLot $lot, string $quantity, array $data = []): InventoryMovement
    {
        $wrap = $data['wrap_transaction'] ?? true;
        $callback = function () use ($product, $lot, $quantity, $data) {
            $product = $this->lockPhysicalProduct($product->id);
            $lot = InventoryLot::query()->lockForUpdate()->findOrFail($lot->id);
            if ((int) $lot->product_id !== (int) $product->id) {
                throw new InvalidArgumentException('El lote no pertenece al producto.');
            }

            $qty = $this->positiveQty($quantity);
            $this->assertEnoughAvailable($product, $qty);

            if (Money::compare($qty, (string) $lot->qty_remaining, 4) > 0) {
                throw new InvalidArgumentException('El lote no tiene cantidad suficiente.');
            }

            $serial = null;
            if ($product->requires_serial) {
                if (! empty($data['inventory_serial_id'])) {
                    $serial = InventorySerial::query()->lockForUpdate()->findOrFail($data['inventory_serial_id']);
                } elseif (! empty($data['serial_number'])) {
                    $serial = $this->serials->findAvailable($product, (string) $data['serial_number']);
                    $serial = InventorySerial::query()->lockForUpdate()->findOrFail($serial->id);
                } else {
                    throw new InvalidArgumentException('Se requiere serial para este producto.');
                }
                if ((int) $serial->product_id !== (int) $product->id) {
                    throw new InvalidArgumentException('El serial no pertenece al producto.');
                }
                if ((int) $serial->inventory_lot_id !== (int) $lot->id) {
                    throw new InvalidArgumentException('El serial no pertenece al lote indicado.');
                }
                if (! $serial->isAvailable()) {
                    throw new InvalidArgumentException('Serial no disponible: '.$serial->serial_number);
                }
                if (Money::compare($qty, '1', 4) !== 0) {
                    throw new InvalidArgumentException('Consumo serializado: cantidad debe ser 1.');
                }
            }

            $lineCost = Money::normalize(bcmul($qty, (string) $lot->unit_cost, 10), 6);
            $lineArs = Money::normalize(bcmul($qty, (string) $lot->unit_cost_ars, 10), 2);
            $lineUsd = Money::normalize(bcmul($qty, (string) $lot->unit_cost_usd, 10), 2);

            $type = InventoryMovementType::Consume;
            $movement = $this->createMovement($product, $type, [
                'quantity' => $qty,
                'reason' => $data['reason'] ?? 'Consumo de lote',
                'notes' => $data['notes'] ?? null,
                'inventory_location_id' => $lot->inventory_location_id,
                'inventory_lot_id' => $lot->id,
                'work_order_id' => $data['work_order_id'] ?? null,
                'sale_id' => $data['sale_id'] ?? null,
                'movement_date' => $data['movement_date'] ?? now()->toDateString(),
                'unit_cost' => Money::normalize((string) $lot->unit_cost, 6),
                'currency_id' => $lot->currency_id,
                'exchange_rate_value' => $lot->exchange_rate_value,
                'total_cost' => $lineCost,
                'total_cost_ars' => $lineArs,
                'total_cost_usd' => $lineUsd,
                'force_fail' => ! empty($data['force_fail']),
            ]);

            $remaining = Money::sub((string) $lot->qty_remaining, $qty, 4);
            $lot->update([
                'qty_remaining' => $remaining,
                'status' => Money::compare($remaining, '0', 4) === 0
                    ? InventoryLotStatus::Depleted
                    : InventoryLotStatus::Open,
            ]);

            $allocation = InventoryLotAllocation::query()->create([
                'inventory_movement_id' => $movement->id,
                'inventory_lot_id' => $lot->id,
                'quantity' => $qty,
                'unit_cost' => Money::normalize((string) $lot->unit_cost, 6),
                'currency_id' => $lot->currency_id,
                'exchange_rate_value' => $lot->exchange_rate_value,
                'unit_cost_ars' => Money::normalize((string) $lot->unit_cost_ars, 6),
                'unit_cost_usd' => Money::normalize((string) $lot->unit_cost_usd, 6),
                'total_cost' => $lineCost,
                'total_cost_ars' => $lineArs,
                'total_cost_usd' => $lineUsd,
            ]);

            if ($serial) {
                $this->serials->markConsumed($serial);
            }

            $this->balances->applyDelta($product, $movement->signed_qty_on_hand, '0');
            $this->audit->log('inventory_consume_lot', $movement, null, [
                'product_id' => $product->id,
                'lot_id' => $lot->id,
                'serial_id' => $serial?->id,
                'qty' => $qty,
                'total_cost_usd' => $lineUsd,
            ], 'Consumo desde lote');

            if (! empty($data['force_fail_after'])) {
                throw new RuntimeException('Falla simulada después de consumo de lote.');
            }

            $movement->setRelation('allocations', collect([$allocation->fresh('lot')]));

            return $movement->fresh(['allocations.lot']);
        };

        return $wrap ? DB::transaction($callback) : $callback();
    }

    /**
     * Devuelve unidades al stock con costo histórico (desarmado/reemplazo).
     * Si hay serial, lo reactiva sobre el nuevo lote (sin duplicar el número).
     */
    public function returnRecovered(Product $product, array $data): InventoryMovement
    {
        return DB::transaction(function () use ($product, $data) {
            $product = $this->lockPhysicalProduct($product->id);
            $qty = $this->positiveQty($data['quantity'] ?? null);
            $location = $this->resolveLocation($data['inventory_location_id'] ?? $product->inventory_location_id);
            $costs = $this->resolveCosts($data);

            $lot = InventoryLot::query()->create([
                'product_id' => $product->id,
                'inventory_location_id' => $location->id,
                'supplier_id' => $data['supplier_id'] ?? null,
                'purchase_id' => $data['purchase_id'] ?? null,
                'purchase_item_id' => null,
                'received_at' => $data['received_at'] ?? now(),
                'qty_received' => $qty,
                'qty_remaining' => $qty,
                'unit_cost' => $costs['unit_cost'],
                'currency_id' => $costs['currency_id'],
                'exchange_rate_id' => $costs['exchange_rate_id'],
                'exchange_rate_value' => $costs['exchange_rate_value'],
                'unit_cost_ars' => $costs['unit_cost_ars'],
                'unit_cost_usd' => $costs['unit_cost_usd'],
                'status' => InventoryLotStatus::Open,
                'notes' => $data['notes'] ?? 'Recuperación a stock',
            ]);

            $movement = $this->createMovement($product, InventoryMovementType::Receipt, [
                'quantity' => $qty,
                'reason' => $data['reason'] ?? 'Recuperación a stock',
                'notes' => $data['notes'] ?? null,
                'inventory_location_id' => $location->id,
                'inventory_lot_id' => $lot->id,
                'movement_date' => $data['movement_date'] ?? now()->toDateString(),
                'unit_cost' => $costs['unit_cost'],
                'currency_id' => $costs['currency_id'],
                'exchange_rate_value' => $costs['exchange_rate_value'],
                'total_cost' => Money::normalize(bcmul($qty, $costs['unit_cost'], 10), 6),
                'total_cost_ars' => Money::normalize(bcmul($qty, $costs['unit_cost_ars'], 10), 2),
                'total_cost_usd' => Money::normalize(bcmul($qty, $costs['unit_cost_usd'], 10), 2),
            ]);

            if (! empty($data['inventory_serial_id'])) {
                $serial = InventorySerial::query()->lockForUpdate()->findOrFail($data['inventory_serial_id']);
                if ((int) $serial->product_id !== (int) $product->id) {
                    throw new InvalidArgumentException('Serial no pertenece al producto.');
                }
                $this->serials->markAvailable($serial, $lot->id);
            }

            $this->balances->applyDelta($product, $movement->signed_qty_on_hand, '0');
            $this->audit->log('inventory_return_recovered', $movement, null, [
                'product_id' => $product->id,
                'lot_id' => $lot->id,
                'serial_id' => $data['inventory_serial_id'] ?? null,
            ], 'Devolución recuperada a stock');

            return $movement->fresh(['lot']);
        });
    }

    public function adjustIn(Product $product, array $data): InventoryMovement
    {
        $data['reason'] = $data['reason'] ?? throw new InvalidArgumentException('El ajuste requiere motivo.');

        return DB::transaction(function () use ($product, $data) {
            $product = $this->lockPhysicalProduct($product->id);
            $qty = $this->positiveQty($data['quantity'] ?? null);
            $location = $this->resolveLocation($data['inventory_location_id'] ?? $product->inventory_location_id);
            $costs = $this->resolveCosts(array_merge($data, [
                'unit_cost' => $data['unit_cost'] ?? '0',
                'currency_code' => $data['currency_code'] ?? 'ARS',
            ]));

            $lot = InventoryLot::query()->create([
                'product_id' => $product->id,
                'inventory_location_id' => $location->id,
                'received_at' => $data['received_at'] ?? now(),
                'qty_received' => $qty,
                'qty_remaining' => $qty,
                'unit_cost' => $costs['unit_cost'],
                'currency_id' => $costs['currency_id'],
                'exchange_rate_id' => $costs['exchange_rate_id'],
                'exchange_rate_value' => $costs['exchange_rate_value'],
                'unit_cost_ars' => $costs['unit_cost_ars'],
                'unit_cost_usd' => $costs['unit_cost_usd'],
                'status' => InventoryLotStatus::Open,
                'notes' => 'Ajuste positivo: '.$data['reason'],
            ]);

            $movement = $this->createMovement($product, InventoryMovementType::AdjustmentIn, [
                'quantity' => $qty,
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'inventory_location_id' => $location->id,
                'inventory_lot_id' => $lot->id,
                'movement_date' => $data['movement_date'] ?? now()->toDateString(),
                'unit_cost' => $costs['unit_cost'],
                'currency_id' => $costs['currency_id'],
                'exchange_rate_value' => $costs['exchange_rate_value'],
                'total_cost' => Money::normalize(bcmul($qty, $costs['unit_cost'], 10), 6),
                'total_cost_ars' => Money::normalize(bcmul($qty, $costs['unit_cost_ars'], 10), 2),
                'total_cost_usd' => Money::normalize(bcmul($qty, $costs['unit_cost_usd'], 10), 2),
            ]);

            $this->balances->applyDelta($product, $movement->signed_qty_on_hand, '0');
            $this->audit->log('inventory_adjustment_in', $movement, null, [
                'product_id' => $product->id,
                'qty' => $qty,
                'reason' => $data['reason'],
            ], 'Ajuste positivo de stock');

            return $movement->fresh(['lot']);
        });
    }

    public function adjustOut(Product $product, array $data): InventoryMovement
    {
        $data['reason'] = trim((string) ($data['reason'] ?? ''));
        if ($data['reason'] === '') {
            throw new InvalidArgumentException('El ajuste requiere motivo.');
        }

        return $this->fifoOutbound($product, InventoryMovementType::AdjustmentOut, $data);
    }

    public function reserve(Product $product, array $data): InventoryMovement
    {
        return DB::transaction(function () use ($product, $data) {
            $product = $this->lockPhysicalProduct($product->id);
            $qty = $this->positiveQty($data['quantity'] ?? null);
            $available = $product->qtyAvailable();
            if (Money::compare($qty, $available, 4) > 0) {
                throw new InvalidArgumentException('No se puede reservar más que el disponible ('.$available.').');
            }

            $movement = $this->createMovement($product, InventoryMovementType::Reserve, [
                'quantity' => $qty,
                'reason' => $data['reason'] ?? 'Reserva',
                'notes' => $data['notes'] ?? null,
                'inventory_location_id' => $product->inventory_location_id,
                'movement_date' => $data['movement_date'] ?? now()->toDateString(),
            ]);

            $this->balances->applyDelta($product, '0', $movement->signed_qty_reserved);
            $this->audit->log('inventory_reserve', $movement, null, [
                'product_id' => $product->id,
                'qty' => $qty,
            ], 'Reserva de stock');

            return $movement;
        });
    }

    public function release(Product $product, array $data): InventoryMovement
    {
        return DB::transaction(function () use ($product, $data) {
            $product = $this->lockPhysicalProduct($product->id);
            $qty = $this->positiveQty($data['quantity'] ?? null);
            if (Money::compare($qty, (string) $product->qty_reserved, 4) > 0) {
                throw new InvalidArgumentException('No se puede liberar más que lo reservado ('.$product->qty_reserved.').');
            }

            $movement = $this->createMovement($product, InventoryMovementType::Release, [
                'quantity' => $qty,
                'reason' => $data['reason'] ?? 'Liberación de reserva',
                'notes' => $data['notes'] ?? null,
                'inventory_location_id' => $product->inventory_location_id,
                'movement_date' => $data['movement_date'] ?? now()->toDateString(),
            ]);

            $this->balances->applyDelta($product, '0', $movement->signed_qty_reserved);
            $this->audit->log('inventory_release', $movement, null, [
                'product_id' => $product->id,
                'qty' => $qty,
            ], 'Liberación de reserva');

            return $movement;
        });
    }

    /**
     * Transferencia entre ubicaciones: FIFO out + lote nuevo in (mismo costo histórico).
     *
     * @return array{out: InventoryMovement, in: InventoryMovement}
     */
    public function transfer(Product $product, array $data): array
    {
        return DB::transaction(function () use ($product, $data) {
            $product = $this->lockPhysicalProduct($product->id);
            $qty = $this->positiveQty($data['quantity'] ?? null);
            $fromId = (int) ($data['inventory_location_id'] ?? $product->inventory_location_id);
            $toId = (int) ($data['inventory_location_to_id'] ?? 0);
            if ($toId <= 0 || $toId === $fromId) {
                throw new InvalidArgumentException('La ubicación destino debe ser distinta.');
            }
            $to = InventoryLocation::query()->where('is_active', true)->findOrFail($toId);
            $group = (string) Str::uuid();

            $out = $this->fifoOutbound($product, InventoryMovementType::TransferOut, [
                'quantity' => $qty,
                'reason' => $data['reason'] ?? 'Transferencia',
                'notes' => $data['notes'] ?? null,
                'inventory_location_id' => $fromId,
                'inventory_location_to_id' => $to->id,
                'transfer_group_id' => $group,
                'movement_date' => $data['movement_date'] ?? now()->toDateString(),
                'wrap_transaction' => false,
            ]);

            // Recrear lotes en destino preservando costo de cada allocation
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            $firstLotId = null;
            $totalCost = '0';
            $totalArs = '0.00';
            $totalUsd = '0.00';
            $currencyId = null;
            $rate = null;
            $unitCost = null;

            foreach ($out->allocations as $alloc) {
                $lot = InventoryLot::query()->create([
                    'product_id' => $product->id,
                    'inventory_location_id' => $to->id,
                    'supplier_id' => $alloc->lot->supplier_id,
                    'purchase_id' => $alloc->lot->purchase_id,
                    'purchase_item_id' => $alloc->lot->purchase_item_id,
                    'received_at' => $alloc->lot->received_at,
                    'qty_received' => $alloc->quantity,
                    'qty_remaining' => $alloc->quantity,
                    'unit_cost' => $alloc->unit_cost,
                    'currency_id' => $alloc->currency_id,
                    'exchange_rate_id' => $alloc->lot->exchange_rate_id,
                    'exchange_rate_value' => $alloc->exchange_rate_value,
                    'unit_cost_ars' => $alloc->unit_cost_ars,
                    'unit_cost_usd' => $alloc->unit_cost_usd,
                    'status' => InventoryLotStatus::Open,
                    'notes' => 'Transferencia desde lote #'.$alloc->inventory_lot_id,
                ]);
                $firstLotId ??= $lot->id;
                $totalCost = Money::add($totalCost, (string) $alloc->total_cost, 6);
                $totalArs = Money::add($totalArs, (string) $alloc->total_cost_ars);
                $totalUsd = Money::add($totalUsd, (string) $alloc->total_cost_usd);
                $currencyId = $alloc->currency_id;
                $rate = $alloc->exchange_rate_value;
                $unitCost = $alloc->unit_cost;
            }

            $in = $this->createMovement($product, InventoryMovementType::TransferIn, [
                'quantity' => $qty,
                'reason' => $data['reason'] ?? 'Transferencia',
                'notes' => $data['notes'] ?? null,
                'inventory_location_id' => $to->id,
                'inventory_location_to_id' => null,
                'inventory_lot_id' => $firstLotId,
                'transfer_group_id' => $group,
                'movement_date' => $data['movement_date'] ?? now()->toDateString(),
                'unit_cost' => $unitCost,
                'currency_id' => $currencyId,
                'exchange_rate_value' => $rate,
                'total_cost' => $totalCost,
                'total_cost_ars' => $totalArs,
                'total_cost_usd' => $totalUsd,
            ]);

            $this->balances->applyDelta($product, $in->signed_qty_on_hand, '0');
            $product->update(['inventory_location_id' => $to->id]);

            $this->audit->log('inventory_transfer', $out, null, [
                'product_id' => $product->id,
                'qty' => $qty,
                'from' => $fromId,
                'to' => $to->id,
                'group' => $group,
            ], 'Transferencia de stock');

            return ['out' => $out->fresh(['allocations']), 'in' => $in];
        });
    }

    /**
     * Anula un movimiento posted. Bloquea si dejaría lotes/stock inconsistentes
     * (p.ej. lote de ingreso ya parcialmente consumido).
     */
    public function void(InventoryMovement $movement, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('La anulación requiere motivo.');
        }

        DB::transaction(function () use ($movement, $reason) {
            $movement = InventoryMovement::query()->with('allocations.lot')->lockForUpdate()->findOrFail($movement->id);
            if (! $movement->isPosted()) {
                throw new InvalidArgumentException('El movimiento ya está anulado.');
            }

            $product = Product::query()->lockForUpdate()->findOrFail($movement->product_id);
            $type = $movement->type;

            if ($type->createsLot() && $movement->inventory_lot_id) {
                $lot = InventoryLot::query()->lockForUpdate()->findOrFail($movement->inventory_lot_id);
                if (Money::compare((string) $lot->qty_remaining, (string) $lot->qty_received, 4) !== 0) {
                    throw new InvalidArgumentException('No se puede anular: el lote de ingreso ya fue consumido parcialmente.');
                }
                // Si es transfer_in con varios lotes, verificar todos los creados en el grupo es complejo;
                // para receipt/adjustment_in un lote basta.
                $lot->update([
                    'qty_remaining' => '0',
                    'status' => InventoryLotStatus::Voided,
                ]);
            }

            if ($type->consumesLots()) {
                foreach ($movement->allocations as $alloc) {
                    $lot = InventoryLot::query()->lockForUpdate()->findOrFail($alloc->inventory_lot_id);
                    if ($lot->status === InventoryLotStatus::Voided) {
                        throw new InvalidArgumentException('No se puede anular: lote origen anulado.');
                    }
                    $restored = Money::add((string) $lot->qty_remaining, (string) $alloc->quantity, 4);
                    $lot->update([
                        'qty_remaining' => $restored,
                        'status' => InventoryLotStatus::Open,
                    ]);
                }
            }

            // Revertir deltas en cache
            $revOnHand = Money::mul((string) $movement->signed_qty_on_hand, '-1', 4);
            $revReserved = Money::mul((string) $movement->signed_qty_reserved, '-1', 4);
            $this->assertNonNegativeAfter($product, $revOnHand, $revReserved);
            $this->balances->applyDelta($product, $revOnHand, $revReserved);

            if ($movement->purchase_item_id && $type === InventoryMovementType::Receipt) {
                $item = PurchaseItem::query()->lockForUpdate()->find($movement->purchase_item_id);
                if ($item) {
                    $item->update([
                        'qty_pending_stock' => Money::add((string) $item->qty_pending_stock, (string) $movement->quantity, 4),
                    ]);
                }
            }

            $movement->update([
                'status' => MovementStatus::Voided,
                'void_reason' => $reason,
                'voided_by' => Auth::id(),
                'voided_at' => now(),
            ]);

            $this->audit->log('inventory_movement_voided', $movement, ['status' => 'posted'], [
                'status' => 'voided',
                'reason' => $reason,
            ], 'Movimiento de inventario anulado');
        });
    }

    private function receivePurchaseItem(Purchase $purchase, PurchaseItem $item, Product $product, string $qty): InventoryMovement
    {
        $location = $this->resolveLocation($product->inventory_location_id);
        $unitCost = Money::normalize((string) $item->unit_price, 6);
        $rate = Money::normalize((string) ($item->exchange_rate_value ?? $purchase->exchange_rate_value ?? '0'), 6);

        $lot = InventoryLot::query()->create([
            'product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'supplier_id' => $purchase->supplier_id,
            'purchase_id' => $purchase->id,
            'purchase_item_id' => $item->id,
            'received_at' => $purchase->purchase_date->copy()->startOfDay()->setTimeFrom(now()),
            'qty_received' => $qty,
            'qty_remaining' => $qty,
            'unit_cost' => $unitCost,
            'currency_id' => $item->currency_id,
            'exchange_rate_id' => $purchase->exchange_rate_id,
            'exchange_rate_value' => $rate,
            'unit_cost_ars' => Money::normalize((string) $item->unit_cost_ars, 6),
            'unit_cost_usd' => Money::normalize((string) $item->unit_cost_usd, 6),
            'status' => InventoryLotStatus::Open,
            'notes' => 'Compra #'.$purchase->id,
        ]);

        $movement = $this->createMovement($product, InventoryMovementType::Receipt, [
            'quantity' => $qty,
            'reason' => 'Ingreso por compra #'.$purchase->id,
            'inventory_location_id' => $location->id,
            'purchase_id' => $purchase->id,
            'purchase_item_id' => $item->id,
            'inventory_lot_id' => $lot->id,
            'movement_date' => $purchase->purchase_date->toDateString(),
            'unit_cost' => $unitCost,
            'currency_id' => $item->currency_id,
            'exchange_rate_value' => $rate,
            'total_cost' => Money::normalize(bcmul($qty, $unitCost, 10), 6),
            'total_cost_ars' => Money::normalize((string) $item->line_total_ars),
            'total_cost_usd' => Money::normalize((string) $item->line_total_usd),
        ]);

        $item->update([
            'qty_pending_stock' => Money::sub((string) $item->qty_pending_stock, $qty, 4),
        ]);

        $this->balances->applyDelta($product, $movement->signed_qty_on_hand, '0');
        $this->audit->log('inventory_receipt_purchase', $movement, null, [
            'purchase_id' => $purchase->id,
            'purchase_item_id' => $item->id,
            'product_id' => $product->id,
            'lot_id' => $lot->id,
            'qty' => $qty,
        ], 'Ingreso de stock desde compra');

        return $movement;
    }

    private function fifoOutbound(Product $product, InventoryMovementType $type, array $data): InventoryMovement
    {
        $wrap = $data['wrap_transaction'] ?? true;
        $callback = function () use ($product, $type, $data) {
            $product = $this->lockPhysicalProduct($product->id);
            $qty = $this->positiveQty($data['quantity'] ?? null);
            $this->assertEnoughAvailable($product, $qty);

            $locationId = (int) ($data['inventory_location_id'] ?? $product->inventory_location_id);
            $plan = $this->fifo->planConsumption($product->id, $qty, $locationId > 0 ? $locationId : null);

            $movement = $this->createMovement($product, $type, [
                'quantity' => $qty,
                'reason' => $data['reason'] ?? $type->label(),
                'notes' => $data['notes'] ?? null,
                'inventory_location_id' => $locationId ?: null,
                'inventory_location_to_id' => $data['inventory_location_to_id'] ?? null,
                'transfer_group_id' => $data['transfer_group_id'] ?? null,
                'movement_date' => $data['movement_date'] ?? now()->toDateString(),
                'work_order_id' => $data['work_order_id'] ?? null,
                'sale_id' => $data['sale_id'] ?? null,
                'unit_cost' => $plan['avg_unit_cost'],
                'currency_id' => $plan['currency_id'],
                'exchange_rate_value' => $plan['exchange_rate_value'],
                'total_cost' => $plan['total_cost'],
                'total_cost_ars' => $plan['total_cost_ars'],
                'total_cost_usd' => $plan['total_cost_usd'],
                'force_fail' => ! empty($data['force_fail']),
            ]);

            foreach ($plan['allocations'] as $row) {
                $lot = InventoryLot::query()->lockForUpdate()->findOrFail($row['lot_id']);
                $remaining = Money::sub((string) $lot->qty_remaining, $row['quantity'], 4);
                if (Money::compare($remaining, '0', 4) < 0) {
                    throw new RuntimeException('Concurrencia: el lote ya no tiene stock suficiente.');
                }
                $lot->update([
                    'qty_remaining' => $remaining,
                    'status' => Money::compare($remaining, '0', 4) === 0
                        ? InventoryLotStatus::Depleted
                        : InventoryLotStatus::Open,
                ]);

                InventoryLotAllocation::query()->create([
                    'inventory_movement_id' => $movement->id,
                    'inventory_lot_id' => $lot->id,
                    'quantity' => $row['quantity'],
                    'unit_cost' => $row['unit_cost'],
                    'currency_id' => $row['currency_id'],
                    'exchange_rate_value' => $row['exchange_rate_value'],
                    'unit_cost_ars' => $row['unit_cost_ars'],
                    'unit_cost_usd' => $row['unit_cost_usd'],
                    'total_cost' => $row['total_cost'],
                    'total_cost_ars' => $row['total_cost_ars'],
                    'total_cost_usd' => $row['total_cost_usd'],
                ]);
            }

            $this->balances->applyDelta($product, $movement->signed_qty_on_hand, '0');
            $this->audit->log('inventory_'.$type->value, $movement, null, [
                'product_id' => $product->id,
                'qty' => $qty,
                'total_cost' => $plan['total_cost'],
                'total_cost_usd' => $plan['total_cost_usd'],
            ], $type->label());

            if (! empty($data['force_fail_after'])) {
                throw new RuntimeException('Falla simulada después de salida FIFO.');
            }

            return $movement->fresh(['allocations.lot']);
        };

        return $wrap ? DB::transaction($callback) : $callback();
    }

    private function createMovement(Product $product, InventoryMovementType $type, array $data): InventoryMovement
    {
        $qty = Money::normalize((string) $data['quantity'], 4);
        $onHand = Money::mul($qty, (string) $type->onHandSign(), 4);
        $reserved = Money::mul($qty, (string) $type->reservedSign(), 4);

        $movement = InventoryMovement::query()->create([
            'transfer_group_id' => $data['transfer_group_id'] ?? null,
            'product_id' => $product->id,
            'type' => $type,
            'quantity' => $qty,
            'signed_qty_on_hand' => $onHand,
            'signed_qty_reserved' => $reserved,
            'movement_date' => $data['movement_date'] ?? now()->toDateString(),
            'movement_time' => $data['movement_time'] ?? now()->format('H:i:s'),
            'user_id' => Auth::id() ?? throw new RuntimeException('Usuario requerido.'),
            'reason' => $data['reason'] ?? null,
            'notes' => $data['notes'] ?? null,
            'inventory_location_id' => $data['inventory_location_id'] ?? null,
            'inventory_location_to_id' => $data['inventory_location_to_id'] ?? null,
            'purchase_id' => $data['purchase_id'] ?? null,
            'purchase_item_id' => $data['purchase_item_id'] ?? null,
            'work_order_id' => $data['work_order_id'] ?? null,
            'sale_id' => $data['sale_id'] ?? null,
            'inventory_lot_id' => $data['inventory_lot_id'] ?? null,
            'unit_cost' => $data['unit_cost'] ?? null,
            'currency_id' => $data['currency_id'] ?? null,
            'exchange_rate_value' => $data['exchange_rate_value'] ?? null,
            'total_cost' => $data['total_cost'] ?? null,
            'total_cost_ars' => $data['total_cost_ars'] ?? null,
            'total_cost_usd' => $data['total_cost_usd'] ?? null,
            'status' => MovementStatus::Posted,
        ]);

        if (! empty($data['force_fail'])) {
            throw new RuntimeException('Falla simulada en movimiento de inventario.');
        }

        return $movement;
    }

    private function lockPhysicalProduct(int $id): Product
    {
        $product = Product::query()->lockForUpdate()->findOrFail($id);
        if (! $product->tracksStock()) {
            throw new InvalidArgumentException('Los servicios no manejan stock físico.');
        }
        if (! $product->isActive()) {
            throw new InvalidArgumentException('El producto no está activo.');
        }

        return $product;
    }

    private function positiveQty(mixed $qty): string
    {
        $normalized = Money::normalize((string) ($qty ?? '0'), 4);
        if (Money::compare($normalized, '0', 4) <= 0) {
            throw new InvalidArgumentException('La cantidad debe ser mayor a cero.');
        }

        return $normalized;
    }

    private function assertEnoughAvailable(Product $product, string $qty): void
    {
        $available = $product->qtyAvailable();
        if (Money::compare($qty, $available, 4) <= 0) {
            return;
        }

        $allowNegative = (bool) Setting::getValue('stock.allow_negative', false);
        if ($allowNegative) {
            return;
        }

        throw new InvalidArgumentException('Stock insuficiente. Disponible: '.$available.'.');
    }

    private function assertNonNegativeAfter(Product $product, string $deltaOnHand, string $deltaReserved): void
    {
        $newOnHand = Money::add((string) $product->qty_on_hand, $deltaOnHand, 4);
        $newReserved = Money::add((string) $product->qty_reserved, $deltaReserved, 4);
        if (Money::compare($newOnHand, '0', 4) < 0 || Money::compare($newReserved, '0', 4) < 0) {
            throw new InvalidArgumentException('La anulación dejaría stock inconsistente.');
        }
        if (Money::compare($newReserved, $newOnHand, 4) > 0) {
            throw new InvalidArgumentException('La anulación dejaría reservas mayores al stock.');
        }
    }

    private function resolveLocation(?int $locationId): InventoryLocation
    {
        if ($locationId) {
            return InventoryLocation::query()->where('is_active', true)->findOrFail($locationId);
        }

        return InventoryLocation::defaultLocation();
    }

    /**
     * @return array{unit_cost: string, currency_id: int, exchange_rate_id: ?int, exchange_rate_value: string, unit_cost_ars: string, unit_cost_usd: string}
     */
    private function resolveCosts(array $data): array
    {
        $unitCost = Money::normalize((string) ($data['unit_cost'] ?? '0'), 6);
        $code = strtoupper((string) ($data['currency_code'] ?? 'ARS'));
        $currency = Currency::query()->where('code', $code)->firstOrFail();
        $rate = Money::normalize((string) ($data['exchange_rate_value'] ?? '1'), 6);
        if ($code === 'ARS') {
            $ars = $unitCost;
            $usd = Money::compare($rate, '0', 6) > 0 ? Money::normalize(bcdiv($unitCost, $rate, 10), 6) : '0.000000';
        } else {
            $usd = $unitCost;
            $ars = Money::normalize(bcmul($unitCost, $rate, 10), 6);
        }

        return [
            'unit_cost' => $unitCost,
            'currency_id' => $currency->id,
            'exchange_rate_id' => $data['exchange_rate_id'] ?? null,
            'exchange_rate_value' => $rate,
            'unit_cost_ars' => $ars,
            'unit_cost_usd' => $usd,
        ];
    }
}
