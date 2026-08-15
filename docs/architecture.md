# Arquitectura — Etapa 1

## Resumen

Monolito modular Laravel 13, single-tenant, frontend Blade + Alpine.js + CSS con variables de tema.

## Branding

Nombre visible: **AR Sistemas - Gestión**

## Núcleo implementado

### Etapa 1
- Autenticación, usuarios, Spatie, temas, settings, auditoría, layout

### Etapa 3
- Clientes, CC multimoneda ARS/USD, cargos/pagos/créditos/ajustes
- Pago atómico CC ↔ finanzas (`ClientLedgerService`)
- Documentos básicos polimórficos
- Ver `docs/clients.md`

### Etapa 6
- Tipos de equipo configurables + plantillas de componentes
- Armado atómico con consumo FIFO y seriales
- Costo consolidado histórico; desarmado y reemplazo
- Ver `docs/equipment.md`

### Etapa 7
- Órdenes de trabajo (OT): tareas, materiales, diagnóstico, cierre atómico
- Consumo FIFO + cargo CC sin movimiento bancario
- Abonos recurrentes con generación idempotente (`period_key`)
- Comando `php artisan subscriptions:generate`
- Ver `docs/work-orders.md`

### Etapa 8
- Presupuestos sin efecto en stock/CC/finanzas
- Ventas (contado/crédito) con confirmación atómica FIFO + CC
- Conversión presupuesto → venta borrador
- Venta de equipos armados sin re-consumir componentes
- Ver `docs/sales.md`

### Etapa 9
- Dashboard operativo (home = carga rápida)
- Reportes exportables + rentabilidad básica
- Importaciones CSV/XLSX con preview/rollback
- Búsqueda global y menú reorganizado
- Ver `docs/reports-imports.md`

## Decisiones confirmadas relevantes

| Tema | Decisión |
|------|----------|
| Permisos | Spatie |
| Transferencias (Etapa 2) | Dos movimientos vinculados por `transfer_id` |
| Cotización | Venta dólar oficial DolarAPI, congelada |
| Stock | Denormalizado + movimientos + servicio transaccional |
| Valuación | FIFO con lotes |
| Equipos | Consumo real de inventario; costo = suma FIFO de componentes |
| OT | Unidad operativa; cierre atómico stock + CC |
| Abonos | Cargo CC por período; idempotente; pago vía CC existente |
| Presupuesto | Comercial; sin efectos hasta conversión+confirmación |
| Venta | Confirmación atómica; pago vía ClientLedgerService |
| Home | Carga rápida de movimiento (no dashboard financiero) |
| Documentos 12A | Captura PWA privada; sin OCR; retención 34B preparada |
| Importaciones | Preview obligatorio; rollback por `import_batch_id` |
| Tenancy | Single-tenant |
| Frontend | Alpine.js; sin Livewire salvo justificación |

## Base de datos

Migraciones compatibles con MySQL. En local se usa SQLite por ausencia de MySQL instalado en el entorno de desarrollo actual.

## Seguridad Etapa 1

- Hash de contraseñas (cast `hashed`)
- CSRF
- Policies/middleware Spatie en backend
- Registro público deshabilitado
- Usuarios inactivos no pueden autenticarse
- Auditoría de altas/cambios relevantes

## No incluido (etapas posteriores)

## No incluido (etapas posteriores)

Facturación AFIP/ARCA, remitos fiscales, eventos, importaciones Google Sheets, notificaciones WhatsApp/email, rentabilidad completa, portal de clientes.
