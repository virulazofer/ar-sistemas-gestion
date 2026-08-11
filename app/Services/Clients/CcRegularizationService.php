<?php

namespace App\Services\Clients;

use App\Enums\ClientLedgerType;
use App\Models\Client;
use App\Models\ClientLedgerEntry;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CcRegularizationService
{
    public function __construct(
        private readonly ClientLedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Regularización auditada: siempre genera movimiento; nunca edita saldo directo.
     *
     * @param  array{
     *   currency_code: string,
     *   amount: string|float|int,
     *   sign: int,
     *   reason: string,
     *   regularization_kind: string,
     *   entry_date?: string,
     *   description?: string|null,
     *   related_ledger_entry_id?: int|null
     * }  $data
     */
    public function regularize(Client $client, array $data): ClientLedgerEntry
    {
        $kind = trim((string) ($data['regularization_kind'] ?? ''));
        $allowed = [
            'omitted_charge',
            'omitted_payment',
            'opening_balance',
            'misapplied_payment',
            'historical_correction',
            'reclassification',
            'other',
        ];
        if (! in_array($kind, $allowed, true)) {
            throw new InvalidArgumentException('Tipo de regularización inválido.');
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new InvalidArgumentException('La regularización requiere motivo.');
        }

        return DB::transaction(function () use ($client, $data, $kind, $reason) {
            $entry = $this->ledger->registerAdjustment($client, [
                'currency_code' => $data['currency_code'],
                'amount' => Money::normalize($data['amount']),
                'sign' => (int) $data['sign'],
                'reason' => $reason,
                'entry_date' => $data['entry_date'] ?? now()->toDateString(),
                'description' => $data['description'] ?? ('Regularización CC: '.$kind),
            ]);

            $entry->update([
                'regularization_kind' => $kind,
                'related_ledger_entry_id' => $data['related_ledger_entry_id'] ?? null,
            ]);

            $this->audit->log('client_cc_regularized', $entry, null, [
                'client_id' => $client->id,
                'kind' => $kind,
                'amount' => $entry->amount,
                'sign' => $data['sign'],
                'reason' => $reason,
                'type' => ClientLedgerType::Adjustment->value,
            ], 'Regularización de cuenta corriente');

            return $entry->fresh();
        });
    }
}
