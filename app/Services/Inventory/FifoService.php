<?php

namespace App\Services\Inventory;

use App\Enums\InventoryLotStatus;
use App\Models\InventoryLot;
use App\Support\Money;
use InvalidArgumentException;
use RuntimeException;

/**
 * Planifica y aplica consumo FIFO con bloqueo de lotes.
 * Orden: received_at ASC, id ASC.
 */
class FifoService
{
    /**
     * @return array{
     *   allocations: list<array{
     *     lot_id: int,
     *     quantity: string,
     *     unit_cost: string,
     *     currency_id: int,
     *     exchange_rate_value: ?string,
     *     unit_cost_ars: string,
     *     unit_cost_usd: string,
     *     total_cost: string,
     *     total_cost_ars: string,
     *     total_cost_usd: string
     *   }>,
     *   total_cost: string,
     *   total_cost_ars: string,
     *   total_cost_usd: string,
     *   avg_unit_cost: string,
     *   currency_id: ?int,
     *   exchange_rate_value: ?string
     * }
     */
    public function planConsumption(int $productId, string $quantity, ?int $locationId = null): array
    {
        $qtyNeeded = Money::normalize($quantity, 4);
        if (Money::compare($qtyNeeded, '0', 4) <= 0) {
            throw new InvalidArgumentException('Cantidad FIFO inválida.');
        }

        $query = InventoryLot::query()
            ->where('product_id', $productId)
            ->where('status', InventoryLotStatus::Open->value)
            ->where('qty_remaining', '>', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate();

        if ($locationId) {
            $query->where('inventory_location_id', $locationId);
        }

        $lots = $query->get();
        $remaining = $qtyNeeded;
        $allocations = [];
        $totalCost = '0';
        $totalArs = '0.00';
        $totalUsd = '0.00';
        $currencyId = null;
        $rate = null;

        foreach ($lots as $lot) {
            if (Money::compare($remaining, '0', 4) <= 0) {
                break;
            }

            $take = Money::compare($remaining, (string) $lot->qty_remaining, 4) <= 0
                ? $remaining
                : Money::normalize((string) $lot->qty_remaining, 4);

            $lineCost = Money::normalize(bcmul($take, (string) $lot->unit_cost, 10), 6);
            $lineArs = Money::normalize(bcmul($take, (string) $lot->unit_cost_ars, 10), 2);
            $lineUsd = Money::normalize(bcmul($take, (string) $lot->unit_cost_usd, 10), 2);

            $allocations[] = [
                'lot_id' => $lot->id,
                'quantity' => $take,
                'unit_cost' => Money::normalize((string) $lot->unit_cost, 6),
                'currency_id' => $lot->currency_id,
                'exchange_rate_value' => $lot->exchange_rate_value !== null
                    ? Money::normalize((string) $lot->exchange_rate_value, 6)
                    : null,
                'unit_cost_ars' => Money::normalize((string) $lot->unit_cost_ars, 6),
                'unit_cost_usd' => Money::normalize((string) $lot->unit_cost_usd, 6),
                'total_cost' => $lineCost,
                'total_cost_ars' => $lineArs,
                'total_cost_usd' => $lineUsd,
            ];

            $totalCost = Money::add($totalCost, $lineCost, 6);
            $totalArs = Money::add($totalArs, $lineArs);
            $totalUsd = Money::add($totalUsd, $lineUsd);
            $currencyId = $lot->currency_id;
            $rate = $lot->exchange_rate_value;
            $remaining = Money::sub($remaining, $take, 4);
        }

        if (Money::compare($remaining, '0', 4) > 0) {
            throw new RuntimeException('Stock en lotes insuficiente para consumo FIFO. Faltan '.$remaining.' unidades.');
        }

        $avg = Money::compare($qtyNeeded, '0', 4) > 0
            ? Money::normalize(bcdiv($totalCost, $qtyNeeded, 10), 6)
            : '0.000000';

        return [
            'allocations' => $allocations,
            'total_cost' => $totalCost,
            'total_cost_ars' => $totalArs,
            'total_cost_usd' => $totalUsd,
            'avg_unit_cost' => $avg,
            'currency_id' => $currencyId,
            'exchange_rate_value' => $rate !== null ? Money::normalize((string) $rate, 6) : null,
        ];
    }
}
