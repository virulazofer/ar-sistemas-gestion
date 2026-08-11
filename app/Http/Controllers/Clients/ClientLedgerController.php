<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientLedgerEntry;
use App\Models\FinancialAccount;
use App\Services\Clients\ClientLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientLedgerController extends Controller
{
    public function __construct(private readonly ClientLedgerService $ledger) {}

    public function createCharge(Client $client): View
    {
        return view('clients.ledger.charge', compact('client'));
    }

    public function storeCharge(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'currency_code' => ['required', Rule::in(['ARS', 'USD'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'entry_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->ledger->registerCharge($client, $data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('clients.show', $client)->with('status', 'Cargo registrado.');
    }

    public function createPayment(Client $client): View
    {
        $accounts = FinancialAccount::query()->with('currency')->active()->orderBy('name')->get();

        return view('clients.ledger.payment', compact('client', 'accounts'));
    }

    public function storePayment(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'financial_account_id' => ['required', 'exists:financial_accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'entry_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->ledger->registerPayment($client, $data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('clients.show', $client)->with('status', 'Pago registrado (CC + finanzas).');
    }

    public function createCredit(Client $client): View
    {
        return view('clients.ledger.credit', compact('client'));
    }

    public function storeCredit(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'currency_code' => ['required', Rule::in(['ARS', 'USD'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'entry_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->ledger->registerCredit($client, $data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('clients.show', $client)->with('status', 'Crédito a favor registrado.');
    }

    public function createAdjustment(Client $client): View
    {
        return view('clients.ledger.adjustment', compact('client'));
    }

    public function storeAdjustment(Request $request, Client $client): RedirectResponse
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
            $this->ledger->registerAdjustment($client, $data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('clients.show', $client)->with('status', 'Ajuste registrado.');
    }

    public function createOpening(Client $client): View
    {
        return view('clients.ledger.opening', compact('client'));
    }

    public function storeOpening(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'currency_code' => ['required', Rule::in(['ARS', 'USD'])],
            'balance' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['required', 'string', 'max:1000'],
            'entry_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'set_control_desde' => ['nullable', 'boolean'],
            'control_cc_desde' => ['nullable', 'date'],
        ]);

        $data['set_control_desde'] = $request->boolean('set_control_desde');

        try {
            $this->ledger->registerOpeningBalance($client, $data);
            if ($request->filled('control_cc_desde') && ! $data['set_control_desde']) {
                $client->update(['control_cc_desde' => $data['control_cc_desde']]);
            }
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['balance' => $e->getMessage()]);
        }

        return redirect()->route('clients.show', $client)->with('status', 'Apertura de CC registrada (AJUSTE/APERTURA).');
    }

    public function applyCredit(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'currency_code' => ['required', Rule::in(['ARS', 'USD'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'entry_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->ledger->applyCredit($client, $data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('clients.show', $client)->with('status', 'Crédito aplicado.');
    }

    public function void(Request $request, Client $client, ClientLedgerEntry $entry): RedirectResponse
    {
        if ($entry->client_id !== $client->id) {
            abort(404);
        }

        $data = $request->validate([
            'void_reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->ledger->void($entry, $data['void_reason']);
        } catch (\Throwable $e) {
            return back()->withErrors(['void_reason' => $e->getMessage()]);
        }

        return redirect()->route('clients.show', $client)->with('status', 'Movimiento de CC anulado.');
    }
}
