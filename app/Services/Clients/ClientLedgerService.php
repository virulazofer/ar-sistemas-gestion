<?php

namespace App\Services\Clients;

use App\Enums\ClientLedgerType;
use App\Enums\MovementStatus;
use App\Models\Client;
use App\Models\ClientLedgerEntry;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Services\AuditLogger;
use App\Services\Finance\ExchangeRateService;
use App\Services\Finance\MovementService;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ClientLedgerService
{
    public function __construct(
        private readonly MovementService $movements,
        private readonly ExchangeRateService $rates,
        private readonly AuditLogger $audit,
    ) {}

    public function balanceFor(Client $client, Currency|int|string $currency): string
    {
        $currencyId = $this->resolveCurrencyId($currency);

        $sum = ClientLedgerEntry::query()
            ->posted()
            ->where('client_id', $client->id)
            ->where('currency_id', $currencyId)
            ->sum('signed_amount');

        return Money::normalize((string) $sum);
    }

    /**
     * @return array{ARS: string, USD: string}
     */
    public function balances(Client $client): array
    {
        return [
            'ARS' => $this->balanceFor($client, 'ARS'),
            'USD' => $this->balanceFor($client, 'USD'),
        ];
    }

    /**
     * Cargo: aumenta deuda del cliente. NO mueve caja/banco.
     *
     * @param  array{
     *   currency_code: string,
     *   amount: string|float|int,
     *   entry_date?: string,
     *   description?: string|null,
     *   exchange_rate_id?: int|null,
     *   force_fail?: bool
     * }  $data
     */
    public function registerCharge(Client $client, array $data): ClientLedgerEntry
    {
        return $this->createEntry($client, ClientLedgerType::Charge, $data, sign: -1, requiresFinance: false);
    }

    /**
     * Crédito a favor del cliente. NO mueve caja/banco.
     *
     * @param  array{
     *   currency_code: string,
     *   amount: string|float|int,
     *   entry_date?: string,
     *   description?: string|null,
     *   exchange_rate_id?: int|null
     * }  $data
     */
    public function registerCredit(Client $client, array $data): ClientLedgerEntry
    {
        return $this->createEntry($client, ClientLedgerType::Credit, $data, sign: 1, requiresFinance: false);
    }

    /**
     * Aplicar crédito disponible: reduce saldo a favor (mismo efecto contable que un cargo),
     * sin movimiento financiero. Documenta el consumo de crédito.
     */
    public function applyCredit(Client $client, array $data): ClientLedgerEntry
    {
        $currencyCode = strtoupper((string) $data['currency_code']);
        $amount = Money::normalize($data['amount']);
        $available = $this->balanceFor($client, $currencyCode);

        if (Money::compare($available, '0') <= 0) {
            throw new InvalidArgumentException('El cliente no tiene crédito a favor en '.$currencyCode.'.');
        }

        if (Money::compare($amount, $available) > 0) {
            throw new InvalidArgumentException('El crédito a aplicar supera el saldo a favor ('.$available.').');
        }

        $data['description'] = $data['description'] ?? 'Aplicación de crédito a favor';

        return $this->createEntry($client, ClientLedgerType::CreditApplication, $data, sign: -1, requiresFinance: false);
    }

    /**
     * Ajuste controlado. Requiere motivo. sign: -1 deuda / +1 a favor.
     *
     * @param  array{
     *   currency_code: string,
     *   amount: string|float|int,
     *   sign: int,
     *   reason: string,
     *   entry_date?: string,
     *   description?: string|null
     * }  $data
     */
    public function registerAdjustment(Client $client, array $data): ClientLedgerEntry
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

        return $this->createEntry($client, ClientLedgerType::Adjustment, $data, sign: $sign, requiresFinance: false);
    }

    /**
     * Apertura manual de CC: no borra movimientos previos.
     * El saldo de presentación (+ = nos deben / rojo) se convierte a signo ledger (− = deuda).
     *
     * @param  array{
     *   currency_code: string,
     *   balance: string|float|int,
     *   reason: string,
     *   entry_date?: string,
     *   description?: string|null,
     *   set_control_desde?: bool
     * }  $data
     */
    public function registerOpeningBalance(Client $client, array $data): ClientLedgerEntry
    {
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new InvalidArgumentException('La apertura de CC requiere un motivo.');
        }

        $displayBalance = Money::normalize($data['balance']);
        if (Money::isZero($displayBalance)) {
            throw new InvalidArgumentException('El saldo de apertura no puede ser cero.');
        }

        // Presentación: + = nos deben → ledger sign −1 (charge/debt)
        $sign = Money::isPositive($displayBalance) ? -1 : 1;
        $amount = Money::abs($displayBalance);

        return DB::transaction(function () use ($client, $data, $reason, $sign, $amount, $displayBalance) {
            $entry = $this->createEntry($client, ClientLedgerType::Adjustment, [
                'currency_code' => $data['currency_code'],
                'amount' => $amount,
                'entry_date' => $data['entry_date'] ?? now()->toDateString(),
                'description' => $data['description'] ?? 'APERTURA de cuenta corriente',
                'reason' => $reason,
                'regularization_kind' => 'opening_balance',
            ], sign: $sign, requiresFinance: false);

            $entry->update([
                'regularization_kind' => 'opening_balance',
                'description' => $entry->description ?: 'APERTURA de cuenta corriente',
            ]);

            if (! empty($data['set_control_desde'])) {
                $desde = $data['entry_date'] ?? now()->toDateString();
                $client->update(['control_cc_desde' => $desde]);
            }

            $this->audit->log('client_cc_opening', $entry, null, [
                'client_id' => $client->id,
                'display_balance' => $displayBalance,
                'ledger_sign' => $sign,
                'amount' => $amount,
                'currency' => $data['currency_code'],
                'reason' => $reason,
                'kind' => 'APERTURA',
            ], 'Apertura manual de cuenta corriente');

            return $entry->fresh();
        });
    }

    /**
     * Pago de cliente: atómico CC + ingreso financiero.
     *
     * @param  array{
     *   financial_account_id: int,
     *   amount: string|float|int,
     *   entry_date?: string,
     *   description?: string|null,
     *   exchange_rate_id?: int|null,
     *   category_id?: int|null,
     *   subcategory_id?: int|null,
     *   force_fail_after_finance?: bool,
     *   force_fail_after_ledger?: bool,
     *   force_fail_finance?: bool
     * }  $data
     * @return array{ledger: ClientLedgerEntry, movement: \App\Models\Movement}
     */
    public function registerPayment(Client $client, array $data): array
    {
        if (! $client->isActive()) {
            throw new InvalidArgumentException('El cliente no está activo.');
        }

        return DB::transaction(function () use ($client, $data) {
            /** @var Client $client */
            $client = Client::query()->lockForUpdate()->findOrFail($client->id);

            $account = FinancialAccount::query()->with('currency')->lockForUpdate()->findOrFail($data['financial_account_id']);
            if (! $account->isActive()) {
                throw new InvalidArgumentException('La cuenta financiera no está activa.');
            }

            $amount = Money::normalize($data['amount']);
            if (! Money::isPositive($amount)) {
                throw new InvalidArgumentException('El importe debe ser mayor a cero.');
            }

            $currency = $account->currency;
            $date = $data['entry_date'] ?? now()->toDateString();
            $description = $data['description'] ?? ('Pago cliente '.$client->name);

            $movement = $this->movements->createSimple([
                'type' => 'income',
                'scope' => 'professional',
                'financial_account_id' => $account->id,
                'amount' => $amount,
                'movement_date' => $date,
                'description' => $description,
                'exchange_rate_id' => $data['exchange_rate_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'subcategory_id' => $data['subcategory_id'] ?? null,
                'client_id' => $client->id,
                'force_fail' => ! empty($data['force_fail_finance']),
            ]);

            if (! empty($data['force_fail_after_finance'])) {
                throw new RuntimeException('Falla simulada después del movimiento financiero.');
            }

            $entry = $this->createEntry($client, ClientLedgerType::Payment, [
                'currency_code' => $currency->code,
                'amount' => $amount,
                'entry_date' => $date,
                'description' => $description,
                'exchange_rate_id' => $movement->exchange_rate_id,
                'financial_movement_id' => $movement->id,
                'sale_id' => $data['sale_id'] ?? null,
                'quote_id' => $data['quote_id'] ?? null,
                'force_fail' => ! empty($data['force_fail_after_ledger']),
            ], sign: 1, requiresFinance: false, wrapTransaction: false);

            $this->audit->log('client_payment_registered', $entry, null, [
                'client_id' => $client->id,
                'ledger_id' => $entry->id,
                'financial_movement_id' => $movement->id,
                'amount' => $amount,
                'currency' => $currency->code,
            ], 'Pago de cliente registrado (CC + finanzas)');

            return [
                'ledger' => $entry->fresh(['currency', 'financialMovement']),
                'movement' => $movement,
            ];
        });
    }

    public function void(ClientLedgerEntry $entry, string $reason): void
    {
        if (! $entry->isPosted()) {
            throw new InvalidArgumentException('El movimiento de CC ya está anulado.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('La anulación requiere motivo.');
        }

        DB::transaction(function () use ($entry, $reason) {
            $entry = ClientLedgerEntry::query()->lockForUpdate()->findOrFail($entry->id);

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

            $this->audit->log('client_ledger_voided', $entry, ['status' => 'posted'], [
                'status' => 'voided',
                'reason' => $reason,
            ], 'Movimiento de cuenta corriente anulado');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createEntry(
        Client $client,
        ClientLedgerType $type,
        array $data,
        int $sign,
        bool $requiresFinance = false,
        bool $wrapTransaction = true,
    ): ClientLedgerEntry {
        if (! $client->isActive()) {
            throw new InvalidArgumentException('El cliente no está activo.');
        }

        $callback = function () use ($client, $type, $data, $sign, $requiresFinance) {
            $amount = Money::normalize($data['amount']);
            if (! Money::isPositive($amount)) {
                throw new InvalidArgumentException('El importe debe ser mayor a cero.');
            }

            $currency = Currency::query()->where('code', strtoupper((string) $data['currency_code']))->firstOrFail();
            $entryDate = $data['entry_date'] ?? now()->toDateString();
            $fx = $this->resolveFx($data['exchange_rate_id'] ?? null, $entryDate);
            $equivalents = $this->equivalents($currency->code, $amount, $fx['value']);
            $signed = Money::mul($amount, (string) $sign);

            $entry = ClientLedgerEntry::query()->create([
                'client_id' => $client->id,
                'currency_id' => $currency->id,
                'type' => $type,
                'amount' => $amount,
                'signed_amount' => $signed,
                'exchange_rate_id' => $fx['id'],
                'exchange_rate_value' => $fx['value'],
                'exchange_rate_at' => $fx['at'],
                'amount_ars' => $equivalents['ars'],
                'amount_usd' => $equivalents['usd'],
                'entry_date' => $entryDate,
                'entry_time' => $data['entry_time'] ?? now()->format('H:i:s'),
                'user_id' => Auth::id() ?? throw new RuntimeException('Usuario requerido.'),
                'description' => $data['description'] ?? null,
                'reason' => $data['reason'] ?? null,
                'status' => MovementStatus::Posted,
                'financial_movement_id' => $data['financial_movement_id'] ?? null,
                'work_order_id' => $data['work_order_id'] ?? null,
                'subscription_id' => $data['subscription_id'] ?? null,
                'invoice_id' => $data['invoice_id'] ?? null,
                'quote_id' => $data['quote_id'] ?? null,
                'sale_id' => $data['sale_id'] ?? null,
                'event_id' => $data['event_id'] ?? null,
                'document_id' => $data['document_id'] ?? null,
                'commercial_charge_id' => $data['commercial_charge_id'] ?? null,
                'receipt_id' => $data['receipt_id'] ?? null,
                'regularization_kind' => $data['regularization_kind'] ?? null,
                'related_ledger_entry_id' => $data['related_ledger_entry_id'] ?? null,
            ]);

            if (! empty($data['force_fail'])) {
                throw new RuntimeException('Falla simulada en cuenta corriente.');
            }

            if ($requiresFinance && ! $entry->financial_movement_id) {
                throw new RuntimeException('Este tipo de movimiento requiere vínculo financiero.');
            }

            $this->audit->log('client_ledger_'.$type->value, $entry, null, $entry->only([
                'client_id', 'type', 'amount', 'signed_amount', 'currency_id', 'financial_movement_id',
            ]), 'CC: '.$type->label());

            return $entry->fresh(['currency', 'financialMovement']);
        };

        return $wrapTransaction ? DB::transaction($callback) : $callback();
    }

    /**
     * @return array{id: ?int, value: string, at: mixed}
     */
    private function resolveFx(?int $exchangeRateId, ?string $asOfDate = null): array
    {
        if ($exchangeRateId) {
            $rate = \App\Models\ExchangeRate::query()->findOrFail($exchangeRateId);

            return [
                'id' => $rate->id,
                'value' => Money::normalize($rate->rate, 6),
                'at' => $rate->rate_at,
            ];
        }

        $rate = $asOfDate ? $this->rates->rateForDate($asOfDate) : null;
        if (! $rate) {
            try {
                $rate = $this->rates->latestOfficialSell(false)['rate'];
            } catch (Throwable) {
                throw new RuntimeException('Se requiere una cotización vigente para registrar el movimiento de CC.');
            }
        }

        return [
            'id' => $rate->id,
            'value' => Money::normalize($rate->rate, 6),
            'at' => $rate->rate_at,
        ];
    }

    /**
     * @return array{ars: string, usd: string}
     */
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
