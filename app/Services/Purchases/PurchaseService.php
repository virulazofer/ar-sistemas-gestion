<?php

namespace App\Services\Purchases;

use App\Enums\MovementStatus;
use App\Enums\SupplierLedgerType;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Services\AuditLogger;
use App\Services\Finance\ExchangeRateService;
use App\Services\Finance\MovementService;
use App\Services\Inventory\InventoryService;
use App\Services\Suppliers\SupplierLedgerService;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class PurchaseService
{
    public function __construct(
        private readonly MovementService $movements,
        private readonly ExchangeRateService $rates,
        private readonly SupplierLedgerService $supplierLedger,
        private readonly InventoryService $inventory,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{
     *   supplier_id: int,
     *   purchase_date?: string,
     *   voucher_type?: string|null,
     *   voucher_letter?: string|null,
     *   voucher_number?: string|null,
     *   currency_code: string,
     *   payment_mode: string,
     *   financial_account_id?: int|null,
     *   tax_amount?: string|float|int,
     *   other_taxes?: string|float|int,
     *   discount_amount?: string|float|int,
     *   notes?: string|null,
     *   exchange_rate_id?: int|null,
     *   force_fail?: bool,
     *   items: list<array{
     *     description: string,
     *     quantity: string|float|int,
     *     unit?: string,
     *     unit_price: string|float|int,
     *     sku?: string|null,
     *     product_id?: int|null,
     *     tax_amount?: string|float|int,
     *     discount_amount?: string|float|int
     *   }>
     * }  $data
     */
    public function create(array $data): Purchase
    {
        return DB::transaction(function () use ($data) {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($data['supplier_id']);
            if (! $supplier->isActive()) {
                throw new InvalidArgumentException('El proveedor no está activo.');
            }

            $mode = $data['payment_mode'];
            if (! in_array($mode, [Purchase::MODE_CASH, Purchase::MODE_CREDIT], true)) {
                throw new InvalidArgumentException('Modo de pago inválido (cash|credit).');
            }

            $items = $data['items'] ?? [];
            if ($items === []) {
                throw new InvalidArgumentException('La compra requiere al menos una línea.');
            }

            $currency = Currency::query()->where('code', strtoupper((string) $data['currency_code']))->firstOrFail();
            $fx = $this->resolveFx($data['exchange_rate_id'] ?? null);
            $rate = $fx['value'];

            $subtotal = '0.00';
            $linesTax = '0.00';
            $linesDiscount = '0.00';
            $normalizedLines = [];

            foreach ($items as $i => $item) {
                $qty = Money::normalize($item['quantity'], 4);
                $unitPrice = Money::normalize($item['unit_price'], 6);
                if (Money::compare($qty, '0', 4) <= 0 || Money::compare($unitPrice, '0', 6) <= 0) {
                    throw new InvalidArgumentException('Cantidad y precio unitario deben ser mayores a cero.');
                }

                $lineSubtotal = Money::normalize(bcmul($qty, $unitPrice, 8), 2);
                $lineTax = Money::normalize($item['tax_amount'] ?? '0');
                $lineDiscount = Money::normalize($item['discount_amount'] ?? '0');
                $lineTotal = Money::sub(Money::add($lineSubtotal, $lineTax), $lineDiscount);

                if (Money::compare($lineTotal, '0') < 0) {
                    throw new InvalidArgumentException('El total de línea no puede ser negativo.');
                }

                $unitCosts = $this->unitCosts($currency->code, $unitPrice, $rate);
                $lineEquiv = $this->equivalents($currency->code, $lineTotal, $rate);

                $normalizedLines[] = [
                    'line_number' => $i + 1,
                    'product_id' => $item['product_id'] ?? null,
                    'sku' => $item['sku'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $qty,
                    'unit' => $item['unit'] ?? 'u',
                    'unit_price' => $unitPrice,
                    'currency_id' => $currency->id,
                    'exchange_rate_value' => $rate,
                    'line_subtotal' => $lineSubtotal,
                    'tax_amount' => $lineTax,
                    'discount_amount' => $lineDiscount,
                    'line_total' => $lineTotal,
                    'unit_cost_ars' => $unitCosts['ars'],
                    'unit_cost_usd' => $unitCosts['usd'],
                    'line_total_ars' => $lineEquiv['ars'],
                    'line_total_usd' => $lineEquiv['usd'],
                    'qty_pending_stock' => $qty,
                    'stock_receipt_ready' => true,
                ];

                $subtotal = Money::add($subtotal, $lineSubtotal);
                $linesTax = Money::add($linesTax, $lineTax);
                $linesDiscount = Money::add($linesDiscount, $lineDiscount);
            }

            $headerTax = Money::normalize($data['tax_amount'] ?? $linesTax);
            $otherTaxes = Money::normalize($data['other_taxes'] ?? '0');
            $headerDiscount = Money::normalize($data['discount_amount'] ?? $linesDiscount);
            $total = Money::sub(Money::add(Money::add($subtotal, $headerTax), $otherTaxes), $headerDiscount);
            if (! Money::isPositive($total) && Money::compare($total, '0') !== 0) {
                throw new InvalidArgumentException('Total de compra inválido.');
            }
            if (Money::compare($total, '0') <= 0) {
                throw new InvalidArgumentException('El total de la compra debe ser mayor a cero.');
            }

            $totalEquiv = $this->equivalents($currency->code, $total, $rate);

            $purchase = Purchase::query()->create([
                'supplier_id' => $supplier->id,
                'purchase_date' => $data['purchase_date'] ?? now()->toDateString(),
                'voucher_type' => $data['voucher_type'] ?? null,
                'voucher_letter' => $data['voucher_letter'] ?? null,
                'voucher_number' => $data['voucher_number'] ?? null,
                'currency_id' => $currency->id,
                'exchange_rate_id' => $fx['id'],
                'exchange_rate_value' => $rate,
                'exchange_rate_at' => $fx['at'],
                'subtotal' => $subtotal,
                'tax_amount' => $headerTax,
                'other_taxes' => $otherTaxes,
                'discount_amount' => $headerDiscount,
                'total' => $total,
                'total_ars' => $totalEquiv['ars'],
                'total_usd' => $totalEquiv['usd'],
                'payment_mode' => $mode,
                'status' => MovementStatus::Posted,
                'financial_account_id' => $data['financial_account_id'] ?? null,
                'user_id' => Auth::id() ?? throw new RuntimeException('Usuario requerido.'),
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($normalizedLines as $line) {
                $purchase->items()->create($line);
            }

            if ($mode === Purchase::MODE_CASH) {
                $accountId = (int) ($data['financial_account_id'] ?? 0);
                if ($accountId <= 0) {
                    throw new InvalidArgumentException('Compra contado requiere cuenta de pago.');
                }
                $account = FinancialAccount::query()->with('currency')->lockForUpdate()->findOrFail($accountId);
                if ($account->currency_id !== $currency->id) {
                    throw new InvalidArgumentException('La cuenta de pago debe coincidir con la moneda de la compra.');
                }

                $movement = $this->movements->createSimple([
                    'type' => 'expense',
                    'scope' => 'professional',
                    'financial_account_id' => $account->id,
                    'amount' => $total,
                    'movement_date' => $purchase->purchase_date->toDateString(),
                    'description' => 'Compra contado #'.$purchase->id.' — '.$supplier->name,
                    'exchange_rate_id' => $fx['id'],
                    'supplier_id' => $supplier->id,
                ]);

                $purchase->update([
                    'financial_account_id' => $account->id,
                    'financial_movement_id' => $movement->id,
                ]);
            } else {
                // Crédito: obligación en CC, sin salida de dinero.
                $obligation = $this->supplierLedger->createEntry($supplier, SupplierLedgerType::Charge, [
                    'currency_code' => $currency->code,
                    'amount' => $total,
                    'entry_date' => $purchase->purchase_date->toDateString(),
                    'description' => 'Compra a crédito #'.$purchase->id,
                    'exchange_rate_id' => $fx['id'],
                    'purchase_id' => $purchase->id,
                ], sign: -1, wrapTransaction: false);

                $purchase->update([
                    'obligation_ledger_entry_id' => $obligation->id,
                ]);
            }

            // Ingreso de stock + lotes FIFO para líneas con producto físico (única operación de ingreso).
            $this->inventory->receiveFromPurchase($purchase->fresh(['items']), wrapTransaction: false);

            if (! empty($data['force_fail'])) {
                throw new RuntimeException('Falla simulada en compra.');
            }

            $this->audit->log('purchase_created', $purchase, null, $purchase->only([
                'supplier_id', 'total', 'payment_mode', 'currency_id', 'exchange_rate_value', 'financial_movement_id', 'obligation_ledger_entry_id',
            ]), 'Compra creada');

            return $purchase->fresh(['items', 'supplier', 'currency', 'financialMovement', 'obligationLedgerEntry']);
        });
    }

    /**
     * Anulación de compra:
     * - Contado: anula el egreso financiero vinculado (única salida de dinero).
     * - Crédito: anula la obligación en CC; bloquea si hay pagos posted vinculados a la compra.
     * - Stock: anula ingresos posted si los lotes no fueron consumidos; si ya hubo consumo, bloquea.
     * Los documentos quedan asociados (no se borran).
     */
    public function void(Purchase $purchase, string $reason): void
    {
        if (! $purchase->isPosted()) {
            throw new InvalidArgumentException('La compra ya está anulada.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('La anulación requiere motivo.');
        }

        DB::transaction(function () use ($purchase, $reason) {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);

            $receipts = InventoryMovement::query()
                ->posted()
                ->where('purchase_id', $purchase->id)
                ->where('type', InventoryMovementType::Receipt->value)
                ->lockForUpdate()
                ->get();

            foreach ($receipts as $receipt) {
                $this->inventory->void($receipt, $reason);
            }

            if ($purchase->isCash()) {
                if ($purchase->financial_movement_id) {
                    $movement = $purchase->financialMovement()->lockForUpdate()->first();
                    if ($movement && $movement->isPosted()) {
                        $this->movements->void($movement, $reason);
                    }
                }
            } else {
                $linkedPayments = SupplierLedgerEntry::query()
                    ->posted()
                    ->where('purchase_id', $purchase->id)
                    ->where('type', SupplierLedgerType::Payment->value)
                    ->exists();

                if ($linkedPayments) {
                    throw new InvalidArgumentException('No se puede anular: hay pagos registrados vinculados a esta compra.');
                }

                if ($purchase->obligation_ledger_entry_id) {
                    $obligation = SupplierLedgerEntry::query()->lockForUpdate()->find($purchase->obligation_ledger_entry_id);
                    if ($obligation && $obligation->isPosted()) {
                        $this->supplierLedger->void($obligation, $reason);
                    }
                }
            }

            $purchase->update([
                'status' => MovementStatus::Voided,
                'void_reason' => $reason,
                'voided_by' => Auth::id(),
                'voided_at' => now(),
            ]);

            $this->audit->log('purchase_voided', $purchase, ['status' => 'posted'], [
                'status' => 'voided',
                'reason' => $reason,
            ], 'Compra anulada');
        });
    }

    private function resolveFx(?int $exchangeRateId): array
    {
        if ($exchangeRateId) {
            $rate = \App\Models\ExchangeRate::query()->findOrFail($exchangeRateId);

            return [
                'id' => $rate->id,
                'value' => Money::normalize($rate->rate, 6),
                'at' => $rate->rate_at,
            ];
        }

        try {
            $latest = $this->rates->latestOfficialSell(false)['rate'];
        } catch (Throwable) {
            throw new RuntimeException('Se requiere una cotización vigente para registrar la compra.');
        }

        return [
            'id' => $latest->id,
            'value' => Money::normalize($latest->rate, 6),
            'at' => $latest->rate_at,
        ];
    }

    private function equivalents(string $currencyCode, string $amount, string $rate): array
    {
        if ($currencyCode === 'ARS') {
            return ['ars' => $amount, 'usd' => Money::div($amount, $rate)];
        }
        if ($currencyCode === 'USD') {
            return ['ars' => Money::mul($amount, $rate), 'usd' => $amount];
        }
        throw new InvalidArgumentException('Moneda no soportada.');
    }

    private function unitCosts(string $currencyCode, string $unitPrice, string $rate): array
    {
        if ($currencyCode === 'ARS') {
            return [
                'ars' => Money::normalize($unitPrice, 6),
                'usd' => Money::normalize(bcdiv($unitPrice, $rate, 10), 6),
            ];
        }

        return [
            'ars' => Money::normalize(bcmul($unitPrice, $rate, 10), 6),
            'usd' => Money::normalize($unitPrice, 6),
        ];
    }
}
