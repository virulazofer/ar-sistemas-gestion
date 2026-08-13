<?php

namespace App\Services\Finance;

use App\Enums\AccountType;
use App\Models\ChartAccount;
use App\Models\FinancialAccount;

/**
 * Vincula cada cuenta financiera a su ubicación en el plan (Activo/Pasivo).
 * FA es el maestro único; el plan solo refleja por tipo (sin nodos duplicados por FA).
 */
class FinancialAccountChartLinker
{
    public function locationCodeFor(FinancialAccount $account): ?string
    {
        $type = $account->type instanceof AccountType
            ? $account->type->value
            : (string) $account->type;

        return match ($type) {
            AccountType::Cash->value => (string) config('finance.financial_account_chart_codes.cash', '1.1.1'),
            AccountType::Bank->value => (string) config('finance.financial_account_chart_codes.bank', '1.1.2'),
            AccountType::Wallet->value => (string) config('finance.financial_account_chart_codes.wallet', '1.1.3'),
            AccountType::CreditCard->value => (string) config('finance.financial_account_chart_codes.credit_card', '2.1'),
            default => null,
        };
    }

    public function accountTypeForCode(string $code): ?AccountType
    {
        return match ($code) {
            '1.1.1' => AccountType::Cash,
            '1.1.2' => AccountType::Bank,
            '1.1.3' => AccountType::Wallet,
            '2.1' => AccountType::CreditCard,
            default => null,
        };
    }

    /**
     * @return list<string>|null  null = no es hoja de disponibilidades tipada
     */
    public function accountTypesForCode(string $code): ?array
    {
        return match (true) {
            $code === '1.1.1' => [AccountType::Cash->value],
            $code === '1.1.2' => [AccountType::Bank->value],
            $code === '1.1.3' => [AccountType::Wallet->value],
            $code === '2.1' => [AccountType::CreditCard->value],
            $code === '1.1' => [
                AccountType::Cash->value,
                AccountType::Bank->value,
                AccountType::Wallet->value,
            ],
            default => null,
        };
    }

    public function resolveLocation(FinancialAccount $account): ?ChartAccount
    {
        $code = $this->locationCodeFor($account);
        if (! $code) {
            return null;
        }

        return ChartAccount::query()->where('code', $code)->first();
    }

    public function link(FinancialAccount $account, bool $force = false): FinancialAccount
    {
        $location = $this->resolveLocation($account);
        if (! $location) {
            return $account;
        }

        if ($account->chart_account_id && ! $force
            && (int) $account->chart_account_id === (int) $location->id) {
            return $account;
        }

        if ($account->chart_account_id && ! $force) {
            return $account;
        }

        if ((int) $account->chart_account_id !== (int) $location->id) {
            $account->chart_account_id = $location->id;
            $account->save();
        }

        return $account->fresh() ?? $account;
    }

    /**
     * @return array{linked:int,skipped:int,missing_code:list<string>}
     */
    public function linkAll(bool $force = false): array
    {
        $linked = 0;
        $skipped = 0;
        $missing = [];

        FinancialAccount::query()->orderBy('id')->each(function (FinancialAccount $fa) use ($force, &$linked, &$skipped, &$missing) {
            $code = $this->locationCodeFor($fa);
            if (! $code) {
                $missing[] = $fa->name.'→(sin tipo reconocido)';
                $skipped++;

                return;
            }
            $loc = ChartAccount::query()->where('code', $code)->first();
            if (! $loc) {
                $missing[] = $fa->name.'→'.$code;
                $skipped++;

                return;
            }
            if ($fa->chart_account_id && ! $force && (int) $fa->chart_account_id === (int) $loc->id) {
                $skipped++;

                return;
            }
            if ($fa->chart_account_id && ! $force) {
                $skipped++;

                return;
            }
            $fa->update(['chart_account_id' => $loc->id]);
            $linked++;
        });

        return ['linked' => $linked, 'skipped' => $skipped, 'missing_code' => $missing];
    }
}
