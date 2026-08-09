<?php

namespace App\Services\WorkOrders;

use App\Enums\ClientLedgerType;
use App\Enums\WorkOrderStatus;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\WorkOrder;
use App\Models\WorkOrderAsset;
use App\Models\WorkOrderDiagnosis;
use App\Models\WorkOrderMaterial;
use App\Models\WorkOrderTask;
use App\Models\WorkOrderType;
use App\Services\AuditLogger;
use App\Services\Clients\ClientLedgerService;
use App\Services\Finance\ExchangeRateService;
use App\Services\Inventory\FifoService;
use App\Services\Inventory\InventoryService;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class WorkOrderService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly FifoService $fifo,
        private readonly ClientLedgerService $ledger,
        private readonly ExchangeRateService $rates,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $data): WorkOrder
    {
        return DB::transaction(function () use ($data) {
            $client = Client::query()->findOrFail($data['client_id']);
            if (! $client->isActive()) {
                throw new InvalidArgumentException('El cliente no está activo.');
            }
            WorkOrderType::query()->where('is_active', true)->findOrFail($data['work_order_type_id']);

            $seq = (int) Setting::getValue('work_orders.next_sequence', 1);
            $number = sprintf('OT-%06d', $seq);
            Setting::setValue('work_orders.next_sequence', $seq + 1, 'int');

            $wo = WorkOrder::query()->create([
                'number' => $number,
                'sequence' => $seq,
                'client_id' => $client->id,
                'work_order_type_id' => $data['work_order_type_id'],
                'status' => WorkOrderStatus::Open,
                'priority' => $data['priority'] ?? 'normal',
                'assigned_user_id' => $data['assigned_user_id'] ?? null,
                'inventory_location_id' => $data['inventory_location_id'] ?? null,
                'opened_at' => $data['opened_at'] ?? now()->toDateString(),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'notes' => $data['notes'] ?? null,
                'currency_code' => strtoupper((string) ($data['currency_code'] ?? 'USD')),
                'user_id' => Auth::id() ?? throw new RuntimeException('Usuario requerido.'),
            ]);

            foreach ($data['assets'] ?? [] as $asset) {
                $this->attachAsset($wo, $asset);
            }

            $this->audit->log('work_order_created', $wo, null, $wo->only([
                'number', 'client_id', 'work_order_type_id', 'status', 'title',
            ]), 'OT creada');

            return $wo->fresh(['client', 'type', 'assets']);
        });
    }

    public function update(WorkOrder $workOrder, array $data): WorkOrder
    {
        $this->assertEditable($workOrder);

        return DB::transaction(function () use ($workOrder, $data) {
            $old = $workOrder->toArray();
            $workOrder->update(array_filter([
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'solution' => $data['solution'] ?? null,
                'notes' => $data['notes'] ?? null,
                'priority' => $data['priority'] ?? null,
                'assigned_user_id' => array_key_exists('assigned_user_id', $data) ? $data['assigned_user_id'] : null,
                'inventory_location_id' => array_key_exists('inventory_location_id', $data) ? $data['inventory_location_id'] : null,
                'status' => isset($data['status']) ? WorkOrderStatus::from($data['status'])->value : null,
            ], fn ($v) => $v !== null));

            $this->audit->log('work_order_updated', $workOrder, $old, $workOrder->fresh()->toArray(), 'OT actualizada');

            return $workOrder->fresh();
        });
    }

    public function addDiagnosis(WorkOrder $workOrder, array $data): WorkOrderDiagnosis
    {
        $this->assertEditable($workOrder);

        $diagnosis = WorkOrderDiagnosis::query()->create([
            'work_order_id' => $workOrder->id,
            'client_reported_issue' => $data['client_reported_issue'] ?? null,
            'technical_diagnosis' => $data['technical_diagnosis'],
            'notes' => $data['notes'] ?? null,
            'user_id' => Auth::id() ?? throw new RuntimeException('Usuario requerido.'),
            'diagnosed_at' => $data['diagnosed_at'] ?? now(),
        ]);

        if ($workOrder->status === WorkOrderStatus::Open) {
            $workOrder->update(['status' => WorkOrderStatus::Diagnosing]);
        }

        $this->audit->log('work_order_diagnosis_added', $diagnosis, null, $diagnosis->toArray(), 'Diagnóstico OT');

        return $diagnosis;
    }

    public function addTask(WorkOrder $workOrder, array $data): WorkOrderTask
    {
        $this->assertEditable($workOrder);
        $fx = $this->resolveFx($data);
        $cost = Money::normalize($data['cost_amount'] ?? '0', 6);
        $price = Money::normalize($data['price_amount'] ?? '0', 6);
        $code = strtoupper((string) ($data['currency_code'] ?? $workOrder->currency_code));
        $costEq = $this->equivalents($code, $cost, $fx);
        $priceEq = $this->equivalents($code, $price, $fx);

        $task = WorkOrderTask::query()->create([
            'work_order_id' => $workOrder->id,
            'description' => $data['description'],
            'assigned_user_id' => $data['assigned_user_id'] ?? null,
            'hours' => $data['hours'] ?? null,
            'cost_amount' => $cost,
            'price_amount' => $price,
            'currency_code' => $code,
            'exchange_rate_value' => $fx,
            'cost_ars' => $costEq['ars'],
            'cost_usd' => $costEq['usd'],
            'price_ars' => $priceEq['ars'],
            'price_usd' => $priceEq['usd'],
            'status' => $data['status'] ?? 'pending',
        ]);

        $this->recalculateTotals($workOrder);
        $this->audit->log('work_order_task_added', $task, null, $task->only([
            'description', 'price_amount', 'cost_amount', 'currency_code',
        ]), 'Tarea OT');

        return $task;
    }

    public function addMaterial(WorkOrder $workOrder, array $data): WorkOrderMaterial
    {
        $this->assertEditable($workOrder);
        $product = Product::query()->findOrFail($data['product_id']);
        if (! $product->tracksStock()) {
            throw new InvalidArgumentException('Solo productos físicos.');
        }

        $qty = Money::normalize((string) $data['quantity'], 4);
        $fx = $this->resolveFx($data);
        $code = strtoupper((string) ($data['currency_code'] ?? $workOrder->currency_code));
        $priceUnit = Money::normalize($data['price_unit'] ?? '0', 6);
        $priceTotal = Money::normalize(bcmul($qty, $priceUnit, 10), 6);
        $priceEq = $this->equivalents($code, $priceTotal, $fx);

        $material = WorkOrderMaterial::query()->create([
            'work_order_id' => $workOrder->id,
            'product_id' => $product->id,
            'quantity' => $qty,
            'price_unit' => $priceUnit,
            'price_total' => $priceTotal,
            'currency_code' => $code,
            'exchange_rate_value' => $fx,
            'price_ars' => $priceEq['ars'],
            'price_usd' => $priceEq['usd'],
            'status' => 'pending',
            'notes' => $data['notes'] ?? null,
            'inventory_serial_id' => $data['inventory_serial_id'] ?? null,
        ]);

        $this->recalculateTotals($workOrder);
        $this->audit->log('work_order_material_added', $material, null, [
            'product_id' => $product->id,
            'quantity' => $qty,
            'price_total' => $priceTotal,
        ], 'Material OT pendiente');

        return $material;
    }

    /**
     * Cierre atómico: consume materiales pendientes (FIFO), cargo CC, cierra OT.
     */
    public function close(WorkOrder $workOrder, array $data = []): WorkOrder
    {
        if ($workOrder->status === WorkOrderStatus::Closed) {
            throw new InvalidArgumentException('La OT ya está cerrada.');
        }
        if ($workOrder->status === WorkOrderStatus::Cancelled) {
            throw new InvalidArgumentException('La OT está cancelada.');
        }

        return DB::transaction(function () use ($workOrder, $data) {
            $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);

            foreach ($workOrder->materials()->where('status', 'pending')->lockForUpdate()->get() as $material) {
                $this->consumeMaterial($workOrder, $material, wrap: false);
            }

            $this->recalculateTotals($workOrder->fresh());
            $workOrder = $workOrder->fresh();

            $billableUsd = Money::normalize((string) $workOrder->total_price_usd);
            $billableArs = Money::normalize((string) $workOrder->total_price_ars);
            $chargeCurrency = $workOrder->currency_code;
            $chargeAmount = $chargeCurrency === 'ARS' ? $billableArs : $billableUsd;

            $ledgerId = null;
            if (Money::compare($chargeAmount, '0') > 0) {
                $entry = $this->ledger->createEntry(
                    $workOrder->client,
                    ClientLedgerType::Charge,
                    [
                        'currency_code' => $chargeCurrency,
                        'amount' => $chargeAmount,
                        'entry_date' => $data['closed_at'] ?? now()->toDateString(),
                        'description' => 'OT '.$workOrder->number.' — '.$workOrder->title,
                        'work_order_id' => $workOrder->id,
                        'force_fail' => ! empty($data['force_fail_charge']),
                    ],
                    sign: -1,
                    requiresFinance: false,
                    wrapTransaction: false,
                );
                $ledgerId = $entry->id;
            }

            if (! empty($data['force_fail'])) {
                throw new RuntimeException('Falla simulada al cerrar OT.');
            }

            $workOrder->update([
                'status' => WorkOrderStatus::Closed,
                'closed_at' => $data['closed_at'] ?? now()->toDateString(),
                'solution' => $data['solution'] ?? $workOrder->solution,
                'client_ledger_entry_id' => $ledgerId,
            ]);

            $this->audit->log('work_order_closed', $workOrder, ['status' => 'open'], [
                'status' => 'closed',
                'total_price_usd' => $workOrder->total_price_usd,
                'total_cost_usd' => $workOrder->total_cost_usd,
                'ledger_id' => $ledgerId,
            ], 'OT cerrada');

            return $workOrder->fresh(['materials', 'tasks', 'ledgerEntry']);
        });
    }

    public function cancel(WorkOrder $workOrder, string $reason): WorkOrder
    {
        $this->assertEditable($workOrder);
        if ($workOrder->materials()->where('status', 'consumed')->exists()) {
            throw new InvalidArgumentException('No se puede cancelar: hay materiales ya consumidos. Usá un procedimiento administrativo.');
        }

        $workOrder->update([
            'status' => WorkOrderStatus::Cancelled,
            'notes' => trim(($workOrder->notes ? $workOrder->notes."\n" : '').'Cancelada: '.$reason),
            'closed_at' => now()->toDateString(),
        ]);
        $this->audit->log('work_order_cancelled', $workOrder, null, ['reason' => $reason], 'OT cancelada');

        return $workOrder->fresh();
    }

    public function attachAsset(WorkOrder $workOrder, array $data): WorkOrderAsset
    {
        return WorkOrderAsset::query()->create([
            'work_order_id' => $workOrder->id,
            'equipment_id' => $data['equipment_id'] ?? null,
            'external_manufacturer' => $data['external_manufacturer'] ?? null,
            'external_model' => $data['external_model'] ?? null,
            'external_serial' => $data['external_serial'] ?? null,
            'external_label' => $data['external_label'] ?? null,
            'external_description' => $data['external_description'] ?? null,
        ]);
    }

    private function consumeMaterial(WorkOrder $workOrder, WorkOrderMaterial $material, bool $wrap): void
    {
        $run = function () use ($workOrder, $material) {
            $material = WorkOrderMaterial::query()->lockForUpdate()->findOrFail($material->id);
            if ($material->status !== 'pending') {
                return;
            }
            $product = Product::query()->lockForUpdate()->findOrFail($material->product_id);
            $qty = Money::normalize((string) $material->quantity, 4);

            $plan = $this->fifo->planConsumption($product->id, $qty);
            $costTotal = '0';
            $costArs = '0.00';
            $costUsd = '0.00';
            $firstLotId = null;
            $firstAllocId = null;
            $movementId = null;
            $serialId = $material->inventory_serial_id;

            if ($product->requires_serial) {
                if (! $serialId && empty($material->notes)) {
                    // serial must be on material
                }
                $movement = $this->inventory->consumeFromLot(
                    $product,
                    \App\Models\InventoryLot::query()->findOrFail(
                        \App\Models\InventorySerial::query()->findOrFail(
                            $serialId ?? throw new InvalidArgumentException('Material serializado requiere serial.')
                        )->inventory_lot_id
                    ),
                    $qty,
                    [
                        'inventory_serial_id' => $serialId,
                        'reason' => 'OT '.$workOrder->number,
                        'work_order_id' => $workOrder->id,
                        'wrap_transaction' => false,
                    ]
                );
                $alloc = $movement->allocations->first();
                $costTotal = (string) $movement->total_cost;
                $costArs = (string) $movement->total_cost_ars;
                $costUsd = (string) $movement->total_cost_usd;
                $firstLotId = $alloc?->inventory_lot_id;
                $firstAllocId = $alloc?->id;
                $movementId = $movement->id;
            } else {
                foreach ($plan['allocations'] as $row) {
                    $lot = \App\Models\InventoryLot::query()->lockForUpdate()->findOrFail($row['lot_id']);
                    $movement = $this->inventory->consumeFromLot($product, $lot, $row['quantity'], [
                        'reason' => 'OT '.$workOrder->number,
                        'work_order_id' => $workOrder->id,
                        'wrap_transaction' => false,
                    ]);
                    $alloc = $movement->allocations->first();
                    $costTotal = Money::add($costTotal, (string) $alloc->total_cost, 6);
                    $costArs = Money::add($costArs, (string) $alloc->total_cost_ars);
                    $costUsd = Money::add($costUsd, (string) $alloc->total_cost_usd);
                    $firstLotId ??= $alloc->inventory_lot_id;
                    $firstAllocId ??= $alloc->id;
                    $movementId = $movement->id;
                }
            }

            $unitCost = Money::compare($qty, '0', 4) > 0
                ? Money::normalize(bcdiv($costTotal, $qty, 10), 6)
                : '0';

            $material->update([
                'status' => 'consumed',
                'cost_unit' => $unitCost,
                'cost_total' => $costTotal,
                'cost_ars' => $costArs,
                'cost_usd' => $costUsd,
                'inventory_movement_id' => $movementId,
                'inventory_lot_id' => $firstLotId,
                'inventory_lot_allocation_id' => $firstAllocId,
            ]);

            $this->audit->log('work_order_material_consumed', $material, null, [
                'cost_usd' => $costUsd,
                'movement_id' => $movementId,
            ], 'Material OT consumido FIFO');
        };

        $wrap ? DB::transaction($run) : $run();
    }

    private function recalculateTotals(WorkOrder $workOrder): void
    {
        $workOrder->load(['tasks', 'materials']);
        $costArs = '0.00';
        $costUsd = '0.00';
        $priceArs = '0.00';
        $priceUsd = '0.00';

        foreach ($workOrder->tasks as $task) {
            $costArs = Money::add($costArs, (string) ($task->cost_ars ?? '0'));
            $costUsd = Money::add($costUsd, (string) ($task->cost_usd ?? '0'));
            $priceArs = Money::add($priceArs, (string) ($task->price_ars ?? '0'));
            $priceUsd = Money::add($priceUsd, (string) ($task->price_usd ?? '0'));
        }
        foreach ($workOrder->materials as $material) {
            $costArs = Money::add($costArs, (string) ($material->cost_ars ?? '0'));
            $costUsd = Money::add($costUsd, (string) ($material->cost_usd ?? '0'));
            $priceArs = Money::add($priceArs, (string) ($material->price_ars ?? '0'));
            $priceUsd = Money::add($priceUsd, (string) ($material->price_usd ?? '0'));
        }

        $workOrder->update([
            'total_cost_ars' => $costArs,
            'total_cost_usd' => $costUsd,
            'total_price_ars' => $priceArs,
            'total_price_usd' => $priceUsd,
            'total_cost' => $workOrder->currency_code === 'ARS' ? $costArs : $costUsd,
            'total_price' => $workOrder->currency_code === 'ARS' ? $priceArs : $priceUsd,
        ]);
    }

    private function assertEditable(WorkOrder $workOrder): void
    {
        if (! $workOrder->isEditable()) {
            throw new InvalidArgumentException('La OT cerrada/cancelada no admite modificaciones libres.');
        }
    }

    private function resolveFx(array $data): string
    {
        if (! empty($data['exchange_rate_value'])) {
            return Money::normalize((string) $data['exchange_rate_value'], 6);
        }
        try {
            return Money::normalize((string) $this->rates->latestOfficialSell(false)['rate']->rate, 6);
        } catch (\Throwable) {
            return '1.000000';
        }
    }

    private function equivalents(string $code, string $amount, string $rate): array
    {
        if ($code === 'ARS') {
            return [
                'ars' => Money::normalize($amount),
                'usd' => Money::compare($rate, '0', 6) > 0 ? Money::div($amount, $rate) : '0.00',
            ];
        }

        return [
            'ars' => Money::mul($amount, $rate),
            'usd' => Money::normalize($amount),
        ];
    }
}
