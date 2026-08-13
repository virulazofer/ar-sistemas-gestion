<?php

namespace App\Services\Finance;

use App\Enums\AccountType;
use App\Models\ChartAccount;
use App\Models\FinancialAccount;

/**
 * Vincula cada cuenta financiera a su ubicación en el plan (Activo/Pasivo).
 * No se pide mapeo por movimiento.
 */
class FinancialAccountChartLinker
{
    public function locationCodeFor(FinancialAccount $account): ?string
    {
        $type = $account->type instanceof AccountType
            ? $account->type->value
            : (string) $account->type;

        if ($account->is_liability || $type === AccountType::CreditCard->value) {
            return (string) config('finance.financial_account_chart_codes.credit_card', '2.1');
        }

        return config('finance.financial_account_chart_codes.'.$type)
            ?? config('finance.financial_account_chart_codes.other');
    }

    public function resolveLocation(FinancialAccount $account): ?ChartAccount
    {
        if ($account->chart_account_id) {
            return $account->chartAccount ?? ChartAccount::query()->find($account->chart_account_id);
        }

        $code = $this->locationCodeFor($account);
        if (! $code) {
            return null;
        }

        return ChartAccount::query()->where('code', $code)->first();
    }

    public function link(FinancialAccount $account, bool $force = false): FinancialAccount
    {
        if ($account->chart_account_id && ! $force) {
            return $account;
        }

        $location = $this->resolveLocation($account);
        if ($location && (int) $account->chart_account_id !== (int) $location->id) {
            $account->chart_account_id = $location->id;
            $account->save();
        }

        return $account->fresh();
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
            $loc = ChartAccount::query()->where('code', $code)->first();
            if (! $loc) {
                $missing[] = $fa->name.'→'.$code;
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
