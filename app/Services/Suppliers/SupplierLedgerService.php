<?php

namespace App\Services\Suppliers;

use App\Enums\MovementStatus;
use App\Enums\SupplierLedgerType;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Services\AuditLogger;
use App\Services\Finance\ExchangeRateService;
use App\Services\Finance\MovementService;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class SupplierLedgerService
{
    public function __construct(
        private readonly MovementService $movements,
        private readonly ExchangeRateService $rates,
        private readonly AuditLogger $audit,
    ) {}

    public function balanceFor(Supplier $supplier, Currency|int|string $currency): string
    {
        $currencyId = $this->resolveCurrencyId($currency);

        $sum = SupplierLedgerEntry::query()
            ->posted()
            ->where('supplier_id', $supplier->id)
            ->where('currency_id', $currencyId)
            ->sum('signed_amount');

        return Money::normalize((string) $sum);
    }

    /**
     * @return array{ARS: string, USD: string}
     */
    public function balances(Supplier $supplier): array
    {
        return [
            'ARS' => $this->balanceFor($supplier, 'ARS'),
            'USD' => $this->balanceFor($supplier, 'USD'),
        ];
    }

    public function registerCharge(Supplier $supplier, array $data): SupplierLedgerEntry
    {
        return $this->createEntry($supplier, SupplierLedgerType::Charge, $data, sign: -1);
    }

    public function registerCredit(Supplier $supplier, array $data): SupplierLedgerEntry
    {
        return $this->createEntry($supplier, SupplierLedgerType::Credit, $data, sign: 1);
    }

    public function applyCredit(Supplier $supplier, array $data): SupplierLedgerEntry
    {
        $currencyCode = strtoupper((string) $data['currency_code']);
        $amount = Money::normalize($data['amount']);
        $available = $this->balanceFor($supplier, $currencyCode);

        if (Money::compare($available, '0') <= 0) {
            throw new InvalidArgumentException('No hay crédito a favor con el proveedor en '.$currencyCode.'.');
        }
        if (Money::compare($amount, $available) > 0) {
            throw new InvalidArgumentException('El crédito a aplicar supera el saldo a favor ('.$available.').');
        }

        $data['description'] = $data['description'] ?? 'Aplicación de crédito con proveedor';

        return $this->createEntry($supplier, SupplierLedgerType::CreditApplication, $data, sign: -1);
    }

    public function registerAdjustment(Supplier $supplier, array $data): SupplierLedgerEntry
    {
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new InvalidArgumentException('El ajuste requiere un motivo.');
        }
        $sign = (int) ($data['sign'] ?? 0);
        if (! in_array($sign, [-1, 1], true)) {
            throw new InvalidArgumentException('El ajuste requiere signo: -1 (deuda) o +1 (a favor).');
        }
        $data['reason'] = $reason;

        return $this->createEntry($supplier, SupplierLedgerType::Adjustment, $data, sign: $sign);
    }

    /**
     * @return array{ledger: SupplierLedgerEntry, movement: \App\Models\Movement}
     */
    public function registerPayment(Supplier $supplier, array $data): array
    {
        return app(SupplierPaymentService::class)->pay($supplier, $data);
    }

    public function void(SupplierLedgerEntry $entry, string $reason): void
    {
        if (! $entry->isPosted()) {
            throw new InvalidArgumentException('El movimiento de CC ya está anulado.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('La anulación requiere motivo.');
        }

        DB::transaction(function () use ($entry, $reason) {
            $entry = SupplierLedgerEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($entry->financial_movement_id) {
                $movement = $entry->financialMovement()->lockForUpdate()->first();
                if ($movement && $movement->isPosted()) {
                    $this->movements->void($movement, $reason);
                }
            }

            $entry->update([
                'status' => MovementStatus::Voided,
                'void_reason' => $reason,
                'voided_by' => Auth::id(),
                'voided_at' => now(),
            ]);

            $this->audit->log('supplier_ledger_voided', $entry, ['status' => 'posted'], [
                'status' => 'voided',
                'reason' => $reason,
            ], 'CC proveedor anulada');
        });
    }

    /**
     * @internal usado por PurchaseService / SupplierPaymentService dentro de transacciones externas
     */
    public function createEntry(
        Supplier $supplier,
        SupplierLedgerType $type,
        array $data,
        int $sign,
        bool $wrapTransaction = true,
    ): SupplierLedgerEntry {
        if (! $supplier->isActive()) {
            throw new InvalidArgumentException('El proveedor no está activo.');
        }

        $callback = function () use ($supplier, $type, $data, $sign) {
            $amount = Money::normalize($data['amount']);
            if (! Money::isPositive($amount)) {
                throw new InvalidArgumentException('El importe debe ser mayor a cero.');
            }

            $currency = Currency::query()->where('code', strtoupper((string) $data['currency_code']))->firstOrFail();
            $fx = $this->resolveFx($data['exchange_rate_id'] ?? null);
            $equivalents = $this->equivalents($currency->code, $amount, $fx['value']);
            $signed = Money::mul($amount, (string) $sign);

            $entry = SupplierLedgerEntry::query()->create([
                'supplier_id' => $supplier->id,
                'currency_id' => $currency->id,
                'type' => $type,
                'amount' => $amount,
                'signed_amount' => $signed,
                'exchange_rate_id' => $fx['id'],
                'exchange_rate_value' => $fx['value'],
                'exchange_rate_at' => $fx['at'],
                'amount_ars' => $equivalents['ars'],
                'amount_usd' => $equivalents['usd'],
                'entry_date' => $data['entry_date'] ?? now()->toDateString(),
                'entry_time' => $data['entry_time'] ?? now()->format('H:i:s'),
                'user_id' => Auth::id() ?? throw new RuntimeException('Usuario requerido.'),
                'description' => $data['description'] ?? null,
                'reason' => $data['reason'] ?? null,
                'status' => MovementStatus::Posted,
                'financial_movement_id' => $data['financial_movement_id'] ?? null,
                'purchase_id' => $data['purchase_id'] ?? null,
            ]);

            if (! empty($data['force_fail'])) {
                throw new RuntimeException('Falla simulada en CC proveedor.');
            }

            $this->audit->log('supplier_ledger_'.$type->value, $entry, null, $entry->only([
                'supplier_id', 'type', 'amount', 'signed_amount', 'currency_id', 'purchase_id', 'financial_movement_id',
            ]), 'CC proveedor: '.$type->label());

            return $entry->fresh(['currency', 'financialMovement']);
        };

        return $wrapTransaction ? DB::transaction($callback) : $callback();
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
            throw new RuntimeException('Se requiere una cotización vigente.');
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

    private function resolveCurrencyId(Currency|int|string $currency): int
    {
        if ($currency instanceof Currency) {
            return $currency->id;
        }
        if (is_int($currency) || ctype_digit((string) $currency)) {
            return (int) $currency;
        }

        return Currency::query()->where('code', strtoupper((string) $currency))->value('id')
            ?? throw new InvalidArgumentException('Moneda inválida.');
    }
}
