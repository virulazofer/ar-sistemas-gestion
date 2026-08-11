<?php

namespace App\Services\Commercial;

use App\Enums\ClientLedgerType;
use App\Enums\CommercialChargeType;
use App\Enums\DocumentalStatus;
use App\Enums\ReceiptStatus;
use App\Models\Client;
use App\Models\CommercialCharge;
use App\Models\FinancialAccount;
use App\Models\Receipt;
use App\Models\ReceiptApplication;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\Clients\ClientLedgerService;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ReceiptService
{
    public const OPTION_CREATE_CHARGE = 'create_charge';

    public const OPTION_ON_ACCOUNT = 'on_account';

    public const OPTION_CANCEL = 'cancel';

    public function __construct(
        private readonly ClientLedgerService $ledger,
        private readonly CommercialChargeService $charges,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return Collection<int, CommercialCharge>
     */
    public function openChargesFor(Client $client, string $currencyCode): Collection
    {
        return CommercialCharge::query()
            ->open()
            ->where('client_id', $client->id)
            ->where('currency_code', strtoupper($currencyCode))
            ->orderBy('charged_on')
            ->orderBy('id')
            ->get();
    }

    public function openDebtTotal(Client $client, string $currencyCode): string
    {
        $sum = CommercialCharge::query()
            ->open()
            ->where('client_id', $client->id)
            ->where('currency_code', strtoupper($currencyCode))
            ->sum('amount_open');

        return Money::normalize((string) $sum);
    }

    /**
     * @param  array{
     *   client_id: int,
     *   financial_account_id: int,
     *   amount: string|float|int,
     *   received_on?: string,
     *   concept?: string|null,
     *   notes?: string|null,
     *   application_mode?: string,
     *   applications?: list<array{commercial_charge_id: int, amount: string|float|int}>,
     *   insufficient_option?: string|null,
     *   missing_charge?: array<string, mixed>|null,
     *   documental_status?: string,
     *   force_fail?: bool
     * }  $data
     * @return array{receipt: Receipt, requires_decision?: bool, open_debt?: string, message?: string}
     */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $client = Client::query()->lockForUpdate()->findOrFail($data['client_id']);
            if (! $client->isActive()) {
                throw new InvalidArgumentException('El cliente no está activo.');
            }

            $account = FinancialAccount::query()->with('currency')->lockForUpdate()->findOrFail($data['financial_account_id']);
            if (! $account->isActive()) {
                throw new InvalidArgumentException('La cuenta financiera no está activa.');
            }

            $amount = Money::normalize($data['amount']);
            if (! Money::isPositive($amount)) {
                throw new InvalidArgumentException('El importe del cobro debe ser mayor a cero.');
            }

            $currency = $account->currency->code;
            $openDebt = $this->openDebtTotal($client, $currency);
            $option = $data['insufficient_option'] ?? null;
            $mode = $data['application_mode'] ?? 'auto';

            if (Money::compare($amount, $openDebt) > 0) {
                if ($option === self::OPTION_CANCEL || $option === null) {
                    if ($option === self::OPTION_CANCEL) {
                        throw new InvalidArgumentException('Cobro cancelado por el usuario.');
                    }

                    return [
                        'receipt' => new Receipt,
                        'requires_decision' => true,
                        'open_debt' => $openDebt,
                        'message' => 'No existe deuda suficiente para aplicar este cobro.',
                    ];
                }

                if ($option === self::OPTION_CREATE_CHARGE) {
                    $missing = Money::sub($amount, $openDebt);
                    $mc = $data['missing_charge'] ?? [];
                    $this->charges->create([
                        'client_id' => $client->id,
                        'charge_type' => $mc['charge_type'] ?? CommercialChargeType::Other->value,
                        'concept' => $mc['concept'] ?? ('Cargo faltante por cobro '.$amount),
                        'amount' => $missing,
                        'currency_code' => $currency,
                        'charged_on' => $mc['charged_on'] ?? ($data['received_on'] ?? now()->toDateString()),
                        'scope' => $mc['scope'] ?? 'professional',
                        'documental_status' => $mc['documental_status'] ?? DocumentalStatus::Pending->value,
                        'notes' => $mc['notes'] ?? 'Creado desde cobro (opción A)',
                        'wrap_transaction' => false,
                    ]);
                    $openDebt = $this->openDebtTotal($client, $currency);
                } elseif ($option !== self::OPTION_ON_ACCOUNT) {
                    throw new InvalidArgumentException('Opción de deuda insuficiente inválida.');
                }
            }

            $applications = $this->resolveApplications(
                $client,
                $currency,
                $amount,
                $mode,
                $data['applications'] ?? [],
                $option === self::OPTION_ON_ACCOUNT
            );

            $appliedTotal = '0.00';
            foreach ($applications as $app) {
                $appliedTotal = Money::add($appliedTotal, $app['amount']);
            }

            if (Money::compare($appliedTotal, $amount) > 0) {
                throw new InvalidArgumentException('Las aplicaciones superan el importe del cobro.');
            }

            $onAccount = Money::sub($amount, $appliedTotal);

            $seq = (int) Setting::getValue('receipts.next_sequence', 1);
            $number = sprintf('RC-%06d', $seq);
            Setting::setValue('receipts.next_sequence', $seq + 1, 'int');

            $date = $data['received_on'] ?? now()->toDateString();
            $concept = $data['concept'] ?? ('Cobro cliente '.$client->name);

            // Un solo ingreso financiero por el total del cobro.
            $pay = $this->ledger->registerPayment($client, [
                'financial_account_id' => $account->id,
                'amount' => $amount,
                'entry_date' => $date,
                'description' => $concept,
            ]);

            $receipt = Receipt::query()->create([
                'number' => $number,
                'sequence' => $seq,
                'client_id' => $client->id,
                'received_on' => $date,
                'currency_code' => $currency,
                'amount' => $amount,
                'amount_applied' => $appliedTotal,
                'amount_on_account' => $onAccount,
                'financial_account_id' => $account->id,
                'financial_movement_id' => $pay['movement']->id,
                'client_ledger_entry_id' => $pay['ledger']->id,
                'application_mode' => $mode,
                'insufficient_option' => $option,
                'concept' => $concept,
                'notes' => $data['notes'] ?? null,
                'status' => ReceiptStatus::Posted,
                'documental_status' => DocumentalStatus::tryFrom((string) ($data['documental_status'] ?? 'none')) ?? DocumentalStatus::None,
                'user_id' => Auth::id() ?? throw new RuntimeException('Usuario requerido.'),
            ]);

            $pay['ledger']->update([
                'receipt_id' => $receipt->id,
            ]);

            foreach ($applications as $app) {
                $charge = CommercialCharge::query()->lockForUpdate()->findOrFail($app['commercial_charge_id']);
                if ($charge->client_id !== $client->id || $charge->currency_code !== $currency) {
                    throw new InvalidArgumentException('Cargo inválido para este cobro.');
                }
                if (! $charge->isOpen()) {
                    throw new InvalidArgumentException('El cargo '.$charge->number.' no está abierto.');
                }
                if (Money::compare($app['amount'], (string) $charge->amount_open) > 0) {
                    throw new InvalidArgumentException('Aplicación supera saldo abierto de '.$charge->number);
                }

                ReceiptApplication::query()->create([
                    'receipt_id' => $receipt->id,
                    'commercial_charge_id' => $charge->id,
                    'amount' => $app['amount'],
                    'status' => ReceiptStatus::Posted,
                    'user_id' => Auth::id(),
                ]);

                $this->charges->registerApplicationAmount($charge, $app['amount']);
            }

            if (! empty($data['force_fail'])) {
                throw new RuntimeException('Falla simulada en cobro.');
            }

            $this->audit->log('receipt_created', $receipt, null, [
                'number' => $number,
                'client_id' => $client->id,
                'amount' => $amount,
                'applied' => $appliedTotal,
                'on_account' => $onAccount,
                'movement_id' => $pay['movement']->id,
            ], 'Cobro registrado');

            return ['receipt' => $receipt->fresh(['applications.charge', 'financialAccount', 'client'])];
        });
    }

    public function void(Receipt $receipt, string $reason): Receipt
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('La anulación requiere motivo.');
        }

        return DB::transaction(function () use ($receipt, $reason) {
            $receipt = Receipt::query()->lockForUpdate()->with(['applications.charge', 'ledgerEntry'])->findOrFail($receipt->id);
            if (! $receipt->isPosted()) {
                throw new InvalidArgumentException('El cobro ya está anulado.');
            }

            foreach ($receipt->applications as $app) {
                if (! $app->isPosted()) {
                    continue;
                }
                $charge = CommercialCharge::query()->lockForUpdate()->findOrFail($app->commercial_charge_id);
                $this->charges->reverseApplicationAmount($charge, (string) $app->amount);
                $app->update([
                    'status' => ReceiptStatus::Voided,
                    'void_reason' => $reason,
                    'voided_at' => now(),
                    'voided_by' => Auth::id(),
                ]);
            }

            if ($receipt->client_ledger_entry_id && $receipt->ledgerEntry?->isPosted()) {
                $this->ledger->void($receipt->ledgerEntry, 'Anulación cobro '.$receipt->number.': '.$reason);
            }

            $receipt->update([
                'status' => ReceiptStatus::Voided,
                'void_reason' => $reason,
                'voided_at' => now(),
                'voided_by' => Auth::id(),
            ]);

            $this->audit->log('receipt_voided', $receipt, ['status' => 'posted'], [
                'status' => 'voided',
                'reason' => $reason,
            ], 'Cobro anulado');

            return $receipt->fresh();
        });
    }

    /**
     * @param  list<array{commercial_charge_id: int, amount: string|float|int}>  $manual
     * @return list<array{commercial_charge_id: int, amount: string}>
     */
    private function resolveApplications(
        Client $client,
        string $currency,
        string $amount,
        string $mode,
        array $manual,
        bool $allowPartialCoverage,
    ): array {
        if ($mode === 'manual') {
            $apps = [];
            foreach ($manual as $row) {
                $apps[] = [
                    'commercial_charge_id' => (int) $row['commercial_charge_id'],
                    'amount' => Money::normalize($row['amount']),
                ];
            }

            return $apps;
        }

        // Auto por antigüedad
        $remaining = $amount;
        $apps = [];
        foreach ($this->openChargesFor($client, $currency) as $charge) {
            if (! Money::isPositive($remaining)) {
                break;
            }
            $take = Money::compare($remaining, (string) $charge->amount_open) >= 0
                ? (string) $charge->amount_open
                : $remaining;
            if (! Money::isPositive($take)) {
                continue;
            }
            $apps[] = [
                'commercial_charge_id' => $charge->id,
                'amount' => $take,
            ];
            $remaining = Money::sub($remaining, $take);
        }

        if (! $allowPartialCoverage && Money::isPositive($remaining) && $apps === []) {
            // Sin cargos abiertos: el caller debe haber pedido decisión o on_account.
        }

        return $apps;
    }
}
