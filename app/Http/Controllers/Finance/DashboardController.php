<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Movement;
use App\Services\Finance\BalanceService;
use App\Services\Finance\ExchangeRateService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly BalanceService $balances,
        private readonly ExchangeRateService $rates,
    ) {}

    public function __invoke(): View
    {
        $rate = null;
        $rateLabel = null;
        try {
            $info = $this->rates->latestOfficialSell(false);
            $rate = $info['rate'];
            $rateLabel = $info['source_label'];
        } catch (\Throwable) {
        }

        $money = $this->balances->availableMoney($rate);
        $month = $this->balances->monthlyActivity();
        $recent = Movement::query()
            ->with(['account.currency', 'currency', 'category'])
            ->latest('movement_date')
            ->latest('id')
            ->limit(10)
            ->get();

        return view('finance.dashboard', compact('money', 'month', 'recent', 'rate', 'rateLabel'));
    }
}
