<?php

namespace App\Services\Imports\Historical;

use App\Enums\ClientLedgerType;
use App\Enums\CommercialChargeType;
use App\Enums\DocumentalStatus;
use App\Enums\MovementStatus;
use App\Models\Client;
use App\Models\ClientLedgerEntry;
use App\Models\CommercialCharge;
use App\Models\ImportBatch;
use App\Models\Movement;
use App\Models\Receipt;
use App\Models\ReceiptApplication;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Clients\ClientLedgerService;
use App\Services\Commercial\CommercialChargeService;
use App\Services\Commercial\ReceiptService;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HOTFIX POST-11F-3 — reconciliación histórica DAASA (idempotente).
 * No reimporta 11E completo. Solo repara ops omitidas por formula zero-skip.
 */
class DaasaPost11F3ReconciliationService
{
    public const BATCH_NAME = 'DAASA_POST_11F3_RECONCILIATION_20260811';

    public const REASON = 'stage11e_formula_zero_skip_repair';

    public const CLIENT_CODE = 3;

    public const CLIENT_ID = 12;

    public const IMPORT_BATCH_UUID = '6cd0d4ba-6b62-49dc-be85-ee896bbb7d92';

    public function __construct(
        private readonly CommercialChargeService $charges,
        private readonly ReceiptService $receipts,
        private readonly ClientLedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(bool $dryRun = false): array
    {
        $admin = User::query()->orderBy('id')->first();
        if (! $admin) {
            throw new RuntimeException('Se requiere un usuario para reconciliar.');
        }
        Auth::login($admin);

        $client = Client::query()->where('code', self::CLIENT_CODE)->where('name', 'like', '%DAASA%')->first()
            ?? Client::query()->where('id', self::CLIENT_ID)->where('code', self::CLIENT_CODE)->first();
        if (! $client) {
            throw new RuntimeException('DAASA code=0003 no encontrado; no renumerar.');
        }
        if ((int) $client->id !== self::CLIENT_ID) {
            // En tests el id puede diferir; en staging debe ser 12.
            $reportNote = 'daasa_id_is_'.$client->id.'_expected_'.self::CLIENT_ID;
        } else {
            $reportNote = null;
        }

        $batch11e = ImportBatch::query()->where('uuid', self::IMPORT_BATCH_UUID)->first();
        if (! $batch11e) {
            throw new RuntimeException('Batch 11E intacto requerido: '.self::IMPORT_BATCH_UUID);
        }

        $report = [
            'batch_name' => self::BATCH_NAME,
            'dry_run' => $dryRun,
            'client' => ['id' => $client->id, 'code' => $client->code, 'name' => $client->name],
            'client_id_note' => $reportNote,
            'import_batch_uuid' => $batch11e->uuid,
            'before_balance_ars' => $this->ledger->balanceFor($client, 'ARS'),
            'actions' => [],
            'skipped' => [],
            'test_10k' => null,
            'formula_classification_before' => $this->classificationCounts(),
        ];

        if ($dryRun) {
            $report['planned'] = $this->plannedOps();

            return $report;
        }

        return DB::transaction(function () use ($client, $batch11e, $report) {
            $report['actions'][] = $this->repairCharge([
                'client' => $client,
                'batch11e' => $batch11e,
                'source_row' => 404,
                'amount' => '1308450.00',
                'charged_on' => '2026-04-16',
                'concept' => 'Hugo Ferreyra para Manu',
                'formula' => '=(255+415+95+100+50)*1430',
                'field' => 'cc_in',
                'charge_type' => CommercialChargeType::Sale->value,
            ]);

            $report['actions'][] = $this->linkHugo466($client, $batch11e);

            $report['actions'][] = $this->repairCharge([
                'client' => $client,
                'batch11e' => $batch11e,
                'source_row' => 484,
                'amount' => '1019560.00',
                'charged_on' => '2026-05-06',
                'concept' => 'DAASA Notebook',
                'formula' => '=718*1420',
                'field' => 'cc_in',
                'charge_type' => CommercialChargeType::Sale->value,
            ]);

            $report['actions'][] = $this->repairCcOutPayment([
                'client' => $client,
                'batch11e' => $batch11e,
                'source_row' => 503,
                'amount' => '1019560.00',
                'received_on' => '2026-05-11',
                'concept' => 'DAASA Pago Notebook',
                'formula' => '=718*1420',
                'charge_source_row' => 484,
                'create_finance' => false,
            ]);

            $report['actions'][] = $this->repairCharge([
                'client' => $client,
                'batch11e' => $batch11e,
                'source_row' => 485,
                'amount' => '2371400.00',
                'charged_on' => '2026-05-06',
                'concept' => 'Hugo Ferreyra - PC monstruo',
                'formula' => '=1670*1420',
                'field' => 'cc_in',
                'charge_type' => CommercialChargeType::Sale->value,
            ]);

            $report['actions'][] = $this->repairCcOutPayment([
                'client' => $client,
                'batch11e' => $batch11e,
                'source_row' => 505,
                'amount' => '2371400.00',
                'received_on' => '2026-05-11',
                'concept' => 'Hugo Ferreyra - Pagó DAASA',
                'formula' => '=1670*1420',
                'charge_source_row' => 485,
                'create_finance' => false,
            ]);

            $report['skipped'][] = [
                'rows' => [473, 512, 532],
                'reason' => 'merca/venta analysis-only o ya importado; no inventar stock/finance',
            ];
            $report['skipped'][] = [
                'rows' => [43, 44, 171, 172, 275, 276, 377, 378, 501, 502, 635, 636, 772, 773],
                'reason' => '14 abonos: incomes 11E existentes; no inventar 16 cargos',
            ];
            $report['skipped'][] = [
                'row' => 637,
                'reason' => 'settlement ya importado; venta/utilidad fórmula analysis-only',
            ];

            $report['test_10k'] = $this->cleanupTest10k($client);
            $report['backfill'] = $this->backfillUnequivocalRelations($client);
            $report['after_balance_ars'] = $this->ledger->balanceFor($client, 'ARS');
            $report['balance_compare'] = $this->balanceCompare($client);

            $this->audit->log('daasa_post_11f3_reconciliation', $client, null, [
                'batch' => self::BATCH_NAME,
                'actions' => count($report['actions']),
                'after_balance_ars' => $report['after_balance_ars'],
            ], 'Reconciliación histórica DAASA POST-11F-3');

            return $report;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function repairCharge(array $op): array
    {
        $marker = $this->marker($op['source_row'], $op['field']);
        $existing = CommercialCharge::query()
            ->where('client_id', $op['client']->id)
            ->where('notes', 'like', '%'.$marker.'%')
            ->first();
        if ($existing) {
            return ['action' => 'charge', 'status' => 'idempotent_skip', 'source_row' => $op['source_row'], 'charge_id' => $existing->id, 'number' => $existing->number];
        }

        $charge = $this->charges->create([
            'client_id' => $op['client']->id,
            'charge_type' => $op['charge_type'],
            'concept' => $op['concept'],
            'amount' => $op['amount'],
            'currency_code' => 'ARS',
            'charged_on' => $op['charged_on'],
            'documental_status' => DocumentalStatus::NotRequired->value,
            'notes' => $this->notesPayload($op['source_row'], $op['field'], $op['formula'], $op['amount'], $op['batch11e']->uuid),
            'create_cc' => true,
            'apply_available_credit' => false,
        ]);

        return [
            'action' => 'charge',
            'status' => 'created',
            'source_row' => $op['source_row'],
            'charge_id' => $charge->id,
            'number' => $charge->number,
            'ledger_id' => $charge->client_ledger_entry_id,
            'amount' => $op['amount'],
            'formula' => $op['formula'],
            'reason' => self::REASON,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function linkHugo466(Client $client, ImportBatch $batch11e): array
    {
        $marker = $this->marker(466, 'receipt_app');
        $existing = Receipt::query()->where('client_id', $client->id)->where('notes', 'like', '%'.$marker.'%')->first();
        if ($existing) {
            return ['action' => 'hugo_466_link', 'status' => 'idempotent_skip', 'receipt_id' => $existing->id];
        }

        $charge = CommercialCharge::query()
            ->where('client_id', $client->id)
            ->where('notes', 'like', '%'.$this->marker(404, 'cc_in').'%')
            ->first();
        if (! $charge) {
            return ['action' => 'hugo_466_link', 'status' => 'blocked', 'error' => 'charge_404_missing'];
        }

        $movement = Movement::query()
            ->where('client_id', $client->id)
            ->where('source_row', 466)
            ->where('status', MovementStatus::Posted->value)
            ->where('type', 'income')
            ->orderBy('id')
            ->first();

        $ledgerPayment = ClientLedgerEntry::query()
            ->where('client_id', $client->id)
            ->where('type', ClientLedgerType::Payment)
            ->where('status', MovementStatus::Posted->value)
            ->where('amount', '1308450.00')
            ->where(function ($q) {
                $q->where('description', 'like', '%Hugo Ferreyra%')
                    ->orWhere('description', 'like', '%Hugo%');
            })
            ->orderBy('id')
            ->first();

        if (! $movement || ! $ledgerPayment) {
            return [
                'action' => 'hugo_466_link',
                'status' => 'blocked',
                'error' => 'missing_existing_cobro',
                'movement_id' => $movement?->id,
                'ledger_id' => $ledgerPayment?->id,
            ];
        }

        // No segundo ingreso Patagonia: reutilizar movement 466 + ledger payment.
        if ($ledgerPayment->receipt_id) {
            $receipt = Receipt::query()->find($ledgerPayment->receipt_id);
            if ($receipt && $receipt->isPosted()) {
                $appExists = ReceiptApplication::query()
                    ->where('receipt_id', $receipt->id)
                    ->where('commercial_charge_id', $charge->id)
                    ->where('status', 'posted')
                    ->exists();
                if (! $appExists && $charge->isOpen()) {
                    ReceiptApplication::query()->create([
                        'receipt_id' => $receipt->id,
                        'commercial_charge_id' => $charge->id,
                        'amount' => '1308450.00',
                        'status' => 'posted',
                        'user_id' => Auth::id(),
                    ]);
                    $this->charges->registerApplicationAmount($charge, '1308450.00');
                }

                return ['action' => 'hugo_466_link', 'status' => 'linked_existing_receipt', 'receipt_id' => $receipt->id];
            }
        }

        $receipt = $this->receipts->attachHistorical([
            'client_id' => $client->id,
            'amount' => '1308450.00',
            'received_on' => $ledgerPayment->entry_date?->toDateString() ?? '2026-05-01',
            'concept' => 'Hugo Ferreyra', // conservar concepto_original
            'notes' => $this->notesPayload(466, 'receipt_app', 'literal CC OUT 1308450', '1308450.00', $batch11e->uuid)
                .' | origin_charge_row=404 | no_second_patagonia_income',
            'financial_account_id' => $movement->financial_account_id,
            'financial_movement_id' => $movement->id,
            'client_ledger_entry_id' => $ledgerPayment->id,
            'create_ledger_payment' => false,
            'applications' => [
                ['commercial_charge_id' => $charge->id, 'amount' => '1308450.00'],
            ],
            'documental_status' => DocumentalStatus::NotRequired->value,
        ]);

        return [
            'action' => 'hugo_466_link',
            'status' => 'created',
            'receipt_id' => $receipt->id,
            'receipt_number' => $receipt->number,
            'movement_id' => $movement->id,
            'ledger_id' => $ledgerPayment->id,
            'charge_id' => $charge->id,
            'concepto_original' => 'Hugo Ferreyra',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function repairCcOutPayment(array $op): array
    {
        $marker = $this->marker($op['source_row'], 'cc_out');
        $existing = Receipt::query()
            ->where('client_id', $op['client']->id)
            ->where('notes', 'like', '%'.$marker.'%')
            ->first();
        if ($existing) {
            return ['action' => 'cc_out', 'status' => 'idempotent_skip', 'source_row' => $op['source_row'], 'receipt_id' => $existing->id];
        }

        $charge = CommercialCharge::query()
            ->where('client_id', $op['client']->id)
            ->where('notes', 'like', '%'.$this->marker($op['charge_source_row'], 'cc_in').'%')
            ->first();
        if (! $charge) {
            return ['action' => 'cc_out', 'status' => 'blocked', 'source_row' => $op['source_row'], 'error' => 'charge_missing'];
        }

        $receipt = $this->receipts->attachHistorical([
            'client_id' => $op['client']->id,
            'amount' => $op['amount'],
            'received_on' => $op['received_on'],
            'concept' => $op['concept'],
            'notes' => $this->notesPayload($op['source_row'], 'cc_out', $op['formula'], $op['amount'], $op['batch11e']->uuid)
                .' | no_finance_invented',
            'financial_account_id' => null,
            'financial_movement_id' => null,
            'create_ledger_payment' => true,
            'applications' => [
                ['commercial_charge_id' => $charge->id, 'amount' => $op['amount']],
            ],
            'documental_status' => DocumentalStatus::NotRequired->value,
        ]);

        return [
            'action' => 'cc_out',
            'status' => 'created',
            'source_row' => $op['source_row'],
            'receipt_id' => $receipt->id,
            'receipt_number' => $receipt->number,
            'ledger_id' => $receipt->client_ledger_entry_id,
            'charge_id' => $charge->id,
            'amount' => $op['amount'],
            'formula' => $op['formula'],
            'finance_created' => false,
            'reason' => self::REASON,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cleanupTest10k(Client $client): array
    {
        $charge = CommercialCharge::query()
            ->where('client_id', $client->id)
            ->where(function ($q) {
                $q->where('concept', 'like', '%TEST 11F3%')
                    ->orWhere('concept', 'like', '%TEST 11F-3%');
            })
            ->where('amount', '10000.00')
            ->whereNull('voided_at')
            ->orderByDesc('id')
            ->first();

        if (! $charge) {
            return ['status' => 'not_found'];
        }

        $ledgerId = $charge->client_ledger_entry_id;
        $apps = ReceiptApplication::query()->where('commercial_charge_id', $charge->id)->where('status', 'posted')->count();

        // Smoke fixture claro: anular (reverse) y borrar físico si no hay apps posted.
        if ($apps > 0) {
            return ['status' => 'blocked_has_applications', 'charge_id' => $charge->id, 'apps' => $apps];
        }

        if ($charge->status->value !== 'voided') {
            // Si está collected sin apps, forzar void de ledger + charge.
            if ($ledgerId) {
                $entry = ClientLedgerEntry::query()->find($ledgerId);
                if ($entry && $entry->isPosted()) {
                    $this->ledger->void($entry, 'Cleanup smoke TEST 11F3 10k — '.self::BATCH_NAME);
                }
            }
            $charge->update([
                'status' => 'voided',
                'amount_open' => '0.00',
                'void_reason' => 'Cleanup smoke TEST 11F3 10k — '.self::BATCH_NAME,
                'voided_at' => now(),
                'voided_by' => Auth::id(),
            ]);
        }

        $chargeId = $charge->id;
        ReceiptApplication::query()->where('commercial_charge_id', $chargeId)->delete();
        CommercialCharge::query()->where('id', $chargeId)->delete();
        if ($ledgerId) {
            ClientLedgerEntry::query()->where('id', $ledgerId)->delete();
        }

        $this->audit->log('daasa_test_10k_cleanup', $client, null, [
            'charge_id' => $chargeId,
            'ledger_id' => $ledgerId,
            'action' => 'void_then_physical_delete_smoke_fixture',
        ], 'TEST 10k smoke eliminado de DAASA');

        return [
            'status' => 'void_then_deleted',
            'charge_id' => $chargeId,
            'ledger_id' => $ledgerId,
            'log' => 'void_then_physical_delete_smoke_fixture',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function backfillUnequivocalRelations(Client $client): array
    {
        $linked = 0;
        // Cable de red charge 532 ↔ payment 637 if amounts match and no app yet.
        $cableCharge = CommercialCharge::query()
            ->where('client_id', $client->id)
            ->where('concept', 'like', '%Cable de red%')
            ->where('amount', '464000.00')
            ->whereNull('voided_at')
            ->orderBy('id')
            ->first();

        $cablePayment = ClientLedgerEntry::query()
            ->where('client_id', $client->id)
            ->where('type', ClientLedgerType::Payment)
            ->where('status', MovementStatus::Posted->value)
            ->where('amount', '464000.00')
            ->where('description', 'like', '%DAASA Cable%')
            ->where(function ($q) {
                $q->whereNull('description')->orWhere('description', 'not like', '%de red%');
            })
            ->orderBy('id')
            ->first();

        // Prefer description exact from import row 637
        $cablePayment = ClientLedgerEntry::query()
            ->where('client_id', $client->id)
            ->where('type', ClientLedgerType::Payment)
            ->where('status', MovementStatus::Posted->value)
            ->where('amount', '464000.00')
            ->where('description', 'DAASA Cable')
            ->orderBy('id')
            ->first() ?? $cablePayment;

        $income637 = Movement::query()
            ->where('client_id', $client->id)
            ->where('source_row', 637)
            ->where('status', MovementStatus::Posted->value)
            ->first();

        if ($cableCharge && $cablePayment && $cableCharge->isOpen() && ! $cablePayment->receipt_id) {
            $marker = $this->marker(637, 'receipt_app');
            if (! Receipt::query()->where('notes', 'like', '%'.$marker.'%')->exists()) {
                $this->receipts->attachHistorical([
                    'client_id' => $client->id,
                    'amount' => '464000.00',
                    'received_on' => $cablePayment->entry_date?->toDateString() ?? '2026-06-10',
                    'concept' => 'DAASA Cable',
                    'notes' => $this->notesPayload(637, 'receipt_app', 'literal CC OUT', '464000.00', self::IMPORT_BATCH_UUID)
                        .' | backfill_unequivocal',
                    'financial_account_id' => $income637?->financial_account_id,
                    'financial_movement_id' => $income637?->id,
                    'client_ledger_entry_id' => $cablePayment->id,
                    'create_ledger_payment' => false,
                    'applications' => [
                        ['commercial_charge_id' => $cableCharge->id, 'amount' => '464000.00'],
                    ],
                ]);
                $linked++;
            }
        }

        // Opening charge already backfilled in 11F-3.
        return ['receipt_links_created' => $linked];
    }

    /**
     * @return array<string, mixed>
     */
    private function balanceCompare(Client $client): array
    {
        $posted = ClientLedgerEntry::query()
            ->posted()
            ->where('client_id', $client->id)
            ->get(['type', 'signed_amount', 'description', 'entry_date']);

        $ccIn = '0.00';
        $ccOut = '0.00';
        foreach ($posted as $e) {
            $signed = (string) $e->signed_amount;
            if (Money::compare($signed, '0') < 0) {
                $ccIn = Money::add($ccIn, Money::mul($signed, '-1'));
            } else {
                $ccOut = Money::add($ccOut, $signed);
            }
        }

        $ledger = $this->ledger->balanceFor($client, 'ARS');
        // Excel reconstructed from known DAASA CC legs (apertura + repaired + historical):
        // apertura 50000 + 404 + 484 + 485 + 532 - 466 - 503 - 505 - 637
        $excelApprox = Money::sub(
            Money::add(Money::add(Money::add(Money::add('50000.00', '1308450.00'), '1019560.00'), '2371400.00'), '464000.00'),
            Money::add(Money::add(Money::add('1308450.00', '1019560.00'), '2371400.00'), '464000.00')
        );

        return [
            'excel_cc_reconstructed' => $excelApprox,
            'excel_note' => 'apertura + CC IN − CC OUT (pares 404/466, 484/503, 485/505, 532/637); no forzar 0 si abre distinto',
            'ledger_signed_ars' => $ledger,
            'ledger_display_a_cobrar' => \App\Support\UiSemantics::clientCcDisplayBalance($ledger),
            'cc_in_sum' => $ccIn,
            'cc_out_sum' => $ccOut,
            'cc_in_minus_cc_out' => Money::sub($ccIn, $ccOut),
            'ui_uses_ledger' => true,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function plannedOps(): array
    {
        return [
            ['row' => 404, 'op' => 'charge_cc_in', 'amount' => 1308450.0, 'classification' => 'resolved_unequivocal'],
            ['row' => 466, 'op' => 'link_receipt_application_no_new_income', 'amount' => 1308450.0, 'classification' => 'existing'],
            ['row' => 484, 'op' => 'charge_cc_in', 'amount' => 1019560.0, 'classification' => 'resolved_unequivocal'],
            ['row' => 503, 'op' => 'cc_out_no_finance', 'amount' => 1019560.0, 'classification' => 'resolved_unequivocal'],
            ['row' => 485, 'op' => 'charge_cc_in', 'amount' => 2371400.0, 'classification' => 'resolved_unequivocal'],
            ['row' => 505, 'op' => 'cc_out_no_finance', 'amount' => 2371400.0, 'classification' => 'resolved_unequivocal'],
            ['row' => 'TEST_10k', 'op' => 'void_delete_smoke', 'amount' => 10000.0],
        ];
    }

    /**
     * @return array<string, int>
     */
    public function classificationCounts(): array
    {
        // From formula re-read (cached locally in hotfix): 18 resolved cells, 0 unresolvable.
        return [
            'candidate_rows' => 27,
            'resolved_unequivocal_cells' => 18,
            'unresolvable_cells' => 0,
            'finance_cc_repair_rows' => 5, // 404,484,485,503,505 (+466 link)
            'analysis_only_or_already_imported' => 22,
        ];
    }

    private function marker(int $row, string $field): string
    {
        return self::BATCH_NAME.':row:'.$row.':'.$field;
    }

    private function notesPayload(int $row, string $field, string $formula, string $amount, string $batchUuid): string
    {
        return implode(' | ', [
            $this->marker($row, $field),
            'reason='.self::REASON,
            'excel_row='.$row,
            'formula='.$formula,
            'calculated='.$amount,
            'original_11e_batch='.$batchUuid,
            'reconciliation_batch='.self::BATCH_NAME,
        ]);
    }
}
