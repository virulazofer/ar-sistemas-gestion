<?php

namespace App\Http\Controllers\Imports;

use App\Http\Controllers\Controller;
use App\Models\ImportBatch;
use App\Services\Imports\Historical\AccountMappingService;
use App\Services\Imports\Historical\HistoricalMappingRuleService;
use App\Services\Imports\Historical\HistoricalMovementsPreviewService;
use App\Services\Imports\Historical\HistoricalResolutionService;
use App\Services\Imports\Historical\SupplierCatalogPreviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HistoricalImportController extends Controller
{
    public function __construct(
        private readonly SupplierCatalogPreviewService $catalog,
        private readonly HistoricalMovementsPreviewService $movements,
        private readonly HistoricalResolutionService $resolution,
    ) {}

    public function create(): View
    {
        return view('imports.historical-create', [
            'cutover' => config('historical_import.cutover_date'),
            'periodFrom' => config('historical_import.period_from'),
            'periodTo' => config('historical_import.period_to'),
        ]);
    }

    public function storeCatalog(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:xlsx,xls'],
            'list_date' => ['nullable', 'date'],
        ]);

        try {
            $batch = $this->catalog->analyzeUpload(
                $data['file'],
                $request->user()->id,
                $data['list_date'] ?? null
            );
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('imports.show', $batch)
            ->with('status', 'Preview de catálogo listo. Stock permanecerá en 0. Confirmación de catálogo es opcional y separada del histórico.');
    }

    public function storeMovements(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:40960', 'mimes:xlsx,xls'],
            'cutover_date' => ['nullable', 'date'],
        ]);

        try {
            $batch = $this->movements->analyzeUpload(
                $data['file'],
                $request->user()->id,
                $data['cutover_date'] ?? null
            );
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('imports.show', $batch)
            ->with('status', 'Preview histórico listo. La confirmación definitiva está bloqueada en esta etapa.');
    }

    public function resolve(ImportBatch $import): View|RedirectResponse
    {
        if ($import->importer_kind !== 'historical_movements') {
            return redirect()->route('imports.show', $import)
                ->withErrors(['resolve' => 'Solo aplica a preview de movimientos históricos.']);
        }

        try {
            $rows = $this->resolution->loadAllRows($import);
        } catch (\Throwable $e) {
            return redirect()->route('imports.show', $import)
                ->withErrors(['resolve' => $e->getMessage()]);
        }

        $queues = $this->resolution->reviewQueues($rows, $import);
        $evolution = $import->options['evolution'] ?? null;
        $unknown = $import->preview_payload['masters']['unknown_accounts'] ?? [];

        return view('imports.historical-resolve', [
            'batch' => $import,
            'queues' => $queues,
            'evolution' => $evolution,
            'unknownAccounts' => $unknown,
            'confirmBlocked' => true,
        ]);
    }

    public function confirmCatalog(ImportBatch $import): RedirectResponse
    {
        if ($import->importer_kind !== 'supplier_catalog') {
            return back()->withErrors(['confirm' => 'Este lote no es un catálogo proveedor.']);
        }

        try {
            $this->catalog->confirm($import);
        } catch (\Throwable $e) {
            return back()->withErrors(['confirm' => $e->getMessage()]);
        }

        return back()->with('status', 'Catálogo confirmado (productos con stock 0).');
    }

    public function confirmMovements(ImportBatch $import): RedirectResponse
    {
        try {
            $this->movements->confirm($import);
        } catch (\Throwable $e) {
            return back()->withErrors(['confirm' => $e->getMessage()]);
        }

        return back();
    }

    public function reprocess(ImportBatch $import): RedirectResponse
    {
        try {
            $this->resolution->reprocessWithEvolution($import);
        } catch (\Throwable $e) {
            return back()->withErrors(['reprocess' => $e->getMessage()]);
        }

        return back()->with('status', 'Preview reprocesado con reglas/decisiones (sin importar movimientos).');
    }

    public function approveAccountRule(Request $request, ImportBatch $import): RedirectResponse
    {
        $data = $request->validate([
            'excel_alias' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:cash,bank,wallet,credit_card,other'],
            'currency' => ['required', 'in:ARS,USD'],
            'holder' => ['required', 'in:fernando,gabi'],
            'liability' => ['nullable', 'boolean'],
        ]);

        app(HistoricalMappingRuleService::class)->approveAccountAlias(
            $data['excel_alias'],
            [
                'name' => $data['name'],
                'type' => $data['type'],
                'currency' => $data['currency'],
                'holder' => $data['holder'],
                'alias' => $data['excel_alias'],
                'liability' => (bool) ($data['liability'] ?? ($data['type'] === 'credit_card')),
            ]
        );

        app(AccountMappingService::class)->ensurePreviewMasters();
        try {
            $this->resolution->reprocessWithEvolution($import);
        } catch (\Throwable $e) {
            return back()->withErrors(['reprocess' => 'Regla guardada pero falló el reproceso: '.$e->getMessage()]);
        }

        return redirect()->route('imports.historical.resolve', $import)
            ->with('status', "Regla {$data['excel_alias']} aprobada y aplicada a filas compatibles.");
    }

    public function decideDates(Request $request, ImportBatch $import): RedirectResponse
    {
        $data = $request->validate([
            'decisions' => ['required', 'array'],
            'decisions.*.source_row' => ['required', 'integer'],
            'decisions.*.action' => ['required', 'in:accept,correct,exclude,skip'],
            'decisions.*.corrected_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        foreach ($data['decisions'] as $row) {
            if (($row['action'] ?? 'skip') === 'skip') {
                continue;
            }
            $this->resolution->decideDate(
                $import,
                (int) $row['source_row'],
                $row['action'],
                $row['corrected_date'] ?? null
            );
        }

        $this->resolution->reprocessWithEvolution($import);

        return redirect()->route('imports.historical.resolve', $import)
            ->with('status', 'Decisiones de fecha aplicadas y preview reprocesado.');
    }

    public function decideComplex(Request $request, ImportBatch $import): RedirectResponse
    {
        $data = $request->validate([
            'source_row' => ['required', 'integer'],
            'venta' => ['nullable', 'numeric'],
            'cobro' => ['nullable', 'numeric'],
            'cc_charge' => ['nullable', 'numeric'],
            'cc_payment' => ['nullable', 'numeric'],
            'merca_out' => ['nullable', 'numeric'],
            'merca_in' => ['nullable', 'numeric'],
            'utilidad' => ['nullable', 'numeric'],
            'finance_income' => ['nullable', 'numeric'],
            'finance_expense' => ['nullable', 'numeric'],
            'client' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->resolution->decideComplexSale($import, (int) $data['source_row'], $data);
        $this->resolution->reprocessWithEvolution($import);

        return redirect()->route('imports.historical.resolve', $import)
            ->with('status', 'Venta compleja fila '.$data['source_row'].' resuelta en preview (sin importar).');
    }

    public function decideCards(Request $request, ImportBatch $import): RedirectResponse
    {
        $data = $request->validate([
            'decisions' => ['required', 'array'],
            'decisions.*.source_row' => ['required', 'integer'],
            'decisions.*.kind' => ['required', 'in:purchase,statement_payment,skip'],
        ]);

        foreach ($data['decisions'] as $row) {
            if (($row['kind'] ?? 'skip') === 'skip') {
                continue;
            }
            $this->resolution->decideCard($import, (int) $row['source_row'], $row['kind']);
        }

        $this->resolution->reprocessWithEvolution($import);

        return redirect()->route('imports.historical.resolve', $import)
            ->with('status', 'Decisiones de tarjeta aplicadas (preview).');
    }

    public function decideScope(Request $request, ImportBatch $import): RedirectResponse
    {
        $data = $request->validate([
            'source_rows' => ['required', 'array', 'min:1'],
            'source_rows.*' => ['integer'],
            'scope' => ['required', 'in:personal,professional,pending'],
            'save_rule' => ['nullable', 'boolean'],
            'rule_match' => ['nullable', 'string', 'max:120'],
        ]);

        $this->resolution->decideScopeBulk(
            $import,
            array_map('intval', $data['source_rows']),
            $data['scope'],
            (bool) ($data['save_rule'] ?? false),
            $data['rule_match'] ?? null
        );
        $this->resolution->reprocessWithEvolution($import);

        return redirect()->route('imports.historical.resolve', $import)
            ->with('status', 'Ámbito actualizado en '.count($data['source_rows']).' filas (preview).');
    }
}
