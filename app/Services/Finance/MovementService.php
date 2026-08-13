<?php

namespace App\Services\Finance;

use App\Enums\MovementScope;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Models\ClientLedgerEntry;
use App\Models\ExchangeRate;
use App\Models\FinancialAccount;
use App\Models\Movement;
use App\Models\Purchase;
use App\Models\Receipt;
use App\Models\Sale;
use App\Models\Subcategory;
use App\Models\SupplierLedgerEntry;
use App\Services\AuditLogger;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class MovementService
{
    /** Campos que exigen motivo de edición. */
    public const SENSITIVE_FIELDS = [
        'amount',
        'currency_id',
        'financial_account_id',
        'type',
        'exchange_rate_value',
    ];

    public function __construct(
        private readonly BalanceService $balances,
        private readonly ExchangeRateService $rates,
        private readonly ChartAccountMappingService $chartMapping,
        private readonly ChartConceptCompatibility $concepts,
        private readonly ScopeOriginRules $scopeRules,
        private readonly AuditLogger $audit,
        private readonly MovementCodeService $codes,
        private readonly MovementEditAuditService $editAudits,
        private readonly ChartAccountUsageService $chartUsage,
    ) {}

    /**
     * @param  array{
     *   type: string,
     *   scope: string,
     *   financial_account_id: int,
     *   amount: string|float|int,
     *   movement_date?: string,
     *   movement_time?: string,
     *   category_id?: int|null,
     *   subcategory_id?: int|null,
     *   chart_account_id?: int|null,
     *   description?: string|null,
     *   observations?: string|null,
     *   exchange_rate_id?: int|null,
     *   client_id?: int|null,
     *   force_fail?: bool,
     * }  $data
     */
    public function createSimple(array $data): Movement
    {
        $type = MovementType::from($data['type']);
        if ($type->isTransfer()) {
            throw new InvalidArgumentException('Usá createTransfer() para transferencias.');
        }

        $this->scopeRules->assertAllowed($type, (string) $data['scope']);

        return DB::transaction(function () use ($data, $type) {
            $account = FinancialAccount::query()->lockForUpdate()->findOrFail($data['financial_account_id']);
            $this->assertAccountActive($account);

            $amount = Money::normalize($data['amount']);
            if (! Money::isPositive($amount)) {
                throw new InvalidArgumentException('El importe debe ser mayor a cero.');
            }

            $movementDate = $data['movement_date'] ?? now()->toDateString();
            $fx = $this->resolveFxSnapshot($account, $data['exchange_rate_id'] ?? null, $movementDate);
            $equivalents = $this->equivalents($account->currency->code, $amount, $fx['value']);

            $resolved = $this->concepts->resolveFromInput(
                ! empty($data['chart_account_id']) ? (int) $data['chart_account_id'] : null,
                ! empty($data['category_id']) ? (int) $data['category_id'] : null,
                ! empty($data['subcategory_id']) ? (int) $data['subcategory_id'] : null,
            );
            $categoryId = $resolved['category_id'];
            $subcategoryId = $resolved['subcategory_id'];
            if ($subcategoryId) {
                $sub = Subcategory::query()->find($subcategoryId);
                if ($sub && $categoryId && (int) $sub->category_id !== (int) $categoryId) {
                    throw new InvalidArgumentException('La subcategoría no pertenece a la categoría indicada.');
                }
            }
            $chartAccountId = $resolved['chart_account_id']
                ?? $this->chartMapping->resolve(
                    $categoryId,
                    $subcategoryId,
                    $type->value,
                    $data['description'] ?? null,
                )['chart_account_id'];

            $movement = Movement::query()->create([
                'code' => $this->codes->allocate($movementDate),
                'transfer_id' => null,
                'movement_date' => $movementDate,
                'movement_time' => $data['movement_time'] ?? now()->format('H:i:s'),
                'user_id' => Auth::id() ?? throw new RuntimeException('Usuario requerido.'),
                'scope' => MovementScope::from($data['scope']),
                'type' => $type,
                'financial_account_id' => $account->id,
                'currency_id' => $account->currency_id,
                'amount' => $amount,
                'exchange_rate_id' => $fx['id'],
                'exchange_rate_value' => $fx['value'],
                'exchange_rate_at' => $fx['at'],
                'amount_ars' => $equivalents['ars'],
                'amount_usd' => $equivalents['usd'],
                'category_id' => $categoryId,
                'subcategory_id' => $subcategoryId,
                'chart_account_id' => $chartAccountId,
                'description' => $data['description'] ?? null,
                'observations' => $data['observations'] ?? null,
                'status' => MovementStatus::Posted,
                'client_id' => $data['client_id'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'is_opening_adjustment' => (bool) ($data['is_opening_adjustment'] ?? false),
                'source_sheet' => $data['source_sheet'] ?? null,
                'source_row' => $data['source_row'] ?? null,
                'source_payload' => $data['source_payload'] ?? null,
                'import_batch_id' => $data['import_batch_id'] ?? null,
                'external_id' => $data['external_id'] ?? null,
            ]);

            if (! empty($data['is_opening_adjustment'])) {
                $reason = trim((string) ($data['opening_reason'] ?? $data['description'] ?? ''));
                if ($reason === '') {
                    throw new InvalidArgumentException('El ajuste de apertura financiero requiere motivo.');
                }
            }

            if (! empty($data['force_fail'])) {
                throw new RuntimeException('Falla simulada en movimiento financiero.');
            }

            $this->balances->recalculateAccountBalance($account->fresh());

            if ($chartAccountId) {
                $this->chartUsage->remember((int) $chartAccountId);
            }

            $this->audit->log('movement_created', $movement, null, $movement->only([
                'code', 'type', 'scope', 'amount', 'financial_account_id', 'exchange_rate_value', 'status', 'client_id', 'supplier_id',
            ]), 'Movimiento creado');

            return $movement->fresh(['account', 'currency', 'category', 'subcategory', 'chartAccount']);
        });
    }

    /**
     * @param  array{
     *   from_account_id: int,
     *   to_account_id: int,
     *   amount: string|float|int,
     *   scope: string,
     *   movement_date?: string,
     *   movement_time?: string,
     *   description?: string|null,
     *   observations?: string|null,
     *   exchange_rate_id?: int|null,
     *   force_fail_after_first?: bool
     * }  $data
     * @return array{out: Movement, in: Movement}
     */
    public function createTransfer(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $fromId = (int) $data['from_account_id'];
            $toId = (int) $data['to_account_id'];

            if ($fromId === $toId) {
                throw new InvalidArgumentException('Las cuentas de origen y destino deben ser distintas.');
            }

            $ids = collect([$fromId, $toId])->sort()->values();
            $locked = FinancialAccount::query()->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
            $from = $locked->get($fromId)?->load('currency') ?? throw new InvalidArgumentException('Cuenta origen inválida.');
            $to = $locked->get($toId)?->load('currency') ?? throw new InvalidArgumentException('Cuenta destino inválida.');

            $this->assertAccountActive($from);
            $this->assertAccountActive($to);

            if ($from->currency_id !== $to->currency_id) {
                throw new InvalidArgumentException('En Etapa 2 las transferencias solo se permiten entre cuentas de la misma moneda.');
            }

            $amount = Money::normalize($data['amount']);
            if (! Money::isPositive($amount)) {
                throw new InvalidArgumentException('El importe debe ser mayor a cero.');
            }

            $date = $data['movement_date'] ?? now()->toDateString();
            $fx = $this->resolveFxSnapshot($from, $data['exchange_rate_id'] ?? null, $date);
            $equivalents = $this->equivalents($from->currency->code, $amount, $fx['value']);
            $transferId = (string) Str::uuid();
            $time = $data['movement_time'] ?? now()->format('H:i:s');
            $scope = MovementScope::from($data['scope']);
            $userId = Auth::id() ?? throw new RuntimeException('Usuario requerido.');
            $description = $data['description'] ?? 'Transferencia';
            $observations = $data['observations'] ?? null;

            $out = Movement::query()->create([
                'code' => $this->codes->allocate($date),
                'transfer_id' => $transferId,
                'movement_date' => $date,
                'movement_time' => $time,
                'user_id' => $userId,
                'scope' => $scope,
                'type' => MovementType::TransferOut,
                'financial_account_id' => $from->id,
                'currency_id' => $from->currency_id,
                'amount' => $amount,
                'exchange_rate_id' => $fx['id'],
                'exchange_rate_value' => $fx['value'],
                'exchange_rate_at' => $fx['at'],
                'amount_ars' => $equivalents['ars'],
                'amount_usd' => $equivalents['usd'],
                'description' => $description,
                'observations' => $observations,
                'status' => MovementStatus::Posted,
            ]);

            if (! empty($data['force_fail_after_first'])) {
                throw new RuntimeException('Falla simulada para probar rollback.');
            }

            $in = Movement::query()->create([
                'code' => $this->codes->allocate($date),
                'transfer_id' => $transferId,
                'movement_date' => $date,
                'movement_time' => $time,
                'user_id' => $userId,
                'scope' => $scope,
                'type' => MovementType::TransferIn,
                'financial_account_id' => $to->id,
                'currency_id' => $to->currency_id,
                'amount' => $amount,
                'exchange_rate_id' => $fx['id'],
                'exchange_rate_value' => $fx['value'],
                'exchange_rate_at' => $fx['at'],
                'amount_ars' => $equivalents['ars'],
                'amount_usd' => $equivalents['usd'],
                'description' => $description,
                'observations' => $observations,
                'status' => MovementStatus::Posted,
            ]);

            $this->balances->recalculateAccountBalance($from->fresh());
            $this->balances->recalculateAccountBalance($to->fresh());

            $this->audit->log('transfer_created', $out, null, [
                'transfer_id' => $transferId,
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => $amount,
                'out_code' => $out->code,
                'in_code' => $in->code,
            ], 'Transferencia creada');

            return ['out' => $out, 'in' => $in];
        });
    }

    /**
     * Edición Admin de un movimiento confirmado.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Movement $movement, array $data): Movement
    {
        if (! $movement->isPosted()) {
            throw new InvalidArgumentException('Solo se pueden editar movimientos confirmados (no anulados).');
        }

        return DB::transaction(function () use ($movement, $data) {
            $movement = Movement::query()->lockForUpdate()->findOrFail($movement->id);
            $oldAccountId = (int) $movement->financial_account_id;

            $fxMode = (string) ($data['fx_mode'] ?? '');
            $reason = trim((string) ($data['edit_reason'] ?? ''));

            $updates = [];
            $deltas = [];

            // Fecha
            if (array_key_exists('movement_date', $data) && $data['movement_date'] !== null) {
                $newDate = Carbon::parse((string) $data['movement_date'])->toDateString();
                $oldDate = $movement->movement_date?->toDateString();
                if ($newDate !== $oldDate) {
                    $updates['movement_date'] = $newDate;
                    $deltas[] = ['field' => 'movement_date', 'old' => $oldDate, 'new' => $newDate, 'reason' => $reason ?: null];
                }
            }

            if (array_key_exists('movement_time', $data) && $data['movement_time'] !== null) {
                $newTime = (string) $data['movement_time'];
                if ($newTime !== (string) $movement->movement_time) {
                    $updates['movement_time'] = $newTime;
                    $deltas[] = ['field' => 'movement_time', 'old' => $movement->movement_time, 'new' => $newTime, 'reason' => $reason ?: null];
                }
            }

            // Tipo (no transferencias)
            if (array_key_exists('type', $data) && $data['type'] !== null) {
                $newType = MovementType::from((string) $data['type']);
                if ($movement->isTransfer() || $newType->isTransfer()) {
                    if ($movement->type !== $newType) {
                        throw new InvalidArgumentException('No se puede cambiar el tipo de una transferencia por esta vía. Anulá y volvé a cargar.');
                    }
                } elseif ($movement->type !== $newType) {
                    $this->assertSensitiveReason($reason, 'type');
                    $this->assertNoBlockingLinks($movement, 'type');
                    $updates['type'] = $newType;
                    $deltas[] = ['field' => 'type', 'old' => $movement->type->value, 'new' => $newType->value, 'reason' => $reason];
                }
            }

            $effectiveType = isset($updates['type'])
                ? ($updates['type'] instanceof MovementType ? $updates['type'] : MovementType::from((string) $updates['type']))
                : $movement->type;

            // Ámbito / Origen
            if (array_key_exists('scope', $data) && $data['scope'] !== null) {
                $newScope = (string) $data['scope'];
                if (! $effectiveType->isTransfer()) {
                    $this->scopeRules->assertAllowed($effectiveType, $newScope);
                }
                if ($newScope !== $movement->scope->value) {
                    $updates['scope'] = MovementScope::from($newScope);
                    $deltas[] = ['field' => 'scope', 'old' => $movement->scope->value, 'new' => $newScope, 'reason' => $reason ?: null];
                }
            }

            // Descripción / observaciones
            foreach (['description', 'observations'] as $textField) {
                if (! array_key_exists($textField, $data)) {
                    continue;
                }
                $newVal = $data[$textField] !== null ? trim((string) $data[$textField]) : null;
                $newVal = $newVal === '' ? null : $newVal;
                $oldVal = $movement->{$textField};
                if ((string) ($oldVal ?? '') !== (string) ($newVal ?? '')) {
                    $updates[$textField] = $newVal;
                    $deltas[] = ['field' => $textField, 'old' => $oldVal, 'new' => $newVal, 'reason' => $reason ?: null];
                }
            }

            // Cuenta contable + cat/sub
            if (array_key_exists('chart_account_id', $data)
                || array_key_exists('category_id', $data)
                || array_key_exists('subcategory_id', $data)
            ) {
                $resolved = $this->concepts->resolveFromInput(
                    array_key_exists('chart_account_id', $data)
                        ? (! empty($data['chart_account_id']) ? (int) $data['chart_account_id'] : null)
                        : $movement->chart_account_id,
                    array_key_exists('category_id', $data)
                        ? (! empty($data['category_id']) ? (int) $data['category_id'] : null)
                        : $movement->category_id,
                    array_key_exists('subcategory_id', $data)
                        ? (! empty($data['subcategory_id']) ? (int) $data['subcategory_id'] : null)
                        : $movement->subcategory_id,
                );
                foreach (['chart_account_id', 'category_id', 'subcategory_id'] as $f) {
                    $newId = $resolved[$f] ?? null;
                    $oldId = $movement->{$f};
                    if ((int) ($oldId ?? 0) !== (int) ($newId ?? 0)) {
                        $updates[$f] = $newId;
                        $deltas[] = ['field' => $f, 'old' => $oldId, 'new' => $newId, 'reason' => $reason ?: null];
                    }
                }
            }

            // Cliente / proveedor
            foreach (['client_id', 'supplier_id'] as $rel) {
                if (! array_key_exists($rel, $data)) {
                    continue;
                }
                $newId = ! empty($data[$rel]) ? (int) $data[$rel] : null;
                $oldId = $movement->{$rel};
                if ((int) ($oldId ?? 0) !== (int) ($newId ?? 0)) {
                    if ($this->hasCommercialLink($movement) && in_array($rel, ['client_id', 'supplier_id'], true)) {
                        // Permitir si no hay cobro/cargo vinculado conflictivo; si hay receipt con client distinto, bloquear
                        $link = $this->blockingLinkSummary($movement);
                        if ($link && (($rel === 'client_id' && $movement->receipt) || ($rel === 'supplier_id' && $movement->supplierLedgerEntry))) {
                            throw new InvalidArgumentException(
                                "No se puede cambiar {$rel}: el movimiento está vinculado a {$link}. Anulá la relación comercial o editá desde allí."
                            );
                        }
                    }
                    $updates[$rel] = $newId;
                    $deltas[] = ['field' => $rel, 'old' => $oldId, 'new' => $newId, 'reason' => $reason ?: null];
                }
            }

            // Cuenta financiera (+ moneda derivada)
            $accountChanged = false;
            if (array_key_exists('financial_account_id', $data) && $data['financial_account_id'] !== null) {
                $newFaId = (int) $data['financial_account_id'];
                if ($newFaId !== (int) $movement->financial_account_id) {
                    $this->assertSensitiveReason($reason, 'financial_account_id');
                    $this->assertNoBlockingLinks($movement, 'financial_account_id');
                    if ($movement->isTransfer()) {
                        throw new InvalidArgumentException('No se puede cambiar la cuenta financiera de una pierna de transferencia aquí. Anulá y volvé a cargar.');
                    }
                    $newAccount = FinancialAccount::query()->lockForUpdate()->findOrFail($newFaId);
                    $this->assertAccountActive($newAccount);
                    $updates['financial_account_id'] = $newAccount->id;
                    $deltas[] = ['field' => 'financial_account_id', 'old' => $movement->financial_account_id, 'new' => $newAccount->id, 'reason' => $reason];
                    if ((int) $newAccount->currency_id !== (int) $movement->currency_id) {
                        $this->assertSensitiveReason($reason, 'currency_id');
                        $updates['currency_id'] = $newAccount->currency_id;
                        $deltas[] = ['field' => 'currency_id', 'old' => $movement->currency_id, 'new' => $newAccount->currency_id, 'reason' => $reason];
                    }
                    $accountChanged = true;
                }
            }

            // Importe
            $amountChanged = false;
            if (array_key_exists('amount', $data) && $data['amount'] !== null) {
                $newAmount = Money::normalize($data['amount']);
                if (! Money::isPositive($newAmount)) {
                    throw new InvalidArgumentException('El importe debe ser mayor a cero.');
                }
                if (Money::compare($newAmount, (string) $movement->amount) !== 0) {
                    $this->assertSensitiveReason($reason, 'amount');
                    $this->assertNoBlockingLinks($movement, 'amount');
                    $updates['amount'] = $newAmount;
                    $deltas[] = ['field' => 'amount', 'old' => $movement->amount, 'new' => $newAmount, 'reason' => $reason];
                    $amountChanged = true;
                }
            }

            // FX manual (sin mutar tabla de cotizaciones)
            $fxManual = false;
            if (array_key_exists('exchange_rate_value', $data) && $data['exchange_rate_value'] !== null && $data['exchange_rate_value'] !== '') {
                $newFx = Money::normalize($data['exchange_rate_value'], 6);
                if (! Money::isPositive($newFx)) {
                    throw new InvalidArgumentException('La cotización debe ser mayor a cero.');
                }
                if (Money::compare($newFx, (string) $movement->exchange_rate_value, 6) !== 0) {
                    $this->assertSensitiveReason($reason, 'exchange_rate_value');
                    $updates['exchange_rate_value'] = $newFx;
                    // Conservar id histórico solo como referencia; el valor editado es el del movimiento
                    $deltas[] = ['field' => 'exchange_rate_value', 'old' => $movement->exchange_rate_value, 'new' => $newFx, 'reason' => $reason];
                    $fxManual = true;
                }
            }

            $dateChanged = array_key_exists('movement_date', $updates);
            $needsFxDecision = $dateChanged && ! $fxManual;

            if ($needsFxDecision) {
                $frozenAt = $movement->exchange_rate_at
                    ? Carbon::parse($movement->exchange_rate_at)->toDateString()
                    : null;
                $targetDate = (string) $updates['movement_date'];
                $mismatch = $frozenAt && $frozenAt !== $targetDate;

                if ($mismatch || $fxMode === 'recalculate') {
                    if (! in_array($fxMode, ['recalculate', 'keep'], true)) {
                        $hist = $this->rates->rateForDate($targetDate);
                        $effective = $hist?->rate_at?->toDateString() ?? 'sin cotización';
                        throw new InvalidArgumentException(
                            "La fecha del movimiento ({$targetDate}) no coincide con la cotización congelada ({$frozenAt}). "
                            .'Elegí «Recalcular histórica» (última cotización previa; vigencia: '.$effective.') o «Conservar» cotización actual. '
                            .'No se recalcula en silencio.'
                        );
                    }
                }

                if ($fxMode === 'recalculate') {
                    $account = FinancialAccount::query()->with('currency')->findOrFail(
                        $updates['financial_account_id'] ?? $movement->financial_account_id
                    );
                    $fx = $this->resolveFxSnapshot($account, null, (string) $updates['movement_date']);
                    if ((string) $fx['value'] !== (string) $movement->exchange_rate_value
                        || (int) ($fx['id'] ?? 0) !== (int) ($movement->exchange_rate_id ?? 0)
                    ) {
                        $updates['exchange_rate_id'] = $fx['id'];
                        $updates['exchange_rate_value'] = $fx['value'];
                        $updates['exchange_rate_at'] = $fx['at'];
                        $deltas[] = [
                            'field' => 'exchange_rate_value',
                            'old' => $movement->exchange_rate_value,
                            'new' => $fx['value'],
                            'reason' => $reason ?: 'Recálculo histórico por cambio de fecha',
                        ];
                        $deltas[] = [
                            'field' => 'exchange_rate_at',
                            'old' => optional($movement->exchange_rate_at)?->format('Y-m-d H:i:s'),
                            'new' => Carbon::parse($fx['at'])->format('Y-m-d H:i:s'),
                            'reason' => $reason ?: 'Recálculo histórico por cambio de fecha',
                        ];
                    }
                } elseif ($fxMode === 'keep' && $dateChanged) {
                    $deltas[] = [
                        'field' => 'fx_mode',
                        'old' => 'frozen',
                        'new' => 'keep',
                        'reason' => $reason ?: 'Conservar cotización al cambiar fecha',
                    ];
                }
            }

            if ($deltas === [] && $updates === []) {
                return $movement->fresh(['account', 'currency', 'chartAccount', 'client', 'supplier']);
            }

            // Recalcular equivalentes si cambió importe, moneda, FA o FX
            $financialTouch = $amountChanged || $accountChanged || $fxManual
                || isset($updates['exchange_rate_value'])
                || isset($updates['currency_id']);

            if ($financialTouch || ($dateChanged && $fxMode === 'recalculate')) {
                $account = FinancialAccount::query()->with('currency')->lockForUpdate()->findOrFail(
                    $updates['financial_account_id'] ?? $movement->financial_account_id
                );
                $amount = (string) ($updates['amount'] ?? $movement->amount);
                $rate = (string) ($updates['exchange_rate_value'] ?? $movement->exchange_rate_value);
                $eq = $this->equivalents($account->currency->code, $amount, $rate);
                if ((string) $movement->amount_ars !== $eq['ars'] || (string) $movement->amount_usd !== $eq['usd']) {
                    $updates['amount_ars'] = $eq['ars'];
                    $updates['amount_usd'] = $eq['usd'];
                    $deltas[] = ['field' => 'amount_ars', 'old' => $movement->amount_ars, 'new' => $eq['ars'], 'reason' => $reason ?: null];
                    $deltas[] = ['field' => 'amount_usd', 'old' => $movement->amount_usd, 'new' => $eq['usd'], 'reason' => $reason ?: null];
                }
            }

            // Transferencia: sincronizar pierna pareja en campos compartidos
            $pair = null;
            if ($movement->transfer_id) {
                $pair = Movement::query()
                    ->where('transfer_id', $movement->transfer_id)
                    ->where('id', '!=', $movement->id)
                    ->lockForUpdate()
                    ->first();
            }

            $movement->update($updates);

            if ($pair) {
                $pairUpdates = array_intersect_key($updates, array_flip([
                    'movement_date', 'movement_time', 'scope', 'description', 'observations',
                    'amount', 'exchange_rate_id', 'exchange_rate_value', 'exchange_rate_at',
                    'amount_ars', 'amount_usd',
                ]));
                if ($pairUpdates !== []) {
                    $pair->update($pairUpdates);
                }
            }

            $touchedAccounts = collect([$oldAccountId, (int) $movement->financial_account_id])->unique();
            if ($pair) {
                $touchedAccounts->push((int) $pair->financial_account_id);
            }
            foreach ($touchedAccounts->unique() as $faId) {
                $fa = FinancialAccount::query()->lockForUpdate()->findOrFail($faId);
                $this->balances->recalculateAccountBalance($fa);
            }

            if (isset($updates['chart_account_id']) && $updates['chart_account_id']) {
                $this->chartUsage->remember((int) $updates['chart_account_id']);
            }

            $this->editAudits->recordDeltas($movement->fresh(), $deltas);

            // Un solo evento resumen en AuditLogger (sin snapshot completo ni bucles)
            $this->audit->log(
                'movement_updated',
                $movement,
                ['fields' => array_values(array_unique(array_column($deltas, 'field')))],
                ['code' => $movement->code, 'changes' => count($deltas)],
                'Movimiento editado (delta por campo)'
            );

            return $movement->fresh(['account', 'currency', 'chartAccount', 'client', 'supplier', 'category', 'subcategory']);
        });
    }

    /**
     * Preview de cotización histórica para UI de edición de fecha.
     *
     * @return array{rate: ?ExchangeRate, effective_date: ?string, value: ?string}
     */
    public function historicalRatePreview(string $date): array
    {
        $rate = $this->rates->rateForDate($date);

        return [
            'rate' => $rate,
            'effective_date' => $rate?->rate_at?->toDateString(),
            'value' => $rate ? Money::normalize($rate->rate, 6) : null,
        ];
    }

    /**
     * @return list<array{kind: string, label: string, id: int|string|null}>
     */
    public function linkedRelations(Movement $movement): array
    {
        $links = [];
        $receipt = Receipt::query()->where('financial_movement_id', $movement->id)->first();
        if ($receipt) {
            $links[] = ['kind' => 'receipt', 'label' => 'Cobro '.$receipt->number, 'id' => $receipt->id];
        }
        $sale = Sale::query()->where('financial_movement_id', $movement->id)->first();
        if ($sale) {
            $links[] = ['kind' => 'sale', 'label' => 'Venta '.$sale->number, 'id' => $sale->id];
        }
        $purchase = Purchase::query()->where('financial_movement_id', $movement->id)->first();
        if ($purchase) {
            $links[] = ['kind' => 'purchase', 'label' => 'Compra #'.$purchase->id, 'id' => $purchase->id];
        }
        $cle = ClientLedgerEntry::query()->where('financial_movement_id', $movement->id)->first();
        if ($cle) {
            $links[] = ['kind' => 'client_cc', 'label' => 'CC cliente #'.$cle->id, 'id' => $cle->id];
        }
        $sle = SupplierLedgerEntry::query()->where('financial_movement_id', $movement->id)->first();
        if ($sle) {
            $links[] = ['kind' => 'supplier_cc', 'label' => 'CC proveedor #'.$sle->id, 'id' => $sle->id];
        }

        return $links;
    }

    public function void(Movement $movement, string $reason): void
    {
        if (! $movement->isPosted()) {
            throw new InvalidArgumentException('El movimiento ya está anulado.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('El motivo de anulación es obligatorio.');
        }

        DB::transaction(function () use ($movement, $reason) {
            if ($movement->transfer_id) {
                $legs = Movement::query()
                    ->where('transfer_id', $movement->transfer_id)
                    ->lockForUpdate()
                    ->get();

                foreach ($legs as $leg) {
                    $this->markVoided($leg, $reason);
                    $account = FinancialAccount::query()->lockForUpdate()->findOrFail($leg->financial_account_id);
                    $this->balances->recalculateAccountBalance($account);
                    $this->editAudits->recordDeltas($leg, [[
                        'field' => 'status',
                        'old' => MovementStatus::Posted->value,
                        'new' => MovementStatus::Voided->value,
                        'reason' => $reason,
                    ]]);
                }

                $this->audit->log('transfer_voided', $movement, null, [
                    'transfer_id' => $movement->transfer_id,
                    'reason' => $reason,
                ], 'Transferencia anulada');

                return;
            }

            $account = FinancialAccount::query()->lockForUpdate()->findOrFail($movement->financial_account_id);
            $this->markVoided($movement, $reason);
            $this->balances->recalculateAccountBalance($account);
            $this->editAudits->recordDeltas($movement, [[
                'field' => 'status',
                'old' => MovementStatus::Posted->value,
                'new' => MovementStatus::Voided->value,
                'reason' => $reason,
            ]]);
            $this->audit->log('movement_voided', $movement, ['status' => 'posted'], [
                'status' => 'voided',
                'reason' => $reason,
            ], 'Movimiento anulado');
        });
    }

    private function markVoided(Movement $movement, string $reason): void
    {
        $movement->update([
            'status' => MovementStatus::Voided,
            'void_reason' => $reason,
            'voided_by' => Auth::id(),
            'voided_at' => now(),
        ]);
    }

    private function assertSensitiveReason(string $reason, string $field): void
    {
        if ($reason === '') {
            throw new InvalidArgumentException(
                'El motivo es obligatorio para cambiar '.$this->fieldLabel($field).'.'
            );
        }
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'amount' => 'el importe',
            'currency_id' => 'la moneda',
            'financial_account_id' => 'la cuenta financiera',
            'type' => 'el tipo',
            'exchange_rate_value' => 'la cotización (FX)',
            'status' => 'el estado (anulación)',
            default => $field,
        };
    }

    private function assertNoBlockingLinks(Movement $movement, string $field): void
    {
        $summary = $this->blockingLinkSummary($movement);
        if ($summary) {
            throw new InvalidArgumentException(
                'No se puede cambiar '.$this->fieldLabel($field)
                .' porque el movimiento está vinculado a '.$summary
                .'. La edición financiera no se aplica parcialmente: resolvé o anulá la relación comercial primero.'
            );
        }
    }

    private function hasCommercialLink(Movement $movement): bool
    {
        return $this->blockingLinkSummary($movement) !== null;
    }

    private function blockingLinkSummary(Movement $movement): ?string
    {
        $links = $this->linkedRelations($movement);
        if ($links === []) {
            return null;
        }

        return collect($links)->pluck('label')->implode(', ');
    }

    /**
     * @return array{id: ?int, value: string, at: \Illuminate\Support\Carbon|string}
     */
    private function resolveFxSnapshot(FinancialAccount $account, ?int $exchangeRateId, ?string $asOfDate = null): array
    {
        $account->loadMissing('currency');

        if ($exchangeRateId) {
            $rate = ExchangeRate::query()->findOrFail($exchangeRateId);

            return [
                'id' => $rate->id,
                'value' => Money::normalize($rate->rate, 6),
                'at' => $rate->rate_at,
            ];
        }

        $rate = null;
        if ($asOfDate) {
            $rate = $this->rates->rateForDate($asOfDate);
        }
        if (! $rate) {
            try {
                $rate = $this->rates->latestOfficialSell(trySync: false)['rate'];
            } catch (Throwable) {
                throw new RuntimeException('Se requiere una cotización vigente para registrar el movimiento.');
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
            return [
                'ars' => $amount,
                'usd' => Money::div($amount, $rate),
            ];
        }

        if ($currencyCode === 'USD') {
            return [
                'ars' => Money::mul($amount, $rate),
                'usd' => $amount,
            ];
        }

        throw new InvalidArgumentException('Moneda no soportada en Etapa 2.');
    }

    private function assertAccountActive(FinancialAccount $account): void
    {
        if (! $account->isActive()) {
            throw new InvalidArgumentException('La cuenta financiera no está activa.');
        }
    }
}
