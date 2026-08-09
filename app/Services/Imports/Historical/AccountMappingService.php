<?php

namespace App\Services\Imports\Historical;

use App\Enums\AccountType;
use App\Enums\ImportReviewStatus;
use App\Models\AccountHolder;
use App\Models\Currency;
use App\Models\FinancialAccount;
use Illuminate\Support\Str;

class AccountMappingService
{
    /**
     * @return array{holders: list<array<string,mixed>>, accounts: list<array<string,mixed>>}
     */
    public function ensurePreviewMasters(): array
    {
        $holders = [];
        foreach (config('historical_import.account_holders', []) as $row) {
            $holder = AccountHolder::query()->updateOrCreate(
                ['code' => $row['code']],
                ['name' => $row['name'], 'is_active' => true]
            );
            $holders[] = ['id' => $holder->id, 'code' => $holder->code, 'name' => $holder->name];
        }

        $accounts = [];
        foreach (config('historical_import.financial_aliases', []) as $alias => $def) {
            $holder = AccountHolder::query()->where('code', $def['holder'])->first();
            $currency = Currency::query()->where('code', $def['currency'])->firstOrFail();
            $type = AccountType::from($def['type']);
            $account = FinancialAccount::query()->updateOrCreate(
                ['name' => $def['name']],
                [
                    'type' => $type->value,
                    'currency_id' => $currency->id,
                    'account_holder_id' => $holder?->id,
                    'is_liability' => (bool) ($def['liability'] ?? $type->isLiability()),
                    'alias' => $def['alias'] ?? $alias,
                    'aliases' => array_values(array_unique(array_filter([$alias, $def['alias'] ?? null]))),
                    'status' => 'active',
                ]
            );
            $accounts[] = [
                'id' => $account->id,
                'name' => $account->name,
                'alias' => $alias,
                'type' => $type->value,
                'is_liability' => (bool) $account->is_liability,
                'holder' => $holder?->code,
                'currency' => $def['currency'],
            ];
        }

        return compact('holders', 'accounts');
    }

    public function resolveAlias(?string $subcuenta): ?array
    {
        $key = trim((string) $subcuenta);
        if ($key === '') {
            return null;
        }

        $map = config('historical_import.financial_aliases', []);
        if (isset($map[$key])) {
            return $map[$key] + ['_matched_alias' => $key];
        }

        // Heurística: contiene Gabi → titular Gabi
        foreach ($map as $alias => $def) {
            if (strcasecmp($alias, $key) === 0) {
                return $def + ['_matched_alias' => $alias];
            }
        }

        if (Str::contains(Str::lower($key), 'gabi')) {
            return [
                'name' => $key,
                'type' => 'bank',
                'currency' => 'ARS',
                'holder' => 'gabi',
                'alias' => $key,
                '_inferred' => true,
            ];
        }

        return null;
    }

    public function inferHolderFromAlias(string $alias): string
    {
        if (Str::contains(Str::lower($alias), 'gabi')) {
            return 'gabi';
        }

        return 'fernando';
    }
}
