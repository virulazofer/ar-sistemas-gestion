<?php

namespace App\Services\Sales;

use App\Enums\ClientLedgerType;
use App\Enums\CommercialItemType;
use App\Enums\EquipmentStatus;
use App\Enums\MovementStatus;
use App\Enums\SaleStatus;
use App\Models\Client;
use App\Models\ClientLedgerEntry;
use App\Models\Equipment;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\Clients\ClientLedgerService;
use App\Services\Equipment\EquipmentAssemblyService;
use App\Services\Finance\ExchangeRateService;
use App\Services\Inventory\InventoryService;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class SaleService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly ClientLedgerService $ledger,
        private readonly EquipmentAssemblyService $equipment,
        private readonly ExchangeRateService $rates,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $data): Sale
    {
        return DB::transaction(function () use ($data) {
            $client = Client::query()->findOrFail($data['client_id']);
            if (! $client->isActive()) {
                throw new InvalidArgumentException('El cliente no está activo.');
            }

            $fx = $this->resolveFx($data);
            $seq = (int) Setting::getValue('sales.next_sequence', 1);
            $number = sprintf('V-%06d', $seq);
            Setting::setValue('sales.next_sequence', $seq + 1, 'int');

            $sale = Sale::query()->create([
                'number' => $number,
                'sequence' => $seq,
                'client_id' => $client->id,
                'status' => SaleStatus::Draft,
                'origin' => $data['origin'] ?? 'manual',
                'quotation_id' => $data['quotation_id'] ?? null,
                'sold_on' => $data['sold_on'] ?? now()->toDateString(),
                'currency_code' => strtoupper((string) ($data['currency_code'] ?? 'USD')),
                'exchange_rate_id' => $fx['id'],
                'exchange_rate_value' => $fx['value'],
                'salesperson_id' => $data['salesperson_id'] ?? Auth::id(),
                'notes' => $data['notes'] ?? null,
                'discount_amount' => Money::normalize($data['discount_amount'] ?? '0'),
                'tax_amount' => Money::normalize($data['tax_amount'] ?? '0'),
                'user_id' => Auth::id() ?? throw new RuntimeException('Usuario requerido.'),
            ]);

            foreach ($data['items'] ?? [] as $i => $item) {
                $this->addItem($sale, $item, $i + 1, recalc: false);
            }

            $this->recalculate($sale->fresh());
            $this->audit->log('sale_created', $sale, null, [
                'number' => $number,
                'client_id' => $client->id,
            ], 'Venta borrador creada');

            return $sale->fresh(['items', 'client']);
        });
    }

    public function createFromQuotation(Quotation $quotation): Sale
    {
        $items = [];
        foreach ($quotation->items as $qi) {
            $items[] = [
                'item_type' => $qi->item_type->value,
                'description' => $qi->description,
                'product_id' => $qi->product_id,
                'equipment_id' => $qi->equipment_id,
                'equipment_type_id' => $qi->equipment_type_id,
                'work_order_id' => $qi->work_order_id,
                'subscription_id' => $qi->subscription_id,
                'quotation_item_id' => $qi->id,
                'quantity' => (string) $qi->quantity,
                'unit_price' => (string) $qi->unit_price,
                'currency_code' => $qi->currency_code,
                'discount_amount' => (string) $qi->discount_amount,
                'tax_amount' => (string) $qi->tax_amount,
                'requires_build' => $qi->requires_build,
                'notes' => $qi->notes,
            ];
        }

        return $this->create([
            'client_id' => $quotation->client_id,
            'origin' => 'quotation',
            'quotation_id' => $quotation->id,
            'sold_on' => now()->toDateString(),
            'currency_code' => $quotation->currency_code,
            'exchange_rate_id' => $quotation->exchange_rate_id,
            'exchange_rate_value' => (string) $quotation->exchange_rate_value,
            'salesperson_id' => $quotation->salesperson_id,
            'notes' => 'Origen '.$quotation->number,
            'discount_amount' => (string) $quotation->discount_amount,
            'tax_amount' => '0', // ya viene en líneas + header se copia aparte
            'items' => $items,
        ]);
    }

    public function addItem(Sale $sale, array $data, ?int $lineNumber = null, bool $recalc = true): SaleItem
    {
        if (! $sale->isEditable()) {
            throw new InvalidArgumentException('Venta no editable.');
        }

        $type = CommercialItemType::from($data['item_type']);
        if ($type === CommercialItemType::BuildToOrder) {
            // Se puede incluir en borrador; confirmación bloqueará hasta fabricar (Etapa futura).
        }
        if ($type === CommercialItemType::Product && empty($data['product_id'])) {
            throw new InvalidArgumentException('Ítem producto requiere product_id.');
        }
        if ($type === CommercialItemType::Equipment && empty($data['equipment_id'])) {
            throw new InvalidArgumentException('Ítem equipo requiere equipment_id.');
        }

        $qty = Money::normalize((string) ($data['quantity'] ?? '1'), 4);
        $unitPrice = Money::normalize((string) $data['unit_price'], 6);
        $discount = Money::normalize($data['discount_amount'] ?? '0');
        $tax = Money::normalize($data['tax_amount'] ?? '0');
        $subtotal = Money::normalize(bcmul($qty, $unitPrice, 10));
        $lineTotal = Money::sub(Money::add($subtotal, $tax), $discount);
        $code = strtoupper((string) ($data['currency_code'] ?? $sale->currency_code));
        $rate = Money::normalize((string) $sale->exchange_rate_value, 6);
        $equiv = $this->equivalents($code, $lineTotal, $rate);

        $item = SaleItem::query()->create([
            'sale_id' => $sale->id,
            'line_number' => $lineNumber ?? (($sale->items()->max('line_number') ?? 0) + 1),
            'item_type' => $type,
            'description' => $data['description'],
            'product_id' => $data['product_id'] ?? null,
            'equipment_id' => $data['equipment_id'] ?? null,
            'equipment_type_id' => $data['equipment_type_id'] ?? null,
            'work_order_id' => $data['work_order_id'] ?? null,
            'subscription_id' => $data['subscription_id'] ?? null,
            'quotation_item_id' => $data['quotation_item_id'] ?? null,
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'currency_code' => $code,
            'discount_amount' => $discount,
            'tax_amount' => $tax,
            'line_subtotal' => $subtotal,
            'line_total' => $lineTotal,
            'line_total_ars' => $equiv['ars'],
            'line_total_usd' => $equiv['usd'],
            'requires_build' => ! empty($data['requires_build']) || $type === CommercialItemType::BuildToOrder,
            'notes' => $data['notes'] ?? null,
        ]);

        if ($recalc) {
            $this->recalculate($sale->fresh());
        }

        return $item;
    }

    /**
     * Confirmación atómica: stock FIFO + equipo + cargo CC + pago contado opcional.
     *
     * @param  array{
     *   payment_mode: string,
     *   financial_account_id?: int,
     *   force_fail?: bool,
     *   force_fail_stock?: bool,
     *   force_fail_charge?: bool,
     *   force_fail_payment?: bool
     * }  $data
     */
    public function confirm(Sale $sale, array $data): Sale
    {
        if ($sale->status !== SaleStatus::Draft) {
            throw new InvalidArgumentException('Solo se confirman ventas en borrador.');
        }

        $mode = $data['payment_mode'] ?? null;
        if (! in_array($mode, [Sale::MODE_CASH, Sale::MODE_CREDIT], true)) {
            throw new InvalidArgumentException('Modo de pago inválido (cash|credit).');
        }
        if ($mode === Sale::MODE_CASH && empty($data['financial_account_id'])) {
            throw new InvalidArgumentException('Venta contado requiere cuenta financiera.');
        }

        return DB::transaction(function () use ($sale, $data, $mode) {
            $sale = Sale::query()->lockForUpdate()->with('items')->findOrFail($sale->id);
            if ($sale->status !== SaleStatus::Draft) {
                throw new InvalidArgumentException('La venta ya no está en borrador.');
            }

            foreach ($sale->items as $item) {
                if ($item->requires_build || $item->item_type === CommercialItemType::BuildToOrder) {
                    throw new InvalidArgumentException(
                        'La línea "'.$item->description.'" requiere fabricación previa (no automática en Etapa 8).'
                    );
                }
                $this->fulfillItem($sale, $item, $data);
            }

            $this->recalculate($sale->fresh(['items']));
            $sale = $sale->fresh(['items']);

            $chargeAmount = Money::normalize((string) $sale->total);
            $charge = null;
            $payment = null;
            $movementId = null;

            if (Money::compare($chargeAmount, '0') > 0) {
                $charge = $this->ledger->createEntry(
                    $sale->client,
                    ClientLedgerType::Charge,
                    [
                        'currency_code' => $sale->currency_code,
                        'amount' => $chargeAmount,
                        'entry_date' => $sale->sold_on?->toDateString() ?? now()->toDateString(),
                        'description' => 'Venta '.$sale->number,
                        'sale_id' => $sale->id,
                        'quote_id' => $sale->quotation_id,
                        'force_fail' => ! empty($data['force_fail_charge']),
                    ],
                    sign: -1,
                    requiresFinance: false,
                    wrapTransaction: false,
                );

                if ($mode === Sale::MODE_CASH) {
                    $pay = $this->ledger->registerPayment($sale->client, [
                        'financial_account_id' => $data['financial_account_id'],
                        'amount' => $chargeAmount,
                        'entry_date' => $sale->sold_on?->toDateString() ?? now()->toDateString(),
                        'description' => 'Pago venta '.$sale->number,
                        'sale_id' => $sale->id,
                        'force_fail_finance' => ! empty($data['force_fail_payment']),
                        'force_fail_after_ledger' => ! empty($data['force_fail_payment_ledger']),
                        'wrap_transaction' => false,
                    ]);
                    $payment = $pay['ledger'];
                    $movementId = $pay['movement']->id;
                    // Vincular sale_id en pago si registerPayment lo soporta
                    if ($payment->sale_id === null) {
                        $payment->update(['sale_id' => $sale->id]);
                    }
                }
            }

            if (! empty($data['force_fail'])) {
                throw new RuntimeException('Falla simulada al confirmar venta.');
            }

            $sale->update([
                'status' => SaleStatus::Confirmed,
                'payment_mode' => $mode,
                'charge_ledger_entry_id' => $charge?->id,
                'payment_ledger_entry_id' => $payment?->id,
                'financial_movement_id' => $movementId,
                'confirmed_at' => now(),
            ]);

            $this->audit->log('sale_confirmed', $sale, ['status' => 'draft'], [
                'status' => 'confirmed',
                'payment_mode' => $mode,
                'total' => $sale->total,
                'total_cost' => $sale->total_cost,
                'gross_margin' => $sale->gross_margin,
            ], 'Venta confirmada');

            return $sale->fresh(['items', 'chargeEntry', 'paymentEntry']);
        });
    }

    /**
     * Anulación: revierte stock, equipo, CC y finanzas. Bloquea si hay inconsistencias.
     */
    public function void(Sale $sale, string $reason): Sale
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('La anulación requiere motivo.');
        }
        if ($sale->status !== SaleStatus::Confirmed) {
            throw new InvalidArgumentException('Solo se anulan ventas confirmadas.');
        }

        return DB::transaction(function () use ($sale, $reason) {
            $sale = Sale::query()->lockForUpdate()->with(['items.product', 'items.equipment', 'chargeEntry', 'paymentEntry'])->findOrFail($sale->id);

            // Política: anular pago primero, luego cargo, luego stock/equipo
            if ($sale->payment_ledger_entry_id) {
                $pay = ClientLedgerEntry::query()->find($sale->payment_ledger_entry_id);
                if ($pay && $pay->status === MovementStatus::Posted) {
                    $this->ledger->void($pay, 'Anulación venta '.$sale->number.': '.$reason);
                }
            }
            if ($sale->charge_ledger_entry_id) {
                $charge = ClientLedgerEntry::query()->find($sale->charge_ledger_entry_id);
                if ($charge && $charge->status === MovementStatus::Posted) {
                    $this->ledger->void($charge, 'Anulación venta '.$sale->number.': '.$reason);
                }
            }

            foreach ($sale->items as $item) {
                if ($item->inventory_movement_id) {
                    $mov = InventoryMovement::query()->find($item->inventory_movement_id);
                    if ($mov && $mov->isPosted()) {
                        $this->inventory->void($mov, 'Anulación venta '.$sale->number.': '.$reason);
                    }
                }
                if ($item->item_type === CommercialItemType::Equipment && $item->equipment_id) {
                    $eq = Equipment::query()->lockForUpdate()->findOrFail($item->equipment_id);
                    if ($eq->status === EquipmentStatus::Sold) {
                        $target = EquipmentStatus::tryFrom((string) ($item->equipment_status_before ?? 'available'))
                            ?? EquipmentStatus::Available;
                        $this->equipment->changeStatus($eq, $target, 'Anulación venta '.$sale->number, adminOverride: true);
                    }
                }
            }

            $sale->update([
                'status' => SaleStatus::Voided,
                'void_reason' => $reason,
                'voided_at' => now(),
                'voided_by' => Auth::id(),
            ]);

            $this->audit->log('sale_voided', $sale, ['status' => 'confirmed'], [
                'status' => 'voided',
                'reason' => $reason,
            ], 'Venta anulada');

            return $sale->fresh();
        });
    }

    private function fulfillItem(Sale $sale, SaleItem $item, array $data): void
    {
        if ($item->item_type->consumesStock()) {
            $product = Product::query()->lockForUpdate()->findOrFail($item->product_id);
            $movement = $this->inventory->consume($product, [
                'quantity' => (string) $item->quantity,
                'reason' => 'Venta '.$sale->number,
                'sale_id' => $sale->id,
                'wrap_transaction' => false,
                'force_fail' => ! empty($data['force_fail_stock']),
            ]);

            $cost = Money::normalize((string) ($movement->total_cost ?? '0'), 6);
            $costArs = Money::normalize((string) ($movement->total_cost_ars ?? '0'));
            $costUsd = Money::normalize((string) ($movement->total_cost_usd ?? '0'));
            $qty = Money::normalize((string) $item->quantity, 4);
            $unitCost = Money::compare($qty, '0', 4) > 0
                ? Money::normalize(bcdiv($cost, $qty, 10), 6)
                : '0';

            $linePrice = Money::normalize((string) $item->line_total);
            // Margen en moneda de la venta: usar costo USD/ARS según currency
            $lineCostForMargin = $sale->currency_code === 'ARS' ? $costArs : $costUsd;

            $item->update([
                'inventory_movement_id' => $movement->id,
                'unit_cost' => $unitCost,
                'line_cost' => $lineCostForMargin,
                'line_cost_ars' => $costArs,
                'line_cost_usd' => $costUsd,
                'line_margin' => Money::sub($linePrice, $lineCostForMargin),
            ]);

            $this->audit->log('sale_stock_consumed', $item, null, [
                'movement_id' => $movement->id,
                'cost_usd' => $costUsd,
            ], 'Stock consumido por venta');

            return;
        }

        if ($item->item_type->sellsEquipment()) {
            $eq = Equipment::query()->lockForUpdate()->findOrFail($item->equipment_id);
            if (in_array($eq->status, [EquipmentStatus::Sold, EquipmentStatus::Disassembled], true)) {
                throw new InvalidArgumentException('Equipo '.$eq->code.' no disponible para venta.');
            }
            $before = $eq->status->value;
            $this->equipment->changeStatus($eq, EquipmentStatus::Sold, 'Venta '.$sale->number);

            $costUsd = Money::normalize((string) $eq->total_cost_usd);
            $costArs = Money::normalize((string) $eq->total_cost_ars);
            $lineCost = $sale->currency_code === 'ARS' ? $costArs : $costUsd;

            $item->update([
                'equipment_status_before' => $before,
                'unit_cost' => $lineCost,
                'line_cost' => $lineCost,
                'line_cost_ars' => $costArs,
                'line_cost_usd' => $costUsd,
                'line_margin' => Money::sub((string) $item->line_total, $lineCost),
            ]);

            $this->audit->log('sale_equipment_sold', $item, null, [
                'equipment_id' => $eq->id,
                'code' => $eq->code,
                'cost_usd' => $costUsd,
            ], 'Equipo vendido (sin re-consumir componentes)');

            return;
        }

        // Servicios / mano de obra / libres: sin stock; costo estimado 0 salvo indicado
        $item->update([
            'line_margin' => Money::normalize((string) $item->line_total),
        ]);
    }

    private function recalculate(Sale $sale): void
    {
        $sale->load('items');
        $subtotal = '0.00';
        $tax = '0.00';
        $disc = '0.00';
        $totalArs = '0.00';
        $totalUsd = '0.00';
        $costArs = '0.00';
        $costUsd = '0.00';
        $cost = '0.00';

        foreach ($sale->items as $item) {
            $subtotal = Money::add($subtotal, (string) $item->line_subtotal);
            $tax = Money::add($tax, (string) $item->tax_amount);
            $disc = Money::add($disc, (string) $item->discount_amount);
            $totalArs = Money::add($totalArs, (string) $item->line_total_ars);
            $totalUsd = Money::add($totalUsd, (string) $item->line_total_usd);
            $costArs = Money::add($costArs, (string) ($item->line_cost_ars ?? '0'));
            $costUsd = Money::add($costUsd, (string) ($item->line_cost_usd ?? '0'));
            $cost = Money::add($cost, (string) ($item->line_cost ?? '0'));
        }

        $headerDisc = Money::normalize((string) $sale->discount_amount);
        $headerTax = Money::normalize((string) $sale->tax_amount);
        $total = Money::sub(Money::add(Money::add($subtotal, $tax), $headerTax), Money::add($disc, $headerDisc));
        $margin = Money::sub($total, $cost);

        $sale->update([
            'subtotal' => $subtotal,
            'tax_amount' => Money::add($tax, $headerTax),
            'total' => $total,
            'total_ars' => $sale->currency_code === 'ARS' ? $total : $totalArs,
            'total_usd' => $sale->currency_code === 'USD' ? $total : $totalUsd,
            'total_cost' => $cost,
            'total_cost_ars' => $costArs,
            'total_cost_usd' => $costUsd,
            'gross_margin' => $margin,
        ]);
    }

    private function resolveFx(array $data): array
    {
        if (! empty($data['exchange_rate_value'])) {
            return [
                'id' => $data['exchange_rate_id'] ?? null,
                'value' => Money::normalize((string) $data['exchange_rate_value'], 6),
            ];
        }
        try {
            $latest = $this->rates->latestOfficialSell(false)['rate'];

            return ['id' => $latest->id, 'value' => Money::normalize((string) $latest->rate, 6)];
        } catch (\Throwable) {
            return ['id' => null, 'value' => '1.000000'];
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
