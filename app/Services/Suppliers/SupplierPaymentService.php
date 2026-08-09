<?php

namespace App\Services\Suppliers;

use App\Enums\SupplierLedgerType;
use App\Models\FinancialAccount;
use App\Models\Supplier;
use App\Services\AuditLogger;
use App\Services\Finance\MovementService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Coordina pago a proveedor: CC + egreso financiero (atómico).
 * No duplica la salida de dinero de una compra contado.
 */
class SupplierPaymentService
{
    public function __construct(
        private readonly SupplierLedgerService $ledger,
        private readonly MovementService $movements,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{
     *   financial_account_id: int,
     *   amount: string|float|int,
     *   entry_date?: string,
     *   description?: string|null,
     *   purchase_id?: int|null,
     *   exchange_rate_id?: int|null,
     *   force_fail_finance?: bool,
     *   force_fail_after_ledger?: bool,
     *   force_fail_after_finance?: bool
     * }  $data
     * @return array{ledger: \App\Models\SupplierLedgerEntry, movement: \App\Models\Movement}
     */
    public function pay(Supplier $supplier, array $data): array
    {
        if (! $supplier->isActive()) {
            throw new InvalidArgumentException('El proveedor no está activo.');
        }

        return DB::transaction(function () use ($supplier, $data) {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            $account = FinancialAccount::query()->with('currency')->lockForUpdate()->findOrFail($data['financial_account_id']);
            if (! $account->isActive()) {
                throw new InvalidArgumentException('La cuenta financiera no está activa.');
            }

            $amount = Money::normalize($data['amount']);
            if (! Money::isPositive($amount)) {
                throw new InvalidArgumentException('El importe debe ser mayor a cero.');
            }

            $date = $data['entry_date'] ?? now()->toDateString();
            $description = $data['description'] ?? ('Pago proveedor '.$supplier->name);

            $movement = $this->movements->createSimple([
                'type' => 'expense',
                'scope' => 'professional',
                'financial_account_id' => $account->id,
                'amount' => $amount,
                'movement_date' => $date,
                'description' => $description,
                'exchange_rate_id' => $data['exchange_rate_id'] ?? null,
                'supplier_id' => $supplier->id,
                'force_fail' => ! empty($data['force_fail_finance']),
            ]);

            if (! empty($data['force_fail_after_finance'])) {
                throw new RuntimeException('Falla simulada después del movimiento financiero.');
            }

            $entry = $this->ledger->createEntry($supplier, SupplierLedgerType::Payment, [
                'currency_code' => $account->currency->code,
                'amount' => $amount,
                'entry_date' => $date,
                'description' => $description,
                'exchange_rate_id' => $movement->exchange_rate_id,
                'financial_movement_id' => $movement->id,
                'purchase_id' => $data['purchase_id'] ?? null,
                'force_fail' => ! empty($data['force_fail_after_ledger']),
            ], sign: 1, wrapTransaction: false);

            $this->audit->log('supplier_payment_registered', $entry, null, [
                'supplier_id' => $supplier->id,
                'ledger_id' => $entry->id,
                'financial_movement_id' => $movement->id,
                'amount' => $amount,
                'currency' => $account->currency->code,
                'purchase_id' => $data['purchase_id'] ?? null,
            ], 'Pago a proveedor (CC + finanzas)');

            return [
                'ledger' => $entry->fresh(['currency', 'financialMovement']),
                'movement' => $movement,
            ];
        });
    }
}
