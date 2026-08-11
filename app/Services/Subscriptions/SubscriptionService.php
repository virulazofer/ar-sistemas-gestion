<?php

namespace App\Services\Subscriptions;

use App\Enums\CommercialChargeType;
use App\Enums\DocumentalStatus;
use App\Enums\SubscriptionPeriodicity;
use App\Enums\SubscriptionStatus;
use App\Models\Client;
use App\Models\Subscription;
use App\Models\SubscriptionPeriod;
use App\Services\AuditLogger;
use App\Services\Clients\ClientLedgerService;
use App\Services\Commercial\CommercialChargeService;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class SubscriptionService
{
    public function __construct(
        private readonly ClientLedgerService $ledger,
        private readonly CommercialChargeService $charges,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $data): Subscription
    {
        return DB::transaction(function () use ($data) {
            $client = Client::query()->findOrFail($data['client_id']);
            if (! $client->isActive()) {
                throw new InvalidArgumentException('El cliente no está activo.');
            }

            $periodicity = SubscriptionPeriodicity::from($data['periodicity']);
            $starts = Carbon::parse($data['starts_on'])->startOfDay();
            $billingDay = (int) ($data['billing_day'] ?? $starts->day);

            $subscription = Subscription::query()->create([
                'client_id' => $client->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'periodicity' => $periodicity,
                'amount' => Money::normalize($data['amount']),
                'currency_code' => strtoupper((string) ($data['currency_code'] ?? 'USD')),
                'starts_on' => $starts->toDateString(),
                'ends_on' => $data['ends_on'] ?? null,
                'status' => SubscriptionStatus::Active,
                'billing_day' => min(28, max(1, $billingDay)),
                'next_generation_on' => $data['next_generation_on'] ?? $starts->toDateString(),
                'reminder_days_before' => $data['reminder_days_before'] ?? null,
                'terms' => $data['terms'] ?? null,
                'notes' => $data['notes'] ?? null,
                'user_id' => Auth::id() ?? throw new RuntimeException('Usuario requerido.'),
            ]);

            $this->syncRemindOn($subscription);
            $this->audit->log('subscription_created', $subscription, null, $subscription->fresh()->toArray(), 'Abono creado');

            return $subscription->fresh();
        });
    }

    public function update(Subscription $subscription, array $data): Subscription
    {
        return DB::transaction(function () use ($subscription, $data) {
            $old = $subscription->toArray();
            $payload = [];
            foreach (['name', 'description', 'terms', 'notes', 'billing_day', 'ends_on', 'next_generation_on', 'reminder_days_before'] as $field) {
                if (array_key_exists($field, $data)) {
                    $payload[$field] = $data[$field];
                }
            }
            if (isset($data['amount'])) {
                $payload['amount'] = Money::normalize($data['amount']);
            }
            if (isset($data['currency_code'])) {
                $payload['currency_code'] = strtoupper((string) $data['currency_code']);
            }
            if (isset($data['periodicity'])) {
                $payload['periodicity'] = SubscriptionPeriodicity::from($data['periodicity'])->value;
            }

            $subscription->update($payload);
            $this->syncRemindOn($subscription->fresh());
            $this->audit->log('subscription_updated', $subscription, $old, $subscription->fresh()->toArray(), 'Abono actualizado');

            return $subscription->fresh();
        });
    }

    public function changeStatus(Subscription $subscription, SubscriptionStatus $status, ?string $reason = null): Subscription
    {
        $old = $subscription->status;
        $subscription->update(['status' => $status]);
        $this->syncRemindOn($subscription->fresh());
        $this->audit->log('subscription_status_changed', $subscription, ['status' => $old->value], [
            'status' => $status->value,
            'reason' => $reason,
        ], 'Estado de abono');

        return $subscription->fresh();
    }

    /**
     * Genera el cargo del período indicado (o el próximo pendiente).
     * Idempotente por (subscription_id, period_key).
     */
    public function generatePeriod(Subscription $subscription, ?Carbon $asOf = null, ?string $forcePeriodKey = null): ?SubscriptionPeriod
    {
        return DB::transaction(function () use ($subscription, $asOf, $forcePeriodKey) {
            $subscription = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);

            if (! $subscription->status->generatesCharges()) {
                return null;
            }

            $asOf = ($asOf ?? now())->copy()->startOfDay();
            [$periodStart, $periodEnd, $periodKey] = $this->resolvePeriod($subscription, $asOf, $forcePeriodKey);

            $existing = SubscriptionPeriod::query()
                ->where('subscription_id', $subscription->id)
                ->where('period_key', $periodKey)
                ->first();

            if ($existing) {
                return $existing; // idempotente: no duplica
            }

            if ($subscription->ends_on && $periodStart->gt($subscription->ends_on)) {
                $subscription->update(['status' => SubscriptionStatus::Ended]);

                return null;
            }

            $amount = Money::normalize((string) $subscription->amount);

            $period = SubscriptionPeriod::query()->create([
                'subscription_id' => $subscription->id,
                'period_key' => $periodKey,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'amount' => $amount,
                'currency_code' => $subscription->currency_code,
                'client_ledger_entry_id' => null,
                'commercial_charge_id' => null,
                'status' => 'generated',
                'documental_status' => DocumentalStatus::None->value,
                'generated_at' => now(),
                'generated_by' => Auth::id(),
            ]);

            $charge = $this->charges->create([
                'client_id' => $subscription->client_id,
                'charge_type' => CommercialChargeType::Subscription->value,
                'concept' => 'Abono '.$subscription->name.' · '.$periodKey,
                'amount' => $amount,
                'currency_code' => $subscription->currency_code,
                'charged_on' => $periodStart->toDateString(),
                'subscription_id' => $subscription->id,
                'subscription_period_id' => $period->id,
                'documental_status' => DocumentalStatus::None->value,
                'apply_available_credit' => true,
                'wrap_transaction' => false,
            ]);

            $period->update([
                'client_ledger_entry_id' => $charge->client_ledger_entry_id,
                'commercial_charge_id' => $charge->id,
            ]);

            $next = $periodStart->copy()->addMonthsNoOverflow($subscription->periodicity->months());
            $subscription->update(['next_generation_on' => $next->toDateString()]);
            $this->syncRemindOn($subscription->fresh());

            $this->audit->log('subscription_period_generated', $period, null, [
                'period_key' => $periodKey,
                'amount' => $amount,
                'ledger_id' => $charge->client_ledger_entry_id,
                'commercial_charge_id' => $charge->id,
            ], 'Cargo de abono generado');

            return $period->fresh();
        });
    }

    /**
     * Genera cargos para todos los abonos activos con next_generation_on <= $asOf.
     */
    public function generateDue(?Carbon $asOf = null): int
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();
        $count = 0;

        Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->where(function ($q) use ($asOf) {
                $q->whereNull('next_generation_on')
                    ->orWhereDate('next_generation_on', '<=', $asOf->toDateString());
            })
            ->orderBy('id')
            ->each(function (Subscription $subscription) use ($asOf, &$count) {
                // Puede requerir varios períodos atrasados
                $guard = 0;
                while ($guard < 36) {
                    $subscription->refresh();
                    if (! $subscription->status->generatesCharges()) {
                        break;
                    }
                    if ($subscription->next_generation_on && $subscription->next_generation_on->gt($asOf)) {
                        break;
                    }
                    $period = $this->generatePeriod($subscription, $subscription->next_generation_on ?? $asOf);
                    if (! $period) {
                        break;
                    }
                    $count++;
                    $guard++;
                }
            });

        return $count;
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function resolvePeriod(Subscription $subscription, Carbon $asOf, ?string $forcePeriodKey): array
    {
        if ($forcePeriodKey) {
            // YYYY-MM
            $start = Carbon::createFromFormat('Y-m', $forcePeriodKey)->startOfMonth();
            $end = $start->copy()->addMonthsNoOverflow($subscription->periodicity->months())->subDay();

            return [$start, $end, $forcePeriodKey];
        }

        $anchor = $subscription->next_generation_on
            ? $subscription->next_generation_on->copy()->startOfDay()
            : $subscription->starts_on->copy()->startOfDay();

        if ($anchor->gt($asOf)) {
            $anchor = $asOf->copy();
        }

        $start = $anchor->copy()->startOfMonth();
        // Para mensual la clave es YYYY-MM del inicio
        $months = $subscription->periodicity->months();
        if ($months === 1) {
            $key = $start->format('Y-m');
            $end = $start->copy()->endOfMonth();
        } else {
            $key = $start->format('Y-m').'+'.$months.'m';
            $end = $start->copy()->addMonthsNoOverflow($months)->subDay();
        }

        return [$start, $end, $key];
    }

    /**
     * Calcula remind_on a partir de next_generation_on y reminder_days_before.
     * Sin envío de WhatsApp/email/SMS (Etapa posterior).
     */
    private function syncRemindOn(Subscription $subscription): void
    {
        if (! $subscription->status->generatesCharges() || ! $subscription->next_generation_on || $subscription->reminder_days_before === null) {
            $subscription->update(['remind_on' => null]);

            return;
        }

        $remindOn = $subscription->next_generation_on
            ->copy()
            ->subDays((int) $subscription->reminder_days_before)
            ->toDateString();

        $subscription->update(['remind_on' => $remindOn]);
    }
}
