# Presupuestos y ventas (Etapa 8)

## Principio

Un **presupuesto** no modifica stock, CC, finanzas ni FIFO.

Una **venta** en borrador tampoco. Los efectos ocurren al **confirmar**.

## Numeración

- Presupuestos: `P-000001`
- Ventas: `V-000001`

## Conversión

Presupuesto enviado/aceptado → **Convertir** → Venta en **borrador**.

Sin stock/CC hasta confirmar la venta.

Presupuesto **vencido**: no convierte; requiere renovación.

## Confirmación de venta (atómica)

1. Consumir productos (FIFO) / marcar equipo `sold`
2. Congelar costos y margen
3. Cargo CC (`ClientLedgerService`)
4. Si contado: pago CC + ingreso financiero
5. Estado `confirmed` + auditoría

Rollback completo si falla cualquier paso.

## Modos

| Modo | Stock | CC | Finanzas |
|------|-------|----|----------|
| Crédito | −productos | −total | sin movimiento |
| Contado | −productos | cargo+pago = 0 | +banco |

## Equipos

Vender equipo existente: estado `sold`, **sin** re-consumir componentes. Costo = costo histórico del armado.

`build_to_order` (PC a fabricar): se puede presupuestar; la confirmación de venta lo bloquea hasta fabricación (Etapa futura).

## Anulación

Política: anular ventas confirmadas (no borrar).

Revierte: pago CC, cargo CC, movimientos de stock (si el lote lo permite), estado de equipo.

Si hay inconsistencias (lote re-consumido, etc.) la anulación de stock falla y bloquea — procedimiento administrativo.

## Abonos

No se convierten automáticamente a venta. Siguen generando cargos por Etapa 7.

## Permisos

- `quotations.view|create|edit|send|accept|convert|cancel|export`
- `sales.view|create|edit|confirm|void|export`

## Servicios

- `QuotationService`
- `SaleService`

## Fuera de alcance

AFIP/ARCA, factura electrónica, remitos fiscales, WhatsApp/email, rentabilidad completa.
