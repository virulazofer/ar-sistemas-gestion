<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\ManagementDashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ManagementDashboardController extends Controller
{
    public function __construct(
        private readonly ManagementDashboardService $dashboard,
    ) {}

    public function __invoke(Request $request): View
    {
        $input = $request->validate([
            'preset' => ['nullable', 'string', 'in:this_month,previous_month,year,custom,month'],
            'ym' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'from' => ['nullable', 'string', 'max:20'],
            'to' => ['nullable', 'string', 'max:20'],
            'scope' => ['nullable', 'string', 'in:all,personal,professional'],
            'chart_months' => ['nullable', 'integer', 'in:6,12'],
            'sort_income' => ['nullable', 'string', 'max:32'],
            'sort_expense' => ['nullable', 'string', 'max:32'],
        ]);

        $data = $this->dashboard->build($input);

        return view('dashboard.management', [
            'data' => $data,
            'filters' => [
                'preset' => $input['preset'] ?? $data['period']['preset'],
                'ym' => $input['ym'] ?? $data['period']['ym'],
                'from' => $input['from'] ?? $data['period']['from_label'],
                'to' => $input['to'] ?? $data['period']['to_label'],
                'scope' => $data['scope'],
                'chart_months' => $data['chart_months'],
                'sort_income' => $input['sort_income'] ?? 'amount_desc',
                'sort_expense' => $input['sort_expense'] ?? 'amount_desc',
            ],
        ]);
    }
}
