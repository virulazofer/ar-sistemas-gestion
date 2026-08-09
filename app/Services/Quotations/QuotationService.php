<?php

namespace App\Services\Quotations;

use App\Enums\CommercialItemType;
use App\Enums\QuotationStatus;
use App\Models\Client;
use App\Models\Equipment;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Sale;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\Finance\ExchangeRateService;
use App\Services\Inventory\FifoService;
use App\Services\Sales\SaleService;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class QuotationService
{
    public function __construct(
        private readonly ExchangeRateService $rates,
        private readonly AuditLogger $audit,
        private readonly SaleService $sales,
        private readonly FifoService $fifo,
    ) {}

    public function create(array $data): Quotation
    {
        return DB::transaction(function () use ($data) {
            $client = Client::query()->findOrFail($data['client_id']);
            if (! $client->isActive()) {
                throw new InvalidArgumentException('El cliente no está activo.');
            }

            $fx = $this->resolveFx($data);
            $seq = (int) Setting::getValue('quotations.next_sequence', 1);
            $number = sprintf('P-%06d', $seq);
            Setting::setValue('quotations.next_sequence', $seq + 1, 'int');

            $quotation = Quotation::query()->create([
                'number' => $number,
                'sequence' => $seq,
                'client_id' => $client->id,
                'status' => QuotationStatus::Draft,
                'quoted_on' => $data['quoted_on'] ?? now()->toDateString(),
                'valid_until' => $data['valid_until'] ?? now()->addDays(15)->toDateString(),
                'currency_code' => strtoupper((string) ($data['currency_code'] ?? 'USD')),
                'exchange_rate_id' => $fx['id'],
                'exchange_rate_value' => $fx['value'],
                'salesperson_id' => $data['salesperson_id'] ?? Auth::id(),
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                'discount_amount' => Money::normalize($data['discount_amount'] ?? '0'),
                'tax_amount' => Money::normalize($data['tax_amount'] ?? '0'),
                'user_id' => Auth::id() ?? throw new RuntimeException('Usuario requerido.'),
            ]);

            foreach ($data['items'] ?? [] as $i => $item) {
                $this->addItem($quotation, $item, $i + 1, recalc: false);
            }

            $this->recalculate($quotation->fresh());
            $this->audit->log('quotation_created', $quotation, null, [
                'number' => $number,
                'client_id' => $client->id,
                'total' => $quotation->fresh()->total,
            ], 'Presupuesto creado (sin efecto stock/CC)');

            return $quotation->fresh(['items', 'client']);
        });
    }

    public function addItem(Quotation $quotation, array $data, ?int $lineNumber = null, bool $recalc = true): QuotationItem
    {
        $this->assertEditable($quotation);
        $type = CommercialItemType::from($data['item_type']);
        $qty = Money::normalize((string) ($data['quantity'] ?? '1'), 4);
        $unitPrice = Money::normalize((string) $data['unit_price'], 6);
        $discount = Money::normalize($data['discount_amount'] ?? '0');
        $tax = Money::normalize($data['tax_amount'] ?? '0');
        $subtotal = Money::normalize(bcmul($qty, $unitPrice, 10));
        $lineTotal = Money::sub(Money::add($subtotal, $tax), $discount);
        $code = strtoupper((string) ($data['currency_code'] ?? $quotation->currency_code));
        $rate = Money::normalize((string) $quotation->exchange_rate_value, 6);
        $equiv = $this->equivalents($code, $lineTotal, $rate);

        $estUnit = Money::normalize($data['estimated_unit_cost'] ?? $this->estimateUnitCost($type, $data), 6);
        $estCost = Money::normalize(bcmul($qty, $estUnit, 10));
        $estEq = $this->equivalents($code, $estCost, $rate);

        $item = QuotationItem::query()->create([
            'quotation_id' => $quotation->id,
            'line_number' => $lineNumber ?? (($quotation->items()->max('line_number') ?? 0) + 1),
            'item_type' => $type,
            'description' => $data['description'],
            'product_id' => $data['product_id'] ?? null,
            'equipment_id' => $data['equipment_id'] ?? null,
            'equipment_type_id' => $data['equipment_type_id'] ?? null,
            'work_order_id' => $data['work_order_id'] ?? null,
            'subscription_id' => $data['subscription_id'] ?? null,
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'currency_code' => $code,
            'discount_amount' => $discount,
            'tax_amount' => $tax,
            'line_subtotal' => $subtotal,
            'line_total' => $lineTotal,
            'estimated_unit_cost' => $estUnit,
            'estimated_cost' => $estCost,
            'estimated_cost_ars' => $estEq['ars'],
            'estimated_cost_usd' => $estEq['usd'],
            'line_total_ars' => $equiv['ars'],
            'line_total_usd' => $equiv['usd'],
            'requires_build' => $type === CommercialItemType::BuildToOrder || ! empty($data['requires_build']),
            'notes' => $data['notes'] ?? null,
        ]);

        if ($recalc) {
            $this->recalculate($quotation->fresh());
        }

        return $item;
    }

    public function changeStatus(Quotation $quotation, QuotationStatus $status): Quotation
    {
        if ($quotation->status === QuotationStatus::Converted) {
            throw new InvalidArgumentException('El presupuesto ya fue convertido.');
        }
        if ($quotation->status === QuotationStatus::Cancelled && $status !== QuotationStatus::Cancelled) {
            throw new InvalidArgumentException('Presupuesto cancelado.');
        }

        if ($status === QuotationStatus::Expired || ($status->canConvert() && $quotation->isExpiredByDate())) {
            // marcar vencido si corresponde
        }

        $old = $quotation->status;
        if ($quotation->isExpiredByDate() && in_array($status, [QuotationStatus::Sent, QuotationStatus::Accepted], true)) {
            throw new InvalidArgumentException('Presupuesto vencido: renovar antes de enviar/aceptar.');
        }

        $quotation->update(['status' => $status]);
        $this->audit->log('quotation_status_changed', $quotation, ['status' => $old->value], [
            'status' => $status->value,
        ], 'Estado presupuesto');

        return $quotation->fresh();
    }

    public function markExpiredIfNeeded(Quotation $quotation): Quotation
    {
        if ($quotation->isExpiredByDate() && ! in_array($quotation->status, [
            QuotationStatus::Converted, QuotationStatus::Cancelled, QuotationStatus::Rejected, QuotationStatus::Expired,
        ], true)) {
            $quotation->update(['status' => QuotationStatus::Expired]);
            $this->audit->log('quotation_expired', $quotation, null, ['valid_until' => $quotation->valid_until?->toDateString()], 'Presupuesto vencido');
        }

        return $quotation->fresh();
    }

    public function renew(Quotation $quotation, string $validUntil): Quotation
    {
        if ($quotation->status === QuotationStatus::Converted) {
            throw new InvalidArgumentException('No se puede renovar un presupuesto convertido.');
        }
        if ($quotation->status === QuotationStatus::Cancelled) {
            throw new InvalidArgumentException('No se puede renovar un presupuesto cancelado.');
        }

        $old = $quotation->only(['status', 'valid_until']);
        $quotation->update([
            'valid_until' => $validUntil,
            'status' => QuotationStatus::Draft,
        ]);
        $this->audit->log('quotation_renewed', $quotation, $old, $quotation->only(['status', 'valid_until']), 'Presupuesto renovado');

        return $quotation->fresh();
    }

    public function cancel(Quotation $quotation, string $reason): Quotation
    {
        if ($quotation->status === QuotationStatus::Converted) {
            throw new InvalidArgumentException('No se puede cancelar: ya convertido.');
        }
        $quotation->update([
            'status' => QuotationStatus::Cancelled,
            'notes' => trim(($quotation->notes ? $quotation->notes."\n" : '').'Cancelado: '.$reason),
        ]);
        $this->audit->log('quotation_cancelled', $quotation, null, ['reason' => $reason], 'Presupuesto cancelado');

        return $quotation->fresh();
    }

    /**
     * Convierte a venta en borrador. Sin stock/CC/finanzas hasta confirmar la venta.
     */
    public function convert(Quotation $quotation): Sale
    {
        return DB::transaction(function () use ($quotation) {
            $quotation = Quotation::query()->lockForUpdate()->with('items')->findOrFail($quotation->id);
            $this->markExpiredIfNeeded($quotation);
            $quotation->refresh();

            if ($quotation->status === QuotationStatus::Expired || $quotation->isExpiredByDate()) {
                throw new InvalidArgumentException('Presupuesto vencido: renovar antes de convertir.');
            }
            if ($quotation->status === QuotationStatus::Converted) {
                throw new InvalidArgumentException('Ya convertido.');
            }
            if (! in_array($quotation->status, [QuotationStatus::Accepted, QuotationStatus::Sent], true)) {
                throw new InvalidArgumentException('Solo presupuestos enviados/aceptados pueden convertirse.');
            }
            if ($quotation->items->isEmpty()) {
                throw new InvalidArgumentException('El presupuesto no tiene ítems.');
            }

            $sale = $this->sales->createFromQuotation($quotation);

            $quotation->update([
                'status' => QuotationStatus::Converted,
                'converted_sale_id' => $sale->id,
                'converted_at' => now(),
            ]);

            $this->audit->log('quotation_converted', $quotation, null, [
                'sale_id' => $sale->id,
                'sale_number' => $sale->number,
            ], 'Presupuesto convertido a venta borrador');

            return $sale->fresh(['items']);
        });
    }

    private function recalculate(Quotation $quotation): void
    {
        $quotation->load('items');
        $subtotal = '0.00';
        $lineDisc = '0.00';
        $lineTax = '0.00';
        $estCost = '0.00';
        $estArs = '0.00';
        $estUsd = '0.00';
        $totalArs = '0.00';
        $totalUsd = '0.00';

        foreach ($quotation->items as $item) {
            $subtotal = Money::add($subtotal, (string) $item->line_subtotal);
            $lineDisc = Money::add($lineDisc, (string) $item->discount_amount);
            $lineTax = Money::add($lineTax, (string) $item->tax_amount);
            $estCost = Money::add($estCost, (string) $item->estimated_cost);
            $estArs = Money::add($estArs, (string) $item->estimated_cost_ars);
            $estUsd = Money::add($estUsd, (string) $item->estimated_cost_usd);
            $totalArs = Money::add($totalArs, (string) $item->line_total_ars);
            $totalUsd = Money::add($totalUsd, (string) $item->line_total_usd);
        }

        $headerDisc = Money::normalize((string) $quotation->discount_amount);
        $headerTax = Money::normalize((string) $quotation->tax_amount);
        $total = Money::sub(Money::add(Money::add($subtotal, $lineTax), $headerTax), Money::add($lineDisc, $headerDisc));
        $margin = Money::sub($total, $estCost);

        $quotation->update([
            'subtotal' => $subtotal,
            'tax_amount' => Money::add($lineTax, $headerTax),
            'total' => $total,
            'estimated_cost' => $estCost,
            'estimated_cost_ars' => $estArs,
            'estimated_cost_usd' => $estUsd,
            'estimated_margin' => $margin,
            'total_ars' => $quotation->currency_code === 'ARS' ? $total : $totalArs,
            'total_usd' => $quotation->currency_code === 'USD' ? $total : $totalUsd,
        ]);
    }

    private function estimateUnitCost(CommercialItemType $type, array $data): string
    {
        if ($type === CommercialItemType::Product && ! empty($data['product_id'])) {
            if (! empty($data['estimated_unit_cost'])) {
                return Money::normalize((string) $data['estimated_unit_cost'], 6);
            }
            try {
                $qty = Money::normalize((string) ($data['quantity'] ?? '1'), 4);
                $plan = $this->fifo->planConsumption((int) $data['product_id'], $qty);
                // Estimación USD promedio — no consume stock
                if (Money::compare($qty, '0', 4) > 0) {
                    return Money::normalize(bcdiv((string) $plan['total_cost_usd'], $qty, 10), 6);
                }
            } catch (\Throwable) {
                return '0.000000';
            }

            return '0.000000';
        }
        if ($type === CommercialItemType::Equipment && ! empty($data['equipment_id'])) {
            $eq = Equipment::query()->find($data['equipment_id']);

            return Money::normalize((string) ($eq?->total_cost_usd ?? '0'), 6);
        }

        return Money::normalize((string) ($data['estimated_unit_cost'] ?? '0'), 6);
    }

    private function assertEditable(Quotation $quotation): void
    {
        if ($quotation->status === QuotationStatus::Converted || $quotation->status === QuotationStatus::Cancelled) {
            throw new InvalidArgumentException('Presupuesto no editable.');
        }
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
