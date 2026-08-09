<?php

namespace App\Http\Controllers\Imports;

use App\Http\Controllers\Controller;
use App\Models\ImportBatch;
use App\Services\Imports\ImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ImportController extends Controller
{
    public function __construct(private readonly ImportService $imports) {}

    public function index(): View
    {
        $batches = ImportBatch::query()->latest('id')->paginate(20);

        return view('imports.index', [
            'batches' => $batches,
            'entityTypes' => ImportService::ENTITY_TYPES,
        ]);
    }

    public function create(): View
    {
        return view('imports.create', [
            'entityTypes' => ImportService::ENTITY_TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'entity_type' => ['required', 'in:'.implode(',', ImportService::ENTITY_TYPES)],
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx,xls'],
        ]);

        try {
            $batch = $this->imports->parseAndPreview(
                $data['entity_type'],
                $data['file'],
                $request->user()->id
            );
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('imports.show', $batch)->with('status', 'Vista previa lista. Revisá y confirmá.');
    }

    public function show(ImportBatch $import): View
    {
        return view('imports.show', ['batch' => $import]);
    }

    public function confirm(ImportBatch $import): RedirectResponse
    {
        try {
            $this->imports->confirm($import);
        } catch (\Throwable $e) {
            return back()->withErrors(['confirm' => $e->getMessage()]);
        }

        return back()->with('status', 'Importación confirmada.');
    }

    public function rollback(Request $request, ImportBatch $import): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->imports->rollback($import, $data['reason']);
        } catch (\Throwable $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Importación revertida.');
    }
}
