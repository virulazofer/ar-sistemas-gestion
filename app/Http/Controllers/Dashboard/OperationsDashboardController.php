<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardService;
use App\Services\Finance\ExchangeRateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OperationsDashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly ExchangeRateService $rates,
    ) {}

    public function __invoke(Request $request): View
    {
        $scope = (string) $request->query('scope', 'all');
        if (! in_array($scope, ['personal', 'professional', 'all'], true)) {
            $scope = 'all';
        }

        $data = $this->dashboard->snapshot($scope);

        return view('dashboard.operations', ['data' => $data]);
    }

    public function refreshRate(): RedirectResponse
    {
        try {
            $this->rates->latestOfficialSell(true);
            $this->dashboard->clearCache();
        } catch (\Throwable $e) {
            return back()->withErrors(['rate' => $e->getMessage()]);
        }

        return back()->with('status', 'Cotización actualizada (no modifica históricas).');
    }
}
