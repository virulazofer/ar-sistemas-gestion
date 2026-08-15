<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">{{ $document->code }}</h1>
                <p class="ar-muted text-sm">{{ $document->typeLabel() }} · {{ $document->statusLabel() }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('documents.index') }}" class="ar-btn ar-btn-secondary">Volver</a>
                <a href="{{ route('documents.stream', ['document' => $document, 'download' => 1]) }}" class="ar-btn ar-btn-secondary">Descargar</a>
            </div>
        </div>
    </x-slot>

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="ar-card overflow-hidden p-3">
            @if (str_starts_with((string) $document->mime, 'image/') || $document->preview_path)
                <img
                    src="{{ route('documents.stream', $document) }}"
                    alt="{{ $document->code }}"
                    class="mx-auto max-h-[70vh] w-full object-contain"
                >
            @elseif ($document->mime === 'application/pdf')
                <iframe
                    title="{{ $document->code }}"
                    src="{{ route('documents.stream', $document) }}"
                    class="h-[70vh] w-full rounded"
                ></iframe>
            @else
                <div class="ar-muted p-8 text-center">Vista previa no disponible.</div>
            @endif
        </div>

        <div class="space-y-4">
            <div class="ar-card p-4 text-sm space-y-2">
                <div><span class="ar-muted">Código:</span> <strong>{{ $document->code }}</strong></div>
                <div><span class="ar-muted">Fecha:</span> {{ $document->created_at?->format('d/m/Y H:i') }}</div>
                <div><span class="ar-muted">Usuario:</span> {{ $document->uploader?->name ?: '—' }}</div>
                <div><span class="ar-muted">Nombre original:</span> {{ $document->original_name }}</div>
                <div><span class="ar-muted">MIME:</span> {{ $document->mime }}</div>
                <div><span class="ar-muted">Tamaño:</span> {{ number_format(($document->size ?? 0) / 1024, 1) }} KB</div>
                @if ($document->optimized_size)
                    <div><span class="ar-muted">Optimizado:</span> {{ number_format($document->optimized_size / 1024, 1) }} KB</div>
                @endif
                <div><span class="ar-muted">Optimización:</span> {{ $document->optimization_status?->label() ?? '—' }}</div>
                <div><span class="ar-muted">Asociado a:</span> {{ $document->associatedLabel() }}</div>
                <div><span class="ar-muted">Hash:</span> <span class="break-all font-mono text-xs">{{ $document->content_hash }}</span></div>
            </div>

            @can('documents.edit')
                <form method="POST" action="{{ route('documents.update', $document) }}" class="ar-card p-4 space-y-3">
                    @csrf
                    @method('PUT')
                    <div>
                        <label class="mb-1 block text-sm font-medium">Tipo</label>
                        <select name="type" class="ar-input w-full">
                            @foreach ($types as $t)
                                <option value="{{ $t->value }}" @selected($document->type?->value === $t->value)>{{ $t->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Estado</label>
                        <select name="status" class="ar-input w-full">
                            @foreach ($statuses as $s)
                                <option value="{{ $s->value }}" @selected($document->status?->value === $s->value)>{{ $s->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Notas</label>
                        <textarea name="notes" class="ar-input w-full" rows="3" maxlength="500">{{ old('notes', $document->notes) }}</textarea>
                    </div>
                    <label class="flex items-start gap-2 text-sm">
                        <input type="hidden" name="keep_original" value="0">
                        <input type="checkbox" name="keep_original" value="1" class="mt-1" @checked($document->keep_original)>
                        <span>Conservar original</span>
                    </label>
                    <button class="ar-btn ar-btn-primary">Guardar</button>
                </form>
            @endcan

            @can('documents.delete')
                <form method="POST" action="{{ route('documents.destroy', $document) }}" class="ar-card p-4 space-y-2" onsubmit="return confirm('¿Descartar este documento?');">
                    @csrf
                    @method('DELETE')
                    <button class="ar-btn ar-btn-secondary">Descartar (soft delete)</button>
                </form>
                @role('Administrador')
                    <form method="POST" action="{{ route('documents.destroy', $document) }}" class="ar-card p-4" onsubmit="return confirm('¿Eliminar definitivamente archivos y metadata?');">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="hard" value="1">
                        <button class="ar-btn ar-btn-secondary" style="color: var(--ar-danger);">Eliminar definitivamente</button>
                    </form>
                @endrole
            @endcan
        </div>
    </div>
</x-app-layout>
