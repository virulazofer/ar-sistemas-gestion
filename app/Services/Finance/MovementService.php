<?php

namespace App\Services\Finance;

use App\Enums\MovementScope;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\FinancialAccount;
use App\Models\Movement;
use App\Models\Subcategory;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class MovementService
{
    public function __construct(
        private readonly BalanceService $balances,
        private readonly ExchangeRateService $rates,
        private readonly AuditLogger $audit,
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
     *   description?: string|null,
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

        return DB::transaction(function () use ($data, $type) {
            $account = FinancialAccount::query()->lockForUpdate()->findOrFail($data['financial_account_id']);
            $this->assertAccountActive($account);

            $amount = Money::normalize($data['amount']);
            if (! Money::isPositive($amount)) {
                throw new InvalidArgumentException('El importe debe ser mayor a cero.');
            }

            $fx = $this->resolveFxSnapshot($account, $data['exchange_rate_id'] ?? null);
            $equivalents = $this->equivalents($account->currency->code, $amount, $fx['value']);

            $categoryId = $data['category_id'] ?? null;
            $subcategoryId = $data['subcategory_id'] ?? null;
            $chartAccountId = $this->resolveChartAccountId($categoryId, $subcategoryId);

            $movement = Movement::query()->create([
                'transfer_id' => null,
                'movement_date' => $data['movement_date'] ?? now()->toDateString(),
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
                'status' => MovementStatus::Posted,
                'client_id' => $data['client_id'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
            ]);

            if (! empty($data['force_fail'])) {
                throw new RuntimeException('Falla simulada en movimiento financiero.');
            }

            $this->balances->recalculateAccountBalance($account->fresh());

            $this->audit->log('movement_created', $movement, null, $movement->only([
                'type', 'scope', 'amount', 'financial_account_id', 'exchange_rate_value', 'status', 'client_id', 'supplier_id',
            ]), 'Movimiento creado');

            return $movement->fresh(['account', 'currency', 'category', 'subcategory']);
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

            // Lock en orden estable para evitar deadlocks
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

            $fx = $this->resolveFxSnapshot($from, $data['exchange_rate_id'] ?? null);
            $equivalents = $this->equivalents($from->currency->code, $amount, $fx['value']);
            $transferId = (string) Str::uuid();
            $date = $data['movement_date'] ?? now()->toDateString();
            $time = $data['movement_time'] ?? now()->format('H:i:s');
            $scope = MovementScope::from($data['scope']);
            $userId = Auth::id() ?? throw new RuntimeException('Usuario requerido.');
            $description = $data['description'] ?? 'Transferencia';

            $out = Movement::query()->create([
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
                'status' => MovementStatus::Posted,
            ]);

            if (! empty($data['force_fail_after_first'])) {
                throw new RuntimeException('Falla simulada para probar rollback.');
            }

            $in = Movement::query()->create([
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
                'status' => MovementStatus::Posted,
            ]);

            $this->balances->recalculateAccountBalance($from->fresh());
            $this->balances->recalculateAccountBalance($to->fresh());

            $this->audit->log('transfer_created', $out, null, [
                'transfer_id' => $transferId,
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => $amount,
            ], 'Transferencia creada');

            return ['out' => $out, 'in' => $in];
        });
    }

    public function void(Movement $movement, string $reason): void
    {
        if (! $movement->isPosted()) {
            throw new InvalidArgumentException('El movimiento ya está anulado.');
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

    /**
     * @return array{id: ?int, value: string, at: \Illuminate\Support\Carbon|string}
     */
    private function resolveFxSnapshot(FinancialAccount $account, ?int $exchangeRateId): array
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

        // ARS puro: rate 1 conceptualmente, pero guardamos última USD venta para equivalentes USD
        try {
            $latest = $this->rates->latestOfficialSell(trySync: false)['rate'];
        } catch (Throwable) {
            // Sin cotización: solo permitido si la cuenta es ARS y no necesitamos USD estricto?
            // Requerimos cotización siempre para congelar equivalentes.
            throw new RuntimeException('Se requiere una cotización vigente para registrar el movimiento.');
        }

        return [
            'id' => $latest->id,
            'value' => Money::normalize($latest->rate, 6),
            'at' => $latest->rate_at,
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

    private function resolveChartAccountId(?int $categoryId, ?int $subcategoryId): ?int
    {
        if ($subcategoryId) {
            $sub = Subcategory::query()->find($subcategoryId);
            if ($sub?->chart_account_id) {
                return $sub->chart_account_id;
            }
            if ($sub && $categoryId && $sub->category_id !== $categoryId) {
                throw new InvalidArgumentException('La subcategoría no pertenece a la categoría indicada.');
            }
        }

        if ($categoryId) {
            return Category::query()->find($categoryId)?->chart_account_id;
        }

        return null;
    }

    private function assertAccountActive(FinancialAccount $account): void
    {
        if (! $account->isActive()) {
            throw new InvalidArgumentException('La cuenta financiera no está activa.');
        }
    }
}
