# Órdenes de trabajo y abonos (Etapa 7)

## Orden de Trabajo (OT)

Unidad central de operatoria: cliente + tipo + tareas + materiales + diagnóstico + cargo.

### Cierre atómico

1. Consume materiales `pending` vía FIFO (`InventoryService`).
2. Congela costos en líneas de material.
3. Genera cargo CC (`ClientLedgerService`) vinculado a `work_order_id`.
4. Marca OT `closed`.

Sin movimiento bancario. Si falla cualquier paso → rollback completo.

### Costos vs precios

| Concepto | Origen |
|----------|--------|
| Costo material | FIFO al consumir |
| Precio material | Definido en la OT |
| Precio tarea | Fijo u horas (importe libre) |
| Margen | precio − costo (informativo) |

OT cerrada/cancelada: sin ediciones libres.

## Abonos

Periodicidades: mensual, trimestral, semestral, anual.

### Idempotencia

Clave única `(subscription_id, period_key)` — ej. `2026-09`.

Re-ejecutar generación del mismo período **no duplica** el cargo.

### Comando

```bash
php artisan subscriptions:generate
php artisan subscriptions:generate --date=2026-09-15
```

### Recordatorios (estructura)

Campos `reminder_days_before`, `remind_on`, `last_reminder_at` — sin envío WhatsApp/email/SMS todavía.

## Permisos

- `work_orders.view|create|edit|void|close|cancel|consume_stock|charge|export`
- `subscriptions.view|create|edit|void|generate|cancel|export`

## Servicios

- `WorkOrderService`
- `SubscriptionService`

## Fuera de alcance

Presupuestos, ventas, facturación, AFIP, WhatsApp/email, eventos, Google Sheets.
