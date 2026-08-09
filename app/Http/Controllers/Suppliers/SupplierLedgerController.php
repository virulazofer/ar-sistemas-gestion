<?php

namespace App\Http\Controllers\Suppliers;

use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Services\Suppliers\SupplierLedgerService;
use App\Services\Suppliers\SupplierPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupplierLedgerController extends Controller
{
    public function __construct(
        private readonly SupplierLedgerService $ledger,
        private readonly SupplierPaymentService $payments,
    ) {}

    public function createPayment(Supplier $supplier): View
    {
        $accounts = FinancialAccount::query()->with('currency')->active()->orderBy('name')->get();

        return view('suppliers.ledger.payment', compact('supplier', 'accounts'));
    }

    public function storePayment(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $request->validate([
            'financial_account_id' => ['required', 'exists:financial_accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'entry_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'purchase_id' => ['nullable', 'exists:purchases,id'],
        ]);

        try {
            $this->payments->pay($supplier, $data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('suppliers.show', $supplier)->with('status', 'Pago a proveedor registrado.');
    }

    public function createAdjustment(Supplier $supplier): View
    {
        return view('suppliers.ledger.adjustment', compact('supplier'));
    }

    public function storeAdjustment(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $request->validate([
            'currency_code' => ['required', Rule::in(['ARS', 'USD'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'sign' => ['required', Rule::in(['-1', '1'])],
            'reason' => ['required', 'string', 'max:1000'],
            'entry_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $data['sign'] = (int) $data['sign'];

        try {
            $this->ledger->registerAdjustment($supplier, $data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('suppliers.show', $supplier)->with('status', 'Ajuste registrado.');
    }

    public function void(Request $request, Supplier $supplier, SupplierLedgerEntry $entry): RedirectResponse
    {
        if ($entry->supplier_id !== $supplier->id) {
            abort(404);
        }
        $data = $request->validate(['void_reason' => ['required', 'string', 'max:500']]);
        try {
            $this->ledger->void($entry, $data['void_reason']);
        } catch (\Throwable $e) {
            return back()->withErrors(['void_reason' => $e->getMessage()]);
        }

        return redirect()->route('suppliers.show', $supplier)->with('status', 'Movimiento anulado.');
    }
}
