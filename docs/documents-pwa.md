# Documentos — Etapa 12A (PWA + captura móvil segura)

## Alcance 12A

- PWA instalable (manifest + service worker conservador).
- Captura puntual de documentos (foto / archivo) con preview y consentimiento de cámara.
- Almacenamiento **privado** (`storage/app/private/documents/...`).
- Stream autenticado/autorizado: `GET /documentos/{uuid}/archivo`.
- Código inmutable `DOC-YYYY-NNNNNN`, hash SHA-256, aviso de duplicado (no bloqueo ciego).
- Soft delete + hard delete (Admin) sin archivos huérfanos.
- **Sin OCR / sin APIs externas / sin QR-barcode scanner completo.**

## Storage

| Ruta relativa (disk `local`) | Uso |
|------------------------------|-----|
| `documents/YYYY/MM/{uuid}.ext` | Original / retenido |
| `documents/optimized/{uuid}.jpg` | Copia optimizada |
| `documents/previews/{uuid}_preview.jpg` | Thumbnail |
| `documents/temp/` | Staging + huérfanos; limpia `documents:cleanup-temp` |

Disk `local` → `storage/app/private` (no `public/`).

## Retención 34B (preparada)

En 12A **no** se elimina el original tras el upload (sigue siendo necesario para OCR futuro).

Flujo preparado para 12B:

`ORIGINAL → optimizado → validación legibilidad → (opcional) eliminar original`

Excepción: `keep_original=true` conserva el original a propósito.

Si falla la optimización: `optimization_status=failed` (“Pendiente de optimización”) y se conserva el original.

Objetivo orientativo de copia optimizada: 200–500 KB (legibilidad > megapíxeles).

## Datos extraíbles futuros (diseño, no columnas)

Preferir JSON validado / tabla normalizada futura (`document_extractions`) en lugar de columnas fijas en `documents`:

Proveedor, CUIT, fecha, tipo comprobante, PV, número, moneda, neto, IVA, percepciones, total, CAE, vencimiento CAE, ítems.

QR fiscal futuro: `qr_payload`, parseo, validación, fuente (nullable).

Barcode futuro: EAN/UPC/Code128 vía stream en memoria (nunca video persistido).

## Interfaz OCR stub

`App\Contracts\DocumentAnalysisService` + `NullDocumentAnalysisService`.

## Permisos

Área `documents`: view / create / edit / delete.

- Administrador: todo
- Operador: capturar + consultar + editar/eliminar según matriz
- Consulta: solo `documents.view`

## Comandos

```bash
php artisan documents:cleanup-temp --dry-run
php artisan documents:cleanup-temp
```

Programado diario en `routes/console.php`.

## Seguridad

- Cámara solo tras acción + consentimiento UX; `stopCamera()` helper.
- SW: cache estático; nunca auth/finanzas/docs/POST.
- Headers: CSP, Permissions-Policy `camera=(self)`, nosniff, frame, Referrer, HSTS en HTTPS.
- Logs/auditoría: códigos, tamaños, estados — nunca binarios/base64.
