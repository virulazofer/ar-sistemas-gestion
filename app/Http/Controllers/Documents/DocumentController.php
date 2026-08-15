<?php

namespace App\Http\Controllers\Documents;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\AuditLogger;
use App\Services\Documents\DocumentCaptureService;
use App\Services\Documents\DocumentStorageMetricsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function index(Request $request, DocumentStorageMetricsService $metrics): View
    {
        Gate::authorize('viewAny', Document::class);

        $q = trim((string) $request->get('q', ''));
        $type = $request->get('type');
        $status = $request->get('status');

        $documents = Document::query()
            ->whereNotNull('code')
            ->with('uploader')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('code', 'like', '%'.$q.'%')
                        ->orWhere('original_name', 'like', '%'.$q.'%')
                        ->orWhere('notes', 'like', '%'.$q.'%');
                });
            })
            ->when($type, fn ($query) => $query->where('type', $type))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('documents.index', [
            'documents' => $documents,
            'q' => $q,
            'type' => $type,
            'status' => $status,
            'types' => DocumentType::cases(),
            'statuses' => DocumentStatus::cases(),
            'metrics' => auth()->user()?->hasRole('Administrador') ? $metrics->snapshot() : null,
        ]);
    }

    public function captureForm(): View
    {
        Gate::authorize('create', Document::class);

        return view('documents.capture', [
            'types' => array_values(array_filter(
                DocumentType::cases(),
                fn (DocumentType $t) => $t !== DocumentType::Adjunto
            )),
            'maxUploadKb' => (int) config('documents.max_upload_kb', 10240),
            'allowedMimes' => config('documents.allowed_mimes'),
        ]);
    }

    public function store(Request $request, DocumentCaptureService $capture): RedirectResponse
    {
        Gate::authorize('create', Document::class);

        $maxKb = (int) config('documents.max_upload_kb', 10240);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.$maxKb],
            'type' => ['nullable', Rule::enum(DocumentType::class)],
            'notes' => ['nullable', 'string', 'max:500'],
            'keep_original' => ['sometimes', 'boolean'],
        ], [
            'file.required' => 'Seleccioná o capturá un documento.',
            'file.max' => 'El archivo supera el tamaño permitido.',
        ]);

        $type = isset($data['type'])
            ? DocumentType::from($data['type'] instanceof DocumentType ? $data['type']->value : (string) $data['type'])
            : DocumentType::Otro;

        $result = $capture->capture(
            $request->file('file'),
            (int) $request->user()->id,
            $type,
            $data['notes'] ?? null,
            (bool) ($data['keep_original'] ?? false),
        );

        $doc = $result['document'];
        $message = 'Documento guardado correctamente.';
        if ($result['duplicate_warning']) {
            $message .= ' Este documento parece haber sido cargado anteriormente'
                .($result['duplicate_of'] ? " ({$result['duplicate_of']})." : '.');
        }

        return redirect()
            ->route('documents.show', $doc)
            ->with('status', $message);
    }

    public function show(Document $document): View
    {
        Gate::authorize('view', $document);
        $document->load('uploader', 'documentable');

        return view('documents.show', [
            'document' => $document,
            'types' => DocumentType::cases(),
            'statuses' => DocumentStatus::cases(),
        ]);
    }

    public function update(Request $request, Document $document, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('update', $document);

        $data = $request->validate([
            'type' => ['required', Rule::enum(DocumentType::class)],
            'status' => ['required', Rule::enum(DocumentStatus::class)],
            'notes' => ['nullable', 'string', 'max:500'],
            'keep_original' => ['sometimes', 'boolean'],
        ]);

        $old = [
            'type' => $document->type?->value,
            'status' => $document->status?->value,
            'notes' => $document->notes,
            'keep_original' => (bool) $document->keep_original,
        ];

        $document->update([
            'type' => $data['type'],
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
            'keep_original' => (bool) ($data['keep_original'] ?? false),
        ]);

        $audit->log('document_metadata_updated', $document, $old, [
            'code' => $document->code,
            'type' => $document->type?->value,
            'status' => $document->status?->value,
            'keep_original' => (bool) $document->keep_original,
        ], 'Metadata de documento actualizada');

        return back()->with('status', 'Documento actualizado.');
    }

    public function stream(Document $document, Request $request): StreamedResponse
    {
        Gate::authorize('download', $document);

        $variant = $request->get('v', 'file');
        $disk = Storage::disk($document->disk ?: 'local');

        $path = match ($variant) {
            'preview' => $document->preview_path ?: $document->servingPath(),
            'original' => $document->original_path ?: $document->path,
            default => $document->servingPath(),
        };

        abort_unless($path && $disk->exists($path), 404);

        $mime = $variant === 'preview' ? 'image/jpeg' : ($document->mime ?: 'application/octet-stream');
        $filename = $document->original_name ?: ($document->code.'.bin');

        return $disk->response($path, $filename, [
            'Content-Type' => $mime,
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline')
                .'; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(Request $request, Document $document, DocumentCaptureService $capture): RedirectResponse
    {
        Gate::authorize('delete', $document);

        $hard = $request->boolean('hard') && Gate::allows('forceDelete', $document);

        if ($hard) {
            $capture->forceDelete($document, (int) $request->user()->id);
            $msg = 'Documento eliminado definitivamente.';
        } else {
            $capture->softDelete($document, (int) $request->user()->id);
            $msg = 'Documento descartado.';
        }

        return redirect()->route('documents.index')->with('status', $msg);
    }
}
