<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Models\Currency;
use App\Models\FinancialAccount;
use Illuminate\Database\Seeder;

class FinancialAccountSeeder extends Seeder
{
    public function run(): void
    {
        $ars = Currency::query()->where('code', 'ARS')->firstOrFail();
        $usd = Currency::query()->where('code', 'USD')->firstOrFail();

        $accounts = [
            ['name' => 'Caja ARS', 'type' => AccountType::Cash, 'currency_id' => $ars->id],
            ['name' => 'Caja USD', 'type' => AccountType::Cash, 'currency_id' => $usd->id],
            ['name' => 'Banco ARS', 'type' => AccountType::Bank, 'currency_id' => $ars->id],
            ['name' => 'Banco USD', 'type' => AccountType::Bank, 'currency_id' => $usd->id],
            ['name' => 'Mercado Pago', 'type' => AccountType::Wallet, 'currency_id' => $ars->id],
        ];

        foreach ($accounts as $account) {
            FinancialAccount::query()->updateOrCreate(
                ['name' => $account['name']],
                [
                    'type' => $account['type']->value,
                    'currency_id' => $account['currency_id'],
                    'status' => 'active',
                    'cached_balance' => 0,
                ]
            );
        }
    }
}
