<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\ExchangeRateImportService;
use App\Services\Finance\ExchangeRateService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ExchangeRateController extends Controller
{
    public function __construct(
        private readonly ExchangeRateService $rates,
        private readonly ExchangeRateImportService $imports,
    ) {}

    public function index(Request $request): View
    {
        $from = $request->filled('from') ? Carbon::parse($request->string('from')) : now()->subYear();
        $to = $request->filled('to') ? Carbon::parse($request->string('to')) : now();

        $rates = $this->rates->history($from, $to, 30);
        $chartPoints = $this->rates->chartPoints($from, $to);

        $latest = null;
        $sourceLabel = null;
        try {
            $info = $this->rates->latestOfficialSell(false);
            $latest = $info['rate'];
            $sourceLabel = $info['source_label'];
        } catch (\Throwable) {
        }

        return view('finance.exchange_rates.index', compact(
            'rates',
            'latest',
            'sourceLabel',
            'from',
            'to',
            'chartPoints',
        ));
    }

    public function storeManual(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rate' => ['required', 'numeric', 'gt:0'],
            'rate_buy' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->rates->storeManual(
            (string) $data['rate'],
            $data['notes'] ?? null,
            isset($data['rate_buy']) ? (string) $data['rate_buy'] : null,
        );

        return redirect()->route('exchange-rates.index')->with('status', 'Cotización manual registrada.');
    }

    public function sync(): RedirectResponse
    {
        try {
            $result = $this->rates->updateOfficialFromProvider();

            return redirect()->route('exchange-rates.index')->with('status', $result['message']);
        } catch (\Throwable $e) {
            return redirect()->route('exchange-rates.index')->withErrors(['sync' => $e->getMessage()]);
        }
    }

    public function importForm(): View
    {
        return view('finance.exchange_rates.import', [
            'preview' => session('exchange_rate_import_preview'),
        ]);
    }

    public function importPreview(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ]);

        try {
            $preview = $this->imports->parseAndPreview($request->file('file'));
            session(['exchange_rate_import_preview' => $preview]);

            return redirect()
                ->route('exchange-rates.import')
                ->with('status', "Vista previa: {$preview['rows_valid']} válidas, {$preview['rows_invalid']} rechazadas, {$preview['rows_duplicate']} duplicadas.");
        } catch (\Throwable $e) {
            return redirect()
                ->route('exchange-rates.import')
                ->withErrors(['file' => $e->getMessage()]);
        }
    }

    public function importConfirm(Request $request): RedirectResponse
    {
        $preview = session('exchange_rate_import_preview');
        if (! is_array($preview) || ($preview['rows_valid'] ?? 0) < 1) {
            return redirect()
                ->route('exchange-rates.import')
                ->withErrors(['file' => 'No hay vista previa válida. Subí un archivo primero.']);
        }

        $result = $this->imports->confirm($preview);
        session()->forget('exchange_rate_import_preview');

        return redirect()
            ->route('exchange-rates.index')
            ->with('status', "Importación histórica: {$result['imported']} altas, {$result['skipped']} omitidas.");
    }
}
