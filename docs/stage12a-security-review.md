# Security review — 12A PWA + captura documental

## A. Qué se cachea

- Precache: `/offline.html`, `/manifest.webmanifest`, iconos.
- Runtime cache (GET): `/build/*` (CSS/JS Vite), `/icons/*`, assets estáticos por extensión.

## B. Qué NO se cachea

- Cualquier método distinto de GET (POST/PUT/DELETE…).
- Rutas sensibles: login/logout, documentos, movimientos, clientes, finanzas, CC, stock, OT, reportes, imports, users, settings, audit, dashboard, buscar, profile, APIs.
- Respuestas autenticadas HTML de esas rutas (network-only; navegación falla → offline.html sin guardar la página).

## C. Dónde se guardan documentos

`storage/app/private/documents/...` (disk `local`). Nunca en `public/`.

## D. Autorización

Middleware Spatie `documents.*` + `DocumentPolicy`. Stream solo con sesión + `documents.view`.

## E. Cámara

Consentimiento en UI → recién entonces `input[capture]` / (futuro) `getUserMedia`. No en open/login/dashboard/install.

## F. Stream

`stopCamera()` detiene `MediaStream.getTracks()`. Se invoca en cancel/unload; scanner continuo no implementado.

## G. Headers

`SecurityHeaders`: CSP, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy, COOP, HSTS si HTTPS.

## H. CSP

Restricción principal: `style-src`/`script-src` incluyen `'unsafe-inline'` por Blade Alpine + estilos inline de tema. Sin `unsafe-eval`. `object-src 'none'`. Limitación real del stack Blade+Alpine actual.

## I. Logs

No binarios/base64. Auditoría: code, uuid, mime, sizes, status, acciones.

## J. Riesgos residuales

- `unsafe-inline` en CSP.
- Preview/stream requieren cookie de sesión (esperado).
- HEIC no convertido (rechazo explícito).
- Optimización JPEG vía GD: si falla, se conserva original.
- Cuota de storage es métrica/alerta, no bloqueo duro de upload en 12A.
