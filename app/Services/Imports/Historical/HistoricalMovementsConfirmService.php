<?php

namespace App\Services\Imports\Historical;

use App\Enums\ImportReviewStatus;
use App\Enums\MovementType;
use App\Models\Category;
use App\Models\Client;
use App\Models\FinancialAccount;
use App\Models\ImportBatch;
use App\Models\ImportRowTrace;
use App\Models\Movement;
use App\Services\AuditLogger;
use App\Services\Clients\ClientLedgerService;
use App\Services\Finance\MovementService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Confirmación autorizada de importación histórica (Etapa 11E).
 * confirm() genérico permanece bloqueado; solo este método con token.
 */
class HistoricalMovementsConfirmService
{
    public function __construct(
        private readonly HistoricalImportGate $gate,
        private readonly AccountMappingService $accounts,
        private readonly ClientDetectionService $clients,
        private readonly MovementService $movements,
        private readonly ClientLedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{batch: ImportBatch, gate: array<string, mixed>, import: array<string, mixed>}
     */
    public function confirmAuthorizedHistoricalImport(
        ImportBatch $batch,
        string $authorizationToken,
        ?int $actingUserId = null,
    ): array {
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '1024M');
        }

        $expected = (string) config('historical_closure_11e.authorization_token');
        if ($authorizationToken === '' || ! hash_equals($expected, $authorizationToken)) {
            throw new InvalidArgumentException('Token de autorización inválido para confirmación histórica 11E.');
        }

        if ($batch->importer_kind !== 'historical_movements') {
            throw new InvalidArgumentException('Solo lotes historical_movements.');
        }
        if ($batch->isConfirmed()) {
            throw new InvalidArgumentException('Este batch ya fue confirmado (idempotencia: no reejecutar).');
        }
        if ($batch->isRolledBack()) {
            throw new InvalidArgumentException('Este batch fue revertido; crear un nuevo preview.');
        }

        $gate = $this->gate->evaluate($batch);
        if (! $gate['passed']) {
            throw new InvalidArgumentException(
                'GATE 11E FALLÓ — no se importa. Bloqueos: '.implode(' | ', $gate['blockers'])
            );
        }

        $userId = $actingUserId ?: Auth::id();
        if (! $userId) {
            throw new InvalidArgumentException('Usuario requerido para importar.');
        }

        $rows = $this->loadRows($batch);
        if ($rows === []) {
            throw new InvalidArgumentException('No hay filas en rows_all_path para importar.');
        }

        $this->accounts->ensurePreviewMasters();
        $this->ensureExchangeRate();

        $stats = [
            'imported_movements' => 0,
            'imported_cc_charges' => 0,
            'imported_cc_payments' => 0,
            'imported_card_payments' => 0,
            'imported_incomes' => 0,
            'imported_expenses' => 0,
            'imported_openings' => 0,
            'imported_inferred' => 0,
            'skipped_pending' => 0,
            'skipped_excluded' => 0,
            'skipped_zero' => 0,
            'duplicates_avoided' => 0,
            'traces' => 0,
            'by_kind' => [],
            'errors' => [],
        ];

