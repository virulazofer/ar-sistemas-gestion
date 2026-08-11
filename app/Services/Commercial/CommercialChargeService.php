<?php

namespace App\Services\Commercial;

use App\Enums\ClientLedgerType;
use App\Enums\CommercialChargeStatus;
use App\Enums\CommercialChargeType;
use App\Enums\DocumentalStatus;
use App\Models\Client;
use App\Models\CommercialCharge;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\Clients\ClientLedgerService;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class CommercialChargeService
{
    public function __construct(
        private readonly ClientLedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{
     *   client_id: int,
     *   charge_type: string,
     *   concept: string,
     *   amount: string|float|int,
     *   currency_code: string,
     *   charged_on?: string,
     *   due_on?: string|null,
     *   scope?: string,
     *   notes?: string|null,
     *   documental_status?: string,
     *   sale_id?: int|null,
     *   subscription_id?: int|null,
     *   subscription_period_id?: int|null,
     *   work_order_id?: int|null,
     *   apply_available_credit?: bool,
     *   create_cc?: bool,
     *   wrap_transaction?: bool
     * }  $data
     */
    public function create(array $data): CommercialCharge
    {
        $callback = function () use ($data) {
            $client = Client::query()->lockForUpdate()->findOrFail($data['client_id']);
            if (! $client->isActive()) {
                throw new InvalidArgumentException('El cliente no está activo.');
            }

            $type = CommercialChargeType::from($data['charge_type']);
            $amount = Money::normalize($data['amount']);
            if (! Money::isPositive($amount)) {
                throw new InvalidArgumentException('El importe del cargo debe ser mayor a cero.');
            }

            $currency = strtoupper((string) $data['currency_code']);
            $seq = (int) Setting::getValue('commercial_charges.next_sequence', 1);
            $number = sprintf('CG-%06d', $seq);
            Setting::setValue('commercial_charges.next_sequence', $seq + 1, 'int');

            $documental = DocumentalStatus::tryFrom((string) ($data['documental_status'] ?? DocumentalStatus::None->value))
                ?? DocumentalStatus::None;

            $charge = CommercialCharge::query()->create([
                'number' => $number,
                'sequence' => $seq,
                'client_id' => $client->id,
                'charge_type' => $type,
                'concept' => $data['concept'],
                'charged_on' => $data['charged_on'] ?? now()->toDateString(),
                'due_on' => $data['due_on'] ?? null,
                'currency_code' => $currency,
                'amount' => $amount,
                'amount_applied' => '0.00',
                'amount_open' => $amount,
                'scope' => $data['scope'] ?? 'professional',
                'status' => CommercialChargeStatus::Pending,
                'documental_status' => $documental,
                'notes' => $data['notes'] ?? null,
                'sale_id' => $data['sale_id'] ?? null,
                'subscription_id' => $data['subscription_id'] ?? null,
                'subscription_period_id' => $data['subscription_period_id'] ?? null,
                'work_order_id' => $data['work_order_id'] ?? null,
                'user_id' => Auth::id() ?? throw new RuntimeException('Usuario requerido.'),
            ]);

            $createCc = $data['create_cc'] ?? true;
            if ($createCc) {
                $entry = $this->ledger->createEntry(
                    $client,
                    ClientLedgerType::Charge,
                    [
                        'currency_code' => $currency,
                        'amount' => $amount,
                        'entry_date' => $charge->charged_on->toDateString(),
                        'description' => $charge->concept,
                        'sale_id' => $charge->sale_id,
                        'subscription_id' => $charge->subscription_id,
                        'work_order_id' => $charge->work_order_id,
                        'commercial_charge_id' => $charge->id,
                    ],
                    sign: -1,
                    requiresFinance: false,
                    wrapTransaction: false,
                );
                $charge->update(['client_ledger_entry_id' => $entry->id]);
            }

            if (! empty($data['apply_available_credit'])) {
                $this->consumeAvailableCredit($charge->fresh());
            }

            $this->audit->log('commercial_charge_created', $charge, null, [
                'number' => $number,
                'client_id' => $client->id,
                'amount' => $amount,
                'currency' => $currency,
                'type' => $type->value,
            ], 'Cargo comercial creado');

            return $charge->fresh(['client', 'ledgerEntry']);
        };

        return ($data['wrap_transaction'] ?? true) ? DB::transaction($callback) : $callback();
    }

    public function consumeAvailableCredit(CommercialCharge $charge): CommercialCharge
    {
        if (! $charge->isOpen()) {
            return $charge;
        }

        return DB::transaction(function () use ($charge) {
            $charge = CommercialCharge::query()->lockForUpdate()->findOrFail($charge->id);
            $client = Client::query()->lockForUpdate()->findOrFail($charge->client_id);

            // El cargo ya generó CC IN. Si aún queda saldo a favor (ledger > 0),
            // ese favor ya neteó parte/toda la deuda: solo marcar aplicación comercial
            // sin nuevo movimiento ledger (evitar doble efecto).
            $balance = $this->ledger->balanceFor($client, $charge->currency_code);
            if (Money::compare($balance, '0') < 0) {
                // Deuda neta remanente: favor previo cubrió (amount_open - |balance|).
                $debt = Money::mul($balance, '-1');
                $covered = Money::sub((string) $charge->amount_open, $debt);
                if (! Money::isPositive($covered)) {
                    return $charge;
                }
                $apply = Money::compare($covered, (string) $charge->amount_open) > 0
                    ? (string) $charge->amount_open
                    : $covered;
            } else {
                // Favor remanente o cero: cargo totalmente cubierto por saldo a favor.
                $apply = (string) $charge->amount_open;
            }

            if (! Money::isPositive($apply)) {
                return $charge;
            }

            $this->registerApplicationAmount($charge, $apply);

            $this->audit->log('commercial_charge_credit_applied', $charge, null, [
                'amount' => $apply,
                'note' => 'Marca comercial: favor neto ya reflejado por CC IN del cargo',
            ], 'Saldo a favor aplicado a cargo');

            return $charge->fresh();
        });
    }

    public function registerApplicationAmount(CommercialCharge $charge, string $amount): void
    {
        $amount = Money::normalize($amount);
        $applied = Money::add((string) $charge->amount_applied, $amount);
        $open = Money::sub((string) $charge->amount, $applied);
        if (Money::compare($open, '0') < 0) {
            throw new InvalidArgumentException('La aplicación supera el importe del cargo '.$charge->number);
        }

        $status = Money::compare($open, '0') === 0
            ? CommercialChargeStatus::Collected
            : (Money::compare($applied, '0') > 0 ? CommercialChargeStatus::Partial : CommercialChargeStatus::Pending);

        $charge->update([
            'amount_applied' => $applied,
            'amount_open' => $open,
            'status' => $status,
        ]);
    }

    public function reverseApplicationAmount(CommercialCharge $charge, string $amount): void
    {
        $amount = Money::normalize($amount);
        $applied = Money::sub((string) $charge->amount_applied, $amount);
        if (Money::compare($applied, '0') < 0) {
            $applied = '0.00';
        }
        $open = Money::sub((string) $charge->amount, $applied);
        $status = Money::compare($open, (string) $charge->amount) === 0
            ? CommercialChargeStatus::Pending
            : (Money::compare($open, '0') === 0 ? CommercialChargeStatus::Collected : CommercialChargeStatus::Partial);

        if ($charge->status === CommercialChargeStatus::Voided) {
            return;
        }

        $charge->update([
            'amount_applied' => $applied,
            'amount_open' => $open,
            'status' => $status,
        ]);
    }

    public function void(CommercialCharge $charge, string $reason): CommercialCharge
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('La anulación requiere motivo.');
        }

        return DB::transaction(function () use ($charge, $reason) {
            $charge = CommercialCharge::query()->lockForUpdate()->with('applications')->findOrFail($charge->id);
            if ($charge->status === CommercialChargeStatus::Voided) {
                throw new InvalidArgumentException('El cargo ya está anulado.');
            }

            $postedApps = $charge->applications->where('status.value', 'posted')->count();
            // Fallback if cast comparison differs
            $postedApps = $charge->applications()->where('status', 'posted')->count();
            if ($postedApps > 0) {
                throw new InvalidArgumentException('No se puede anular un cargo con cobros aplicados. Anule primero los cobros.');
            }

            if ($charge->client_ledger_entry_id) {
                $entry = $charge->ledgerEntry;
                if ($entry && $entry->isPosted()) {
                    $this->ledger->void($entry, 'Anulación cargo '.$charge->number.': '.$reason);
                }
            }

            $charge->update([
                'status' => CommercialChargeStatus::Voided,
                'amount_open' => '0.00',
                'void_reason' => $reason,
                'voided_at' => now(),
                'voided_by' => Auth::id(),
            ]);

            $this->audit->log('commercial_charge_voided', $charge, ['status' => 'pending'], [
                'status' => 'voided',
                'reason' => $reason,
            ], 'Cargo comercial anulado');

            return $charge->fresh();
        });
    }

    public function setDocumentalStatus(CommercialCharge $charge, DocumentalStatus $status): CommercialCharge
    {
        $old = $charge->documental_status;
        $charge->update(['documental_status' => $status]);
        $this->audit->log('commercial_charge_documental', $charge, [
            'documental_status' => $old?->value,
        ], [
            'documental_status' => $status->value,
        ], 'Estado documental de cargo');

        return $charge->fresh();
    }
}
