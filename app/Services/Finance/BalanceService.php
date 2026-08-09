<?php

namespace App\Services\Finance;

use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\FinancialAccount;
use App\Models\Movement;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BalanceService
{
    public function recalculateAccountBalance(FinancialAccount $account): string
    {
        $balance = $this->computeAccountBalance($account->id);
        $account->forceFill(['cached_balance' => $balance])->save();

        return $balance;
    }

    public function computeAccountBalance(int $accountId): string
    {
        $rows = Movement::query()
            ->posted()
            ->where('financial_account_id', $accountId)
            ->get(['type', 'amount']);

        $balance = '0.00';
        foreach ($rows as $row) {
            /** @var MovementType $type */
            $type = $row->type;
            $delta = Money::mul($row->amount, (string) $type->signedMultiplier(), 2);
            $balance = Money::add($balance, $delta, 2);
        }

        return $balance;
    }

    /**
     * @return array{
     *   ars_total: string,
     *   usd_total: string,
     *   ars_equivalent: string,
     *   usd_equivalent: string,
     *   accounts: Collection,
     *   by_scope: array{personal: array{ars: string, usd: string}, professional: array{ars: string, usd: string}}
     * }
     */
    public function availableMoney(?ExchangeRate $rate = null): array
    {
        $accounts = FinancialAccount::query()->with('currency')->active()->orderBy('name')->get();
        $rateValue = $rate?->rate;

        $arsTotal = '0.00';
        $usdTotal = '0.00';

        foreach ($accounts as $account) {
            $balance = $this->computeAccountBalance($account->id);
            $account->setAttribute('computed_balance', $balance);

            if ($account->currency->code === 'ARS') {
                $arsTotal = Money::add($arsTotal, $balance);
            } elseif ($account->currency->code === 'USD') {
                $usdTotal = Money::add($usdTotal, $balance);
            }
        }

        $arsEquivalent = $arsTotal;
        $usdEquivalent = $usdTotal;

        if ($rateValue && Money::isPositive((string) $rateValue)) {
            $usdAsArs = Money::mul($usdTotal, (string) $rateValue);
            $arsEquivalent = Money::add($arsTotal, $usdAsArs);
            $arsAsUsd = Money::div($arsTotal, (string) $rateValue);
            $usdEquivalent = Money::add($usdTotal, $arsAsUsd);
        }

        return [
            'ars_total' => $arsTotal,
            'usd_total' => $usdTotal,
            'ars_equivalent' => $arsEquivalent,
            'usd_equivalent' => $usdEquivalent,
            'accounts' => $accounts,
            'by_scope' => $this->activityByScopeTotals(),
        ];
    }

    /**
     * Resultado del mes (ingresos - gastos), excluye transferencias.
     *
     * @return array{income: string, expense: string, result: string, personal_result: string, professional_result: string}
     */
    public function monthlyActivity(?string $yearMonth = null): array
    {
        $yearMonth ??= now()->format('Y-m');

        $query = Movement::query()
            ->posted()
            ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value])
            ->whereRaw($this->monthExpression(), [$yearMonth]);

        $income = '0.00';
        $expense = '0.00';
        $personal = '0.00';
        $professional = '0.00';

        foreach ($query->get(['type', 'scope', 'amount_ars']) as $row) {
            $amount = (string) $row->amount_ars;
            if ($row->type === MovementType::Income) {
                $income = Money::add($income, $amount);
                $signed = $amount;
            } else {
                $expense = Money::add($expense, $amount);
                $signed = Money::mul($amount, '-1');
            }

            if ($row->scope->value === 'personal') {
                $personal = Money::add($personal, $signed);
            } else {
                $professional = Money::add($professional, $signed);
            }
        }

        return [
            'income' => $income,
            'expense' => $expense,
            'result' => Money::sub($income, $expense),
            'personal_result' => $personal,
            'professional_result' => $professional,
        ];
    }

    private function activityByScopeTotals(): array
    {
        // Placeholder structure for dashboard distribution of available money by scope
        // Available money is account-based; scope distribution uses month activity result.
        $month = $this->monthlyActivity();

        return [
            'personal' => ['result_ars' => $month['personal_result']],
            'professional' => ['result_ars' => $month['professional_result']],
        ];
    }

    private function monthExpression(): string
    {
        // Compatible MySQL + SQLite
        $driver = DB::connection()->getDriverName();

        return $driver === 'sqlite'
            ? "strftime('%Y-%m', movement_date) = ?"
            : "DATE_FORMAT(movement_date, '%Y-%m') = ?";
    }
}