        try {
            DB::transaction(function () use ($batch, $rows, $userId, $authorizationToken, &$stats, $gate) {
                Auth::loginUsingId($userId);

                foreach ($rows as $row) {
                    $status = (string) ($row['review_status'] ?? '');
                    $sr = (int) ($row['source_row'] ?? 0);

                    if ($status === ImportReviewStatus::PendingComplete->value) {
                        $stats['skipped_pending']++;
                        $this->trace($batch, $row, 'skipped_pending', null);
                        $stats['traces']++;
                        continue;
                    }
                    if ($status === ImportReviewStatus::Excluded->value) {
                        $stats['skipped_excluded']++;
                        $this->trace($batch, $row, 'skipped_excluded', null);
                        $stats['traces']++;
                        continue;
                    }
                    if (! ImportReviewStatus::tryFrom($status)?->isImportReady()) {
                        $stats['errors'][] = "Fila {$sr}: status no import-ready ({$status})";
                        continue;
                    }

                    $result = $this->importRow($batch, $row, $stats);
                    if ($result === 'duplicate') {
                        $stats['duplicates_avoided']++;
                    }
                }

                $batch->update([
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'rows_imported' => $stats['imported_movements'] + $stats['imported_cc_charges'] + $stats['imported_cc_payments'],
                    'options' => array_merge($batch->options ?? [], [
                        'confirm_enabled' => false,
                        'confirm_authorized' => true,
                        'authorization_token_used' => true,
                        'authorization_label' => config('historical_closure_11e.authorization_label'),
                        'authorized_at' => now()->toDateTimeString(),
                        'authorized_by_user_id' => $userId,
                        'gate' => $gate,
                        'import_stats' => $stats,
                        'rollback_supported' => true,
                    ]),
                    'preview_payload' => array_merge($batch->preview_payload ?? [], [
                        'confirm_blocked' => false,
                        'confirm_blocked_reason' => null,
                        'confirmed_authorized_at' => now()->toDateTimeString(),
                        'import_stats' => $stats,
                    ]),
                    'error_summary' => [
                        'note' => 'Importación histórica autorizada 11E',
                        'errors' => $stats['errors'],
                    ],
                ]);

                $this->audit->log('historical_movements_confirmed_authorized', $batch, null, [
                    'authorization' => config('historical_closure_11e.authorization_label'),
                    'token_ok' => true,
                    'stats' => $stats,
                    'gate_passed' => true,
                ], 'Importación histórica 11E confirmada con autorización expresa');
            });
        } catch (Throwable $e) {
            throw $e;
        }

