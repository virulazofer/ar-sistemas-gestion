<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Documentos</h1>
                <p class="ar-muted text-sm">Capturas privadas de facturas, tickets y remitos.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @can('documents.create')
                    <a href="{{ route('documents.capture') }}" class="ar-btn ar-btn-primary">Capturar documento</a>
                @endcan
            </div>
        </div>
    </x-slot>

    @if ($metrics)
        <div class="mb-4 ar-card p-4 text-sm">
            <div class="font-semibold mb-1">Espacio de documentos</div>
            <div class="ar-muted">
                {{ $metrics['documents_count'] }} docs ·
                {{ number_format($metrics['total_bytes'] / 1024, 1) }} KB total ·
                avg {{ number_format($metrics['average_bytes'] / 1024, 1) }} KB ·
                uso {{ $metrics['used_percent'] }}%
                @if ($metrics['level'] === 'warning')
                    <span style="color: var(--ar-warning);">· Advertencia umbral</span>
                @elseif ($metrics['level'] === 'critical')
                    <span style="color: var(--ar-danger);">· Alerta crítica</span>
                @endif
            </div>
        </div>
    @endif

    <form method="GET" class="mb-4 flex flex-wrap gap-2">
        <input type="search" name="q" value="{{ $q }}" class="ar-input" placeholder="DOC-…, nombre, notas">
        <select name="type" class="ar-input">
            <option value="">Tipo</option>
            @foreach ($types as $t)
                <option value="{{ $t->value }}" @selected($type === $t->value)>{{ $t->label() }}</option>
            @endforeach
        </select>
        <select name="status" class="ar-input">
            <option value="">Estado</option>
            @foreach ($statuses as $s)
                <option value="{{ $s->value }}" @selected($status === $s->value)>{{ $s->label() }}</option>
            @endforeach
        </select>
        <button class="ar-btn ar-btn-secondary">Filtrar</button>
    </form>

    <div class="ar-card overflow-x-auto">
        <table class="ar-table">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Fecha</th>
                    <th>Tipo</th>
                    <th>Vista previa</th>
                    <th>Estado</th>
                    <th>Asociado a</th>
                    <th>Usuario</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($documents as $doc)
                    <tr>
                        <td>
                            <a href="{{ route('documents.show', $doc) }}" class="font-medium" style="color: var(--ar-brand);">{{ $doc->code }}</a>
                        </td>
                        <td>{{ $doc->created_at?->format('d/m/Y H:i') }}</td>
                        <td>{{ $doc->typeLabel() }}</td>
                        <td>
                            @if ($doc->preview_path || str_starts_with((string) $doc->mime, 'image/'))
                                <a href="{{ route('documents.show', $doc) }}">
                                    <img
                                        src="{{ route('documents.stream', ['document' => $doc, 'v' => 'preview']) }}"
                                        alt="Preview {{ $doc->code }}"
                                        class="h-12 w-12 rounded object-cover"
                                        loading="lazy"
                                    >
                                </a>
                            @else
                                <span class="ar-muted text-xs">{{ strtoupper(pathinfo($doc->original_name, PATHINFO_EXTENSION) ?: 'DOC') }}</span>
                            @endif
                        </td>
                        <td>{{ $doc->statusLabel() }}</td>
                        <td>{{ $doc->associatedLabel() }}</td>
                        <td>{{ $doc->uploader?->name ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="ar-muted py-6 text-center">Sin documentos capturados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $documents->links() }}</div>
</x-app-layout>
