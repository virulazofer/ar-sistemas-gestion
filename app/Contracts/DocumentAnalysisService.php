<?php

namespace App\Contracts;

use App\Models\Document;

/**
 * Contrato futuro para OCR/visión (12B+).
 * 12A: stub sin proveedores externos. No enviar documentos fuera del servidor.
 */
interface DocumentAnalysisService
{
    /**
     * Analiza un documento capturado.
     * En 12A no se ejecuta; retorna estructura vacía/preparada.
     *
     * @return array{
     *     status: string,
     *     provider: string|null,
     *     extracted: array<string, mixed>|null,
     *     qr_payload: string|null,
     *     barcode_payload: string|null,
     *     message: string|null
     * }
     */
    public function analyze(Document $document): array;
}
