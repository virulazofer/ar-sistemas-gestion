<?php

namespace App\Http\Controllers\Imports;

use App\Http\Controllers\Controller;
use App\Models\ImportBatch;
use App\Services\Imports\Historical\HistoricalMovementsPreviewService;
use App\Services\Imports\Historical\SupplierCatalogPreviewService;
use App\Services\Imports\ImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HistoricalImportController extends Controller
{
    public function __construct(
        private readonly SupplierCatalogPreviewService $catalog,
        private readonly HistoricalMovementsPreviewService $movements,
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
            $this->movements->reprocess($import);
        } catch (\Throwable $e) {
            return back()->withErrors(['reprocess' => $e->getMessage()]);
        }

        return back()->with('status', 'Preview reprocesado con reglas activas (sin importar movimientos).');
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

        app(\App\Services\Imports\Historical\HistoricalMappingRuleService::class)->approveAccountAlias(
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

        // Ensure masters exist then reprocess
        app(\App\Services\Imports\Historical\AccountMappingService::class)->ensurePreviewMasters();
        try {
            $this->movements->reprocess($import);
        } catch (\Throwable $e) {
            return back()->withErrors(['reprocess' => 'Regla guardada pero falló el reproceso: '.$e->getMessage()]);
        }

        return back()->with('status', "Regla {$data['excel_alias']} aprobada y aplicada a filas compatibles.");
    }
}
