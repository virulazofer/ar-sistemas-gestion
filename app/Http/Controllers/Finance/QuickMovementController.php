<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Services\Finance\ExchangeRateService;
use App\Services\Finance\MovementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class QuickMovementController extends Controller
{
    public function __construct(
        private readonly MovementService $movements,
        private readonly ExchangeRateService $rates,
    ) {}

    public function create(): View
    {
        $accounts = FinancialAccount::query()->with('currency')->active()->orderBy('name')->get();
        $categories = Category::query()->with(['subcategories' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->active()
            ->orderBy('sort_order')
            ->get();

        $rateInfo = null;
        try {
            $rateInfo = $this->rates->latestOfficialSell(trySync: false);
        } catch (\Throwable) {
            // UI muestra aviso
        }

        return view('finance.quick', compact('accounts', 'categories', 'rateInfo'));
    }

    public function store(Request $request): RedirectResponse
    {
        $type = $request->input('type');

        if ($type === 'transfer') {
            $data = $request->validate([
                'scope' => ['required', Rule::in(['personal', 'professional'])],
                'from_account_id' => ['required', 'exists:financial_accounts,id'],
                'to_account_id' => ['required', 'exists:financial_accounts,id', 'different:from_account_id'],
                'amount' => ['required', 'numeric', 'gt:0'],
                'movement_date' => ['required', 'date'],
                'description' => ['nullable', 'string', 'max:255'],
            ]);

            try {
                $this->movements->createTransfer([
                    'from_account_id' => (int) $data['from_account_id'],
                    'to_account_id' => (int) $data['to_account_id'],
                    'amount' => $data['amount'],
                    'scope' => $data['scope'],
                    'movement_date' => $data['movement_date'],
                    'description' => $data['description'] ?? 'Transferencia',
                ]);
            } catch (\Throwable $e) {
                return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
            }

            return redirect()->route('movements.quick')->with('status', 'Transferencia registrada.');
        }

        $data = $request->validate([
            'type' => ['required', Rule::in(['income', 'expense'])],
            'scope' => ['required', Rule::in(['personal', 'professional'])],
            'financial_account_id' => ['required', 'exists:financial_accounts,id'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'subcategory_id' => ['nullable', 'exists:subcategories,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'movement_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->movements->createSimple($data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('movements.quick')->with('status', 'Movimiento registrado.');
    }
}
