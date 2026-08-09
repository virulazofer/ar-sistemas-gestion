<?php

namespace App\Services\Inventory;

use App\Enums\InventoryLotStatus;
use App\Enums\InventoryMovementType;
use App\Enums\MovementStatus;
use App\Models\InventoryLot;
use App\Models\InventoryLotAllocation;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cache denormalizado de stock + valuación FIFO + reconstrucción desde movimientos.
 */
class StockBalanceService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function applyDelta(Product $product, string $deltaOnHand, string $deltaReserved): void
    {
        $onHand = Money::add((string) $product->qty_on_hand, Money::normalize($deltaOnHand, 4), 4);
        $reserved = Money::add((string) $product->qty_reserved, Money::normalize($deltaReserved, 4), 4);

        if (Money::compare($onHand, '0', 4) < 0) {
            throw new InvalidArgumentException('Stock resultante negativo no permitido.');
        }
        if (Money::compare($reserved, '0', 4) < 0) {
            throw new InvalidArgumentException('Reservas resultantes negativas no permitidas.');
        }
        if (Money::compare($reserved, $onHand, 4) > 0) {
            throw new InvalidArgumentException('Reservado no puede superar stock actual.');
        }

        $product->update([
            'qty_on_hand' => $onHand,
            'qty_reserved' => $reserved,
        ]);
    }

    /**
     * @return array{qty_on_hand: string, qty_reserved: string, qty_available: string}
     */
    public function snapshot(Product $product): array
    {
        $onHand = Money::normalize((string) $product->qty_on_hand, 4);
        $reserved = Money::normalize((string) $product->qty_reserved, 4);

        return [
            'qty_on_hand' => $onHand,
            'qty_reserved' => $reserved,
            'qty_available' => Money::sub($onHand, $reserved, 4),
        ];
    }

    /**
     * Valor de existencia según lotes abiertos (costo histórico FIFO, no cotización actual).
     *
     * @return array{qty: string, value_ars: string, value_usd: string, lots: int}
     */
    public function inventoryValue(?int $productId = null): array
    {
        $query = InventoryLot::query()
            ->where('status', InventoryLotStatus::Open->value)
            ->where('qty_remaining', '>', 0);

        if ($productId) {
            $query->where('product_id', $productId);
        }

        $qty = '0';
        $ars = '0.00';
        $usd = '0.00';
        $count = 0;

        foreach ($query->get() as $lot) {
            $count++;
            $q = Money::normalize((string) $lot->qty_remaining, 4);
            $qty = Money::add($qty, $q, 4);
            $ars = Money::add($ars, Money::normalize(bcmul($q, (string) $lot->unit_cost_ars, 10), 2));
            $usd = Money::add($usd, Money::normalize(bcmul($q, (string) $lot->unit_cost_usd, 10), 2));
        }

        return [
            'qty' => $qty,
            'value_ars' => $ars,
            'value_usd' => $usd,
            'lots' => $count,
        ];
    }

    /**
     * Reconstruye qty_on_hand / qty_reserved desde movimientos posted.
     * También recalcula qty_remaining de lotes no voided:
     * qty_received - sum(allocations de movimientos posted de consumo).
     */
    public function rebuildProduct(Product $product): array
    {
        return DB::transaction(function () use ($product) {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);

            $onHand = '0';
            $reserved = '0';

            $movements = InventoryMovement::query()
                ->posted()
                ->where('product_id', $product->id)
                ->orderBy('movement_date')
                ->orderBy('id')
                ->get();

            foreach ($movements as $movement) {
                $onHand = Money::add($onHand, (string) $movement->signed_qty_on_hand, 4);
                $reserved = Money::add($reserved, (string) $movement->signed_qty_reserved, 4);
            }

            if (Money::compare($onHand, '0', 4) < 0) {
                $onHand = '0.0000';
            }
            if (Money::compare($reserved, '0', 4) < 0) {
                $reserved = '0.0000';
            }
            if (Money::compare($reserved, $onHand, 4) > 0) {
                $reserved = $onHand;
            }

            $before = $product->only(['qty_on_hand', 'qty_reserved']);
            $product->update([
                'qty_on_hand' => $onHand,
                'qty_reserved' => $reserved,
            ]);

            $lotsRebuilt = 0;
            $lots = InventoryLot::query()->where('product_id', $product->id)->lockForUpdate()->get();
            foreach ($lots as $lot) {
                if ($lot->status === InventoryLotStatus::Voided) {
                    $lot->update(['qty_remaining' => '0.0000']);
                    $lotsRebuilt++;

                    continue;
                }

                $consumed = InventoryLotAllocation::query()
                    ->where('inventory_lot_id', $lot->id)
                    ->whereHas('movement', fn ($q) => $q->where('status', MovementStatus::Posted->value))
                    ->sum('quantity');

                $remaining = Money::sub((string) $lot->qty_received, Money::normalize((string) $consumed, 4), 4);
                if (Money::compare($remaining, '0', 4) < 0) {
                    $remaining = '0.0000';
                }

                $status = Money::compare($remaining, '0', 4) === 0
                    ? InventoryLotStatus::Depleted
                    : InventoryLotStatus::Open;

                $lot->update([
                    'qty_remaining' => $remaining,
                    'status' => $status,
                ]);
                $lotsRebuilt++;
            }

            $this->audit->log('stock_rebuilt', $product, $before, [
                'qty_on_hand' => $onHand,
                'qty_reserved' => $reserved,
                'lots' => $lotsRebuilt,
            ], 'Reconstrucción de stock desde movimientos');

            return [
                'qty_on_hand' => $onHand,
                'qty_reserved' => $reserved,
                'qty_available' => Money::sub($onHand, $reserved, 4),
                'lots_rebuilt' => $lotsRebuilt,
                'movements' => $movements->count(),
            ];
        });
    }

    public function rebuildAll(): int
    {
        $count = 0;
        Product::query()->where('type', 'physical')->orderBy('id')->chunkById(50, function ($products) use (&$count) {
            foreach ($products as $product) {
                $this->rebuildProduct($product);
                $count++;
            }
        });

        return $count;
    }

    /**
     * Costo FIFO de una salida hipotética (sin persistir).
     */
    public function estimateFifoCost(Product $product, string $quantity): array
    {
        return app(FifoService::class)->planConsumption($product->id, Money::normalize($quantity, 4));
    }
}
