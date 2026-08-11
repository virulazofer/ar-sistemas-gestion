# Dashboard, reportes e importaciones (Etapa 9)

## Inicio

La pantalla principal sigue siendo **Cargar movimiento**.  
Dashboard operativo: `/dashboard/operativo`.

## Dashboard

- Líquido ARS / USD por tipo de cuenta (sin sumar monedas).
- Cotización DolarAPI + actualización manual.
- Clientes, proveedores, stock FIFO, equipos, OT, abonos, ventas, presupuestos.
- Filtro Personal / Profesional / Consolidado (actividad del mes).
- Alertas accionables.
- Cache 30s del snapshot (no aplica a reportes/export).

## Reportes

Finanzas, clientes, proveedores, stock (valorización FIFO por lotes), ventas, rentabilidad (precio − costo real), plan de cuentas.

Export: CSV, XLSX; PDF en reportes clave.

## Importaciones

Flujo: archivo → validación → vista previa → confirmación → importación.  
Rollback por `import_batch_id` cuando es seguro.

Formatos: CSV / XLSX (exportados desde Google Sheets). Arquitectura lista para Google API futura.

Movimientos importados pasan por `MovementService::createSimple`.

Duplicados: CUIT/DNI (clientes), SKU (productos), `external_id` (movimientos).

Histórico 11E (preview): semáforo Verde / Amarillo / Rojo / **Pendiente de completar**.  
Las anotaciones sin fecha e importe 0 no son error ni basura.  
Feature futura: [Pendientes de carga](stage11e-pendientes-de-carga.md) (fuera de alcance 11E).

## Búsqueda global

`/buscar` — clientes, proveedores, productos, equipos, OT, presupuestos, ventas.

## Permisos

- `dashboard.view`
- `reports.view|finance|clients|suppliers|stock|sales|profitability|export`
- `imports.view|execute`
- `exports.execute`

## Rendimiento

Agregaciones + índices (`import_batch_id`, estados). Cache corto solo en dashboard.

## MySQL

Migraciones compatibles MySQL 8. Validación real pendiente de autorización (ver `docs/mysql-validation.md`).

## Fuera de alcance

AFIP, factura electrónica, WhatsApp/email, portal, API pública, contabilidad completa.
