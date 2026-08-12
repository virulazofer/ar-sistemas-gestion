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
| Crédito | −productos | CC IN (cargo) | sin movimiento de caja |
| Contado | −productos | CC IN + CC OUT (historial completo; neto 0) | +caja/banco |
| Parcial | −productos | CC IN total + CC OUT parcial | +caja por lo cobrado |

**Terminología comercial (11F-7):**

- **Contado** = cargo + cobro inmediato (historial CC completo, saldo neto 0) + ingreso financiero.
- **Crédito** = solo cargo (CC IN); el cobro llega después.
- **Pago a cuenta** = cobro que deja saldo a favor del cliente (sin inventar deuda).
- **Cobro** ≠ **ingreso genérico**: el cobro aplica a cargos vía `ReceiptService` (CC OUT + finanzas). Desde ficha cliente usar **Nueva operación → Cobro**.

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
