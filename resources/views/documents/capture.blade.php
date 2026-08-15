<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Capturar documento</h1>
                <p class="ar-muted text-sm">Fotografiá o subí una factura, ticket o remito. La cámara solo se usa si lo pedís.</p>
            </div>
            <a href="{{ route('documents.index') }}" class="ar-btn ar-btn-secondary">Ver documentos</a>
        </div>
    </x-slot>

    <div
        class="mx-auto max-w-xl"
        x-data="documentCaptureApp({ maxUploadKb: {{ (int) $maxUploadKb }} })"
        x-init="init()"
    >
        <div class="ar-card p-4 sm:p-6 space-y-4">
            <div class="flex flex-wrap gap-2">
                <button type="button" class="ar-btn ar-btn-primary" @click="askConsent('camera')">Tomar foto</button>
                <button type="button" class="ar-btn ar-btn-secondary" @click="askConsent('file')">Elegir archivo</button>
            </div>

            <p class="ar-muted text-xs">
                Formatos: JPEG, PNG, WEBP, PDF. Máx. {{ number_format($maxUploadKb / 1024, 1) }} MB.
                No se admiten videos ni HEIC.
            </p>

            <template x-if="clientError">
                <div class="rounded-lg px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ar-danger) 14%, transparent); color: var(--ar-danger);" x-text="clientError"></div>
            </template>

            {{-- Inputs ocultos: no se disparan solos --}}
            <input
                type="file"
                class="hidden"
                accept="image/*"
                capture="environment"
                x-ref="cameraInput"
                @change="onFilePicked($event)"
            >
            <input
                type="file"
                class="hidden"
                accept="image/jpeg,image/png,image/webp,application/pdf,.jpg,.jpeg,.png,.webp,.pdf"
                x-ref="fileInput"
                @change="onFilePicked($event)"
            >

            <div x-show="previewUrl" x-cloak class="space-y-3">
                <div class="overflow-hidden rounded-lg border" style="border-color: var(--ar-border); background: var(--ar-surface-2);">
                    <template x-if="previewKind === 'image'">
                        <img :src="previewUrl" alt="Vista previa" class="mx-auto max-h-80 w-full object-contain">
                    </template>
                    <template x-if="previewKind === 'pdf'">
                        <div class="p-6 text-center text-sm">
                            <div class="font-semibold">PDF seleccionado</div>
                            <div class="ar-muted mt-1" x-text="fileName"></div>
                        </div>
                    </template>
                </div>

                <form method="POST" action="{{ route('documents.store') }}" enctype="multipart/form-data" class="space-y-3">
                    @csrf
                    <input type="file" name="file" class="hidden" x-ref="submitFile" required>

                    <div>
                        <label class="mb-1 block text-sm font-medium">Tipo</label>
                        <select name="type" class="ar-input w-full">
                            @foreach ($types as $t)
                                <option value="{{ $t->value }}" @selected($t->value === 'otro')>{{ $t->label() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">Notas</label>
                        <input type="text" name="notes" class="ar-input w-full" maxlength="500" placeholder="Opcional">
                    </div>

                    <label class="flex items-start gap-2 text-sm">
                        <input type="checkbox" name="keep_original" value="1" class="mt-1" x-model="keepOriginal">
                        <span>
                            Conservar original de alta resolución
                            <span class="ar-muted block text-xs">Excepción: ocupa más espacio. Por defecto se prepara copia optimizada (el original se retiene en 12A hasta OCR).</span>
                        </span>
                    </label>

                    <div class="flex flex-wrap gap-2">
                        <button type="submit" class="ar-btn ar-btn-primary">Usar esta foto</button>
                        <button type="button" class="ar-btn ar-btn-secondary" @click="repeatCapture()">Repetir</button>
                        <button type="button" class="ar-btn ar-btn-secondary" @click="cancelPreview()">Cancelar</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Consentimiento humano antes del permiso del navegador --}}
        <div
            x-show="consentOpen"
            x-cloak
            class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4"
            style="background: rgb(15 23 42 / 45%);"
            @keydown.escape.window="cancelConsent()"
        >
            <div class="ar-card w-full max-w-md p-5 space-y-3" @click.stop>
                <h2 class="text-lg font-semibold">Permiso de cámara</h2>
                <p class="text-sm" style="color: var(--ar-text);">
                    AR Sistemas necesita abrir la cámara para fotografiar el documento.
                    La cámara se utiliza únicamente durante esta captura.
                </p>
                <p class="ar-muted text-xs" x-show="pendingMode === 'file'">
                    Si elegís archivo, podés seleccionar desde la galería o el explorador sin cámara.
                </p>
                <div class="flex flex-wrap gap-2 pt-1">
                    <button type="button" class="ar-btn ar-btn-primary" @click="continueAfterConsent()">Continuar</button>
                    <button type="button" class="ar-btn ar-btn-secondary" @click="cancelConsent()">Cancelar</button>
                </div>
                <p class="ar-muted text-xs">
                    Si rechazás el permiso del navegador, habilitalo en la configuración del sitio. No insistiremos en un bucle.
                </p>
            </div>
        </div>
    </div>
</x-app-layout>
