<?php

namespace App\Services\Documents;

use App\Contracts\DocumentAnalysisService;
use App\Models\Document;

/**
 * Stub 12A: no OCR, no APIs externas, no envío off-server.
 */
class NullDocumentAnalysisService implements DocumentAnalysisService
{
    public function analyze(Document $document): array
    {
        return [
            'status' => 'not_implemented',
            'provider' => null,
            'extracted' => null,
            'qr_payload' => null,
            'barcode_payload' => null,
            'message' => 'Análisis documental (OCR/visión) no implementado en 12A. Disponible desde 12B.',
        ];
    }
}