        return [
            'batch' => $batch->fresh(),
            'gate' => $gate,
            'import' => $stats,
        ];
    }

    /**
     * Rollback por batch: anula movimientos/CC creados por este import.
     */
    public function rollbackAuthorizedImport(ImportBatch $batch, string $reason, ?int $userId = null): ImportBatch
    {
        if (! $batch->isConfirmed()) {
            throw new InvalidArgumentException('Solo se puede revertir un batch confirmado.');
        }
        $userId = $userId ?: Auth::id();
        if (! $userId) {
            throw new InvalidArgumentException('Usuario requerido.');
        }

        DB::transaction(function () use ($batch, $reason, $userId) {
            Auth::loginUsingId($userId);

            $movements = Movement::query()
                ->where('import_batch_id', $batch->id)
                ->where('status', 'posted')
                ->orderBy('id')
                ->get();

            foreach ($movements as $movement) {
                $this->movements->void($movement, $reason);
            }

            // CC entries linked via financial_movement_id are voided by MovementService/ledger when linked;
            // also void orphan ledger entries tagged in source via import traces entity_type=client_ledger
            $ledgerIds = ImportRowTrace::query()
                ->where('import_batch_id', $batch->id)
                ->where('entity_type', 'client_ledger')
                ->pluck('entity_id')
                ->filter()
                ->all();
            foreach ($ledgerIds as $lid) {
                $entry = \App\Models\ClientLedgerEntry::query()->find($lid);
                if ($entry && $entry->isPosted()) {
                    // If already voided via payment couple, skip
                    try {
                        $this->ledger->void($entry, $reason);
                    } catch (Throwable) {
                        // already voided or linked
                    }
                }
            }

            $batch->update([
                'status' => 'rolled_back',
                'rolled_back_at' => now(),
                'rolled_back_by' => $userId,
                'rollback_reason' => $reason,
                'options' => array_merge($batch->options ?? [], [
                    'rolled_back' => true,
                ]),
            ]);

            $this->audit->log('historical_movements_rolled_back', $batch, null, [
                'reason' => $reason,
                'movements' => $movements->count(),
            ], 'Importación histórica revertida');
        });

        return $batch->fresh();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $stats
     */
    private function importRow(ImportBatch $batch, array $row, array &$stats): string
    {
        $interp = $row['interpretation'] ?? [];
        $kind = (string) ($interp['kind'] ?? 'simple');
        $stats['by_kind'][$kind] = ($stats['by_kind'][$kind] ?? 0) + 1;
        $sr = (int) ($row['source_row'] ?? 0);
        $externalBase = 'hist:'.$batch->file_hash.':'.($row['sheet'] ?? 'Movimientos').':'.$sr;

        if (Movement::query()->where('external_id', $externalBase.':primary')->exists()) {
            $this->trace($batch, $row, 'duplicate_avoided', null);

            return 'duplicate';
        }

        $isInferred = ($row['review_status'] ?? '') === ImportReviewStatus::Inferred->value
            || ! empty($row['dato_inferido']);

        return match ($kind) {
            'excluded', 'pendiente_completar' => 'skipped',
            'card_statement_payment' => $this->importCardPayment($batch, $row, $externalBase, $stats, $isInferred),
            'saldo_apertura_cc' => $this->importOpeningCc($batch, $row, $externalBase, $stats, $isInferred),
            'saldo_apertura_mercaderia' => $this->importOpeningMercaTraceOnly($batch, $row, $stats),
            'cc_cargo_cliente' => $this->importCcCharge($batch, $row, $externalBase, $stats, $isInferred),
            'cc_cancelacion_con_cobro', 'cc_cancelacion_deuda' => $this->importCcSettlement($batch, $row, $externalBase, $stats, $isInferred),
            'reintegro_gasto_personal' => $this->importIncome($batch, $row, $externalBase, $stats, $isInferred, 'reintegro'),
            default => $this->importSimpleOrSale($batch, $row, $externalBase, $stats, $isInferred),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $stats
     */
    private function importSimpleOrSale(ImportBatch $batch, array $row, string $externalBase, array &$stats, bool $isInferred): string
    {
        $interp = $row['interpretation'] ?? [];
        $created = false;

        $expense = (float) ($interp['finance_expense'] ?? 0);
        $income = (float) ($interp['finance_income'] ?? 0);
        $ccCharge = (float) ($interp['cc_charge'] ?? 0);
        $ccPayment = (float) ($interp['cc_payment'] ?? 0);

        // Economic venta/utilidad/merca: análisis only — no stock, no fake sale docs unless finance/CC exist
        if ($expense <= 0.0001 && $income <= 0.0001 && $ccCharge <= 0.0001 && $ccPayment <= 0.0001) {
            $stats['skipped_zero']++;
            $this->trace($batch, $row, 'skipped_zero_or_analysis_only', null);

            return 'skipped';
        }

        if ($expense > 0.0001) {
            $mov = $this->createExpense($batch, $row, $expense, $externalBase.':expense');
            $stats['imported_movements']++;
            $stats['imported_expenses']++;
            if ($isInferred) {
                $stats['imported_inferred']++;
            }
            $this->trace($batch, $row, 'movement', $mov->id);
            $stats['traces']++;
            $created = true;
        }

        if ($income > 0.0001) {
            $clientName = $interp['client'] ?? $row['client'] ?? null;
            $client = $clientName ? $this->ensureClient((string) $clientName) : null;
            $mov = $this->createIncome($batch, $row, $income, $externalBase.':income', $client?->id);
            $stats['imported_movements']++;
            $stats['imported_incomes']++;
            $this->trace($batch, $row, 'movement', $mov->id);
            $stats['traces']++;
            $created = true;
        }

        if ($ccCharge > 0.0001) {
            $clientName = $interp['client'] ?? $row['client'] ?? null;
            if ($clientName) {
                $client = $this->ensureClient((string) $clientName);
                $entry = $this->ledger->registerCharge($client, [
                    'currency_code' => 'ARS',
                    'amount' => $ccCharge,
                    'entry_date' => $row['date'] ?? now()->toDateString(),
                    'description' => (string) ($row['concepto'] ?? 'CC cargo histórico'),
                ]);
                // Tag via update if columns allow — store linkage in trace
                $stats['imported_cc_charges']++;
                $this->trace($batch, $row, 'client_ledger', $entry->id);
                $stats['traces']++;
                $created = true;
            }
        }

        if ($ccPayment > 0.0001 && $income <= 0.0001) {
            // CC OUT: si hay medio documentado → cobro+caja; si no → solo CC (no inventar ingreso).
            $clientName = $interp['client'] ?? $row['client'] ?? null;
            if ($clientName) {
                $client = $this->ensureClient((string) $clientName);
                $account = $this->resolveAccountId($row, $interp);
                if ($account) {
                    $entry = $this->ledger->registerPayment($client, [
                        'financial_account_id' => $account->id,
                        'amount' => $ccPayment,
                        'entry_date' => $row['date'] ?? now()->toDateString(),
                        'description' => (string) ($row['concepto'] ?? 'Cobro CC histórico'),
                        'category_id' => $this->resolveCategoryId($row)?->id,
                    ]);
                    $stats['imported_cc_payments']++;
                    $stats['imported_movements']++;
                    $stats['imported_incomes']++;
                    $this->trace($batch, $row, 'client_ledger', $entry['ledger']->id);
                    $this->trace($batch, $row, 'movement', $entry['movement']->id);
                    $stats['traces'] += 2;
                } else {
                    $entry = $this->ledger->createEntry(
                        $client,
                        \App\Enums\ClientLedgerType::Payment,
                        [
                            'currency_code' => 'ARS',
                            'amount' => $ccPayment,
                            'entry_date' => $row['date'] ?? now()->toDateString(),
                            'description' => (string) ($row['concepto'] ?? 'CC OUT histórico sin caja inventada'),
                        ],
                        sign: 1,
                        requiresFinance: false,
                    );
                    $stats['imported_cc_payments']++;
                    $this->trace($batch, $row, 'client_ledger', $entry->id);
                    $stats['traces']++;
                }
                $created = true;
            }
        } elseif ($ccPayment > 0.0001 && $income > 0.0001) {
            // Income already created; add CC payment linked to that movement if possible
            $clientName = $interp['client'] ?? $row['client'] ?? null;
            if ($clientName) {
                $client = $this->ensureClient((string) $clientName);
                $entry = $this->ledger->createEntry(
                    $client,
                    \App\Enums\ClientLedgerType::Payment,
                    [
                        'currency_code' => 'ARS',
                        'amount' => $ccPayment,
                        'entry_date' => $row['date'] ?? now()->toDateString(),
                        'description' => (string) ($row['concepto'] ?? 'Cobro CC histórico'),
                    ],
                    sign: 1,
                    requiresFinance: false,
                );
                $stats['imported_cc_payments']++;
                $this->trace($batch, $row, 'client_ledger', $entry->id);
                $stats['traces']++;
                $created = true;
            }
        }

        if (! $created) {
            $stats['skipped_zero']++;
            $this->trace($batch, $row, 'skipped_noop', null);
        }

        return $created ? 'imported' : 'skipped';
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $stats
     */
    private function importCardPayment(ImportBatch $batch, array $row, string $externalBase, array &$stats, bool $isInferred): string
    {
        $interp = $row['interpretation'] ?? [];
        $amount = (float) ($interp['card_liability_decrease']
            ?? $interp['payment_account_decrease']
            ?? $row['amounts']['pagos_tc']
            ?? 0);
        if ($amount <= 0.0001) {
            $stats['skipped_zero']++;
            $this->trace($batch, $row, 'skipped_card_zero', null);

            return 'skipped';
        }

        $payAlias = $interp['payment_account_alias'] ?? $row['excel_subcuenta_account'] ?? null;
        $cardAlias = $interp['card_alias'] ?? $row['excel_cuenta_category'] ?? null;
        $from = $this->resolveAccountByAlias((string) $payAlias);
        $card = $this->resolveAccountByAlias((string) $cardAlias);
        if (! $from || ! $card) {
            throw new InvalidArgumentException("Pago tarjeta fila {$row['source_row']}: falta cuenta/tarjeta");
        }

        // Transfer-like: decrease cash + decrease liability via expense on cash and income on liability? 
        // Domain: payment reduces liability and cash. Use transfer from cash to card liability account if supported,
        // else: expense from payment account + special note. Prefer transfer same currency.
        $pair = $this->movements->createTransfer([
            'from_account_id' => $from->id,
            'to_account_id' => $card->id,
            'amount' => $amount,
            'scope' => 'personal',
            'movement_date' => $row['date'] ?? now()->toDateString(),
            'description' => (string) ($row['concepto'] ?? 'Pago resumen tarjeta'),
        ]);
        foreach ([$pair['out'], $pair['in']] as $i => $mov) {
            $mov->update([
                'import_batch_id' => $batch->id,
                'external_id' => $externalBase.':card_pay:'.($i === 0 ? 'out' : 'in'),
                'source_sheet' => $row['sheet'] ?? 'Movimientos',
                'source_row' => $row['source_row'] ?? null,
                'source_payload' => $this->sourcePayload($row),
            ]);
            $this->trace($batch, $row, 'movement', $mov->id);
            $stats['traces']++;
            $stats['imported_movements']++;
        }
        $stats['imported_card_payments']++;
        if ($isInferred) {
            $stats['imported_inferred']++;
        }

        return 'imported';
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $stats
     */
    private function importOpeningCc(ImportBatch $batch, array $row, string $externalBase, array &$stats, bool $isInferred): string
    {
        $interp = $row['interpretation'] ?? [];
        $clientName = $interp['client'] ?? $row['client'] ?? null;
        $amount = (float) ($interp['cc_charge'] ?? 0);
        if (! $clientName || $amount <= 0.0001) {
            $stats['skipped_zero']++;

            return 'skipped';
        }
        $client = $this->ensureClient((string) $clientName);
        $entry = $this->ledger->registerCharge($client, [
            'currency_code' => 'ARS',
            'amount' => $amount,
            'entry_date' => $row['date'] ?? now()->toDateString(),
            'description' => (string) ($row['concepto'] ?? 'Apertura CC'),
            'reason' => 'Saldo apertura CC histórico',
        ]);
        $stats['imported_cc_charges']++;
        $stats['imported_openings']++;
        $this->trace($batch, $row, 'client_ledger', $entry->id);
        $stats['traces']++;

        return 'imported';
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $stats
     */
    private function importOpeningMercaTraceOnly(ImportBatch $batch, array $row, array &$stats): string
    {
        // No stock físico ficticio — solo trazabilidad
        $this->trace($batch, $row, 'merca_opening_analysis_only', null);
        $stats['traces']++;
        $stats['imported_openings']++;

        return 'imported';
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $stats
     */
    private function importCcCharge(ImportBatch $batch, array $row, string $externalBase, array &$stats, bool $isInferred): string
    {
        $interp = $row['interpretation'] ?? [];
        $clientName = $interp['client'] ?? $row['client'] ?? null;
        $amount = (float) ($interp['cc_charge'] ?? 0);
        if (! $clientName || $amount <= 0.0001) {
            $stats['skipped_zero']++;

            return 'skipped';
        }
        $client = $this->ensureClient((string) $clientName);
        $entry = $this->ledger->registerCharge($client, [
            'currency_code' => 'ARS',
            'amount' => $amount,
            'entry_date' => $row['date'] ?? now()->toDateString(),
            'description' => (string) ($row['concepto'] ?? 'CC cargo'),
        ]);
        $stats['imported_cc_charges']++;
        $this->trace($batch, $row, 'client_ledger', $entry->id);
        $stats['traces']++;

        return 'imported';
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $stats
     */
    private function importCcSettlement(ImportBatch $batch, array $row, string $externalBase, array &$stats, bool $isInferred): string
    {
        return $this->importSimpleOrSale($batch, $row, $externalBase, $stats, $isInferred);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $stats
     */
    private function importIncome(ImportBatch $batch, array $row, string $externalBase, array &$stats, bool $isInferred, string $tag): string
    {
        $amount = (float) ($row['interpretation']['finance_income'] ?? 0);
        if ($amount <= 0.0001) {
            $stats['skipped_zero']++;

            return 'skipped';
        }
        $mov = $this->createIncome($batch, $row, $amount, $externalBase.':'.$tag);
        $stats['imported_movements']++;
        $stats['imported_incomes']++;
        $this->trace($batch, $row, 'movement', $mov->id);
        $stats['traces']++;

        return 'imported';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function createExpense(ImportBatch $batch, array $row, float $amount, string $externalId): Movement
    {
        $account = $this->resolveAccountId($row, $row['interpretation'] ?? []);
        if (! $account) {
            throw new InvalidArgumentException('Sin cuenta financiera para egreso fila '.$row['source_row']);
        }
        $category = $this->resolveCategoryId($row);
        $scope = in_array($row['proposed_scope'] ?? '', ['personal', 'professional'], true)
            ? $row['proposed_scope']
            : 'personal';

        $mov = $this->movements->createSimple([
            'type' => MovementType::Expense->value,
            'scope' => $scope,
            'financial_account_id' => $account->id,
            'amount' => $amount,
            'movement_date' => $row['date'] ?? now()->toDateString(),
            'category_id' => $category?->id,
            'description' => (string) ($row['concepto'] ?? 'Gasto histórico'),
            'import_batch_id' => $batch->id,
            'external_id' => $externalId,
            'source_sheet' => $row['sheet'] ?? 'Movimientos',
            'source_row' => $row['source_row'] ?? null,
            'source_payload' => $this->sourcePayload($row),
            'is_opening_adjustment' => false,
        ]);

        return $mov;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function createIncome(ImportBatch $batch, array $row, float $amount, string $externalId, ?int $clientId = null): Movement
    {
        $account = $this->resolveAccountId($row, $row['interpretation'] ?? []);
        if (! $account) {
            throw new InvalidArgumentException('Sin cuenta financiera para ingreso fila '.$row['source_row']);
        }
        $category = $this->resolveCategoryId($row);
        $scope = in_array($row['proposed_scope'] ?? '', ['personal', 'professional'], true)
            ? $row['proposed_scope']
            : 'professional';

        return $this->movements->createSimple([
            'type' => MovementType::Income->value,
            'scope' => $scope,
            'financial_account_id' => $account->id,
            'amount' => $amount,
            'movement_date' => $row['date'] ?? now()->toDateString(),
            'category_id' => $category?->id,
            'description' => (string) ($row['concepto'] ?? 'Ingreso histórico'),
            'client_id' => $clientId,
            'import_batch_id' => $batch->id,
            'external_id' => $externalId,
            'source_sheet' => $row['sheet'] ?? 'Movimientos',
            'source_row' => $row['source_row'] ?? null,
            'source_payload' => $this->sourcePayload($row),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $interp
     */
    private function resolveAccountId(array $row, array $interp): ?FinancialAccount
    {
        $alias = $interp['account_alias']
            ?? $interp['payment_account_alias']
            ?? $interp['finance_account_alias']
            ?? $row['excel_subcuenta_account']
            ?? null;
        $alias = trim((string) $alias);
        if ($alias === '') {
            // Servicios recurrentes confirmados: medio por hint (ej. MUBI → VISA).
            $alias = $this->accountHintFromConcept(
                (string) ($row['concepto'] ?? ''),
                (string) ($row['excel_cuenta_category'] ?? '')
            ) ?? '';
        }
        if ($alias === '') {
            return null;
        }

        return $this->resolveAccountByAlias($alias);
    }

    private function accountHintFromConcept(string $concepto, string $cuenta): ?string
    {
        $norm = mb_strtolower(trim($concepto));
        $norm = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ü'], ['a', 'e', 'i', 'o', 'u', 'u'], $norm);
        $defs = config('historical_recurring_services.services', []);
        $map = [
            'youtube' => '/\byoutube\b/u',
            'spotify' => '/\bspotify\b/u',
            'mubi' => '/\bmubi\b/u',
            'meli' => '/^(meli|mercado\s*libre|ml)\b/u',
            'mercantil_andina' => '/(mercantil\s*andina|seguro\s+del\s+auto|falta\s+el\s+seguro)/u',
            'pedidos_ya_premium' => '/pedidos\s*ya[!.,]?\s*(premium|plus)\b/u',
        ];
        foreach ($map as $key => $rx) {
            if (! isset($defs[$key])) {
                continue;
            }
            if (preg_match($rx, $norm)) {
                if ($key === 'meli' && $cuenta !== '' && ! in_array($cuenta, ['Servicios', 'Suscripciones', 'Seguros'], true)) {
                    continue;
                }

                return $defs[$key]['account_hint'] ?? null;
            }
        }

        return null;
    }

    private function resolveAccountByAlias(string $alias): ?FinancialAccount
    {
        $alias = trim($alias);
        if ($alias === '') {
            return null;
        }
        $def = $this->accounts->resolveAlias($alias);
        if ($def) {
            return $this->accounts->ensureAccountFromDef($def['_matched_alias'] ?? $alias, $def);
        }

        return FinancialAccount::query()
            ->where('alias', $alias)
            ->orWhere('name', $alias)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveCategoryId(array $row): ?Category
    {
        $name = trim((string) ($row['excel_cuenta_category'] ?? ''));
        if ($name === '' || strcasecmp($name, 'CC') === 0) {
            return null;
        }
        $defaults = config('historical_import.category_defaults.'.$name, []);
        $scope = $defaults['default_scope'] ?? 'both';
        if ($scope === 'both') {
            $scope = 'personal';
        }

        return Category::query()->firstOrCreate(
            ['excel_name' => $name],
            [
                'name' => $name,
                'scope' => $scope,
                'default_scope' => $defaults['default_scope'] ?? null,
                'is_active' => true,
                'sort_order' => 100,
            ]
        );
    }

    private function ensureClient(string $name): Client
    {
        $canonical = $this->clients->extractFromConcept($name) ?: $name;
        $aliases = config('historical_import.client_known_aliases', []);
        $key = mb_strtolower(trim($canonical));
        if (isset($aliases[$key])) {
            $canonical = $aliases[$key];
        }

        return Client::query()->firstOrCreate(
            ['name' => $canonical],
            ['status' => Client::STATUS_ACTIVE]
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function sourcePayload(array $row): array
    {
        return [
            'concepto' => $row['concepto'] ?? null,
            'date_original' => $row['date_original'] ?? null,
            'amounts_original' => $row['amounts_original'] ?? $row['amounts'] ?? null,
            'amounts' => $row['amounts'] ?? null,
            'review_status' => $row['review_status'] ?? null,
            'flags' => $row['flags'] ?? [],
            'dato_inferido' => $row['dato_inferido'] ?? false,
            'inference_trace' => $row['inference_trace'] ?? null,
            'interpretation_kind' => $row['interpretation']['kind'] ?? null,
            'original_empty_fields' => $row['original_empty_fields'] ?? [],
            'is_synthetic_reconstruction' => $row['is_synthetic_reconstruction'] ?? false,
        ];
    }

    /**
     * Una traza por fila (unique batch+sheet+source_row); acumula entidades en mapping.
     *
     * @param  array<string, mixed>  $row
     */
    private function trace(ImportBatch $batch, array $row, string $entityType, ?int $entityId): void
    {
        $sr = (int) ($row['source_row'] ?? 0);
        $sheet = (string) ($row['sheet'] ?? 'Movimientos');
        $status = (string) ($row['review_status'] ?? ImportReviewStatus::Accepted->value);
        if (ImportReviewStatus::tryFrom($status) === null) {
            $status = ImportReviewStatus::Accepted->value;
        }
        $baseHash = (string) ($row['row_hash'] ?? hash('sha256', $batch->id.'|'.$sheet.'|'.$sr));

        $existing = ImportRowTrace::query()
            ->where('import_batch_id', $batch->id)
            ->where('sheet', $sheet)
            ->where('source_row', $sr)
            ->first();

        $entities = $existing?->mapping['entities'] ?? [];
        $entities[] = ['type' => $entityType, 'id' => $entityId];

        $payload = [
            'row_hash' => $existing?->row_hash ?? $baseHash,
            'review_status' => $status,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'mapping' => [
                'kind' => $row['interpretation']['kind'] ?? null,
                'account' => $row['excel_subcuenta_account'] ?? null,
                'category' => $row['excel_cuenta_category'] ?? null,
                'entities' => $entities,
            ],
            'original' => [
                'date_original' => $row['date_original'] ?? null,
                'amounts_original' => $row['amounts_original'] ?? null,
                'concepto' => $row['concepto'] ?? null,
            ],
        ];

        if ($existing) {
            $existing->update($payload);
        } else {
            ImportRowTrace::query()->create([
                'import_batch_id' => $batch->id,
                'sheet' => $sheet,
                'source_row' => $sr,
                ...$payload,
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadRows(ImportBatch $batch): array
    {
        $path = $batch->preview_payload['rows_all_path'] ?? null;
        if (! is_string($path) || ! Storage::disk('local')->exists($path)) {
            return [];
        }
        $json = json_decode(Storage::disk('local')->get($path), true);

        return is_array($json['rows'] ?? null) ? $json['rows'] : [];
    }

    private function ensureExchangeRate(): void
    {
        if (\App\Models\ExchangeRate::query()->exists()) {
            return;
        }
        app(\App\Services\Finance\ExchangeRateService::class)
            ->storeManual('1200.000000', 'Bootstrap cotización importación histórica 11E');
    }
}
