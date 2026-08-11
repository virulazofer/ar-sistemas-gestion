# Etapa 11F-3 — Auditoría funcional + diseño de implementación

> Auditoría previa a código (flujos A–F) y contrato de comportamiento para Cobros, Cargos, CC y Regularización.

## Objetivo

Completar flujos operativos centrales sin reescribir lo que ya funciona, distinguiendo siempre:

| Concepto | Entidad | Efecto |
|----------|---------|--------|
| **A. Cargo / operación comercial** | `commercial_charges` | Deuda comercial abierta (importe, tipo, estado cobro) |
| **B. Movimiento de CC** | `client_ledger_entries` | Mayor CC (IN↑ deuda / OUT↓); no editar saldo directo |
| **C. Cobro** | `receipts` + `movements` (ingreso) | Dinero en cuenta financiera + CC OUT + aplicaciones |
| **D. Comprobante asociado** | `commercial_vouchers` | Metadata documental opcional (no ARCA) |

## Convención CC (sin cambios)

- Persistencia ledger (perspectiva cliente): cargo `signed_amount < 0`, cobro/crédito `> 0`.
- Presentación negocio (`UiSemantics::clientCcDisplayBalance`): **+ rojo = nos deben**, **− verde = a favor**, 0 neutro.
- CC IN = aumenta deuda; CC OUT = reduce deuda.

---

## Auditoría flujos A–F (antes de código)

### A. Abono → CC → Cobro

| Aspecto | Estado |
|---------|--------|
| Crear abono | **OK** — `SubscriptionService::create` |
| Generar cargo período | **OK** — `generatePeriod` → `ClientLedgerEntry` Charge (CC IN), idempotente por `subscription_id + period_key` |
| Generar vencidos / generar uno | **OK** — rutas + comando scheduler |
| Comprobante fiscal auto | **OK** — no se genera (correcto) |
| Entidad cargo comercial abierta | **FALTA** — solo ledger; no hay `amount_open` ni aplicaciones |
| UI “Generar cargo ahora” | **PARCIAL** — existe `POST /abonos/{id}/generar`; falta claridad de estados cobro/documental en listado |
| Cobro aplicado al cargo del abono | **FALTA** — pago genérico CC sin vínculo cargo↔cobro |
| Reutilizable | `SubscriptionService`, `ClientLedgerService` |

### B. Presupuesto → Venta contado

| Aspecto | Estado |
|---------|--------|
| Presupuesto sin dinero/CC/stock | **OK** — `QuotationService` no toca finanzas/stock/CC |
| Convertir → venta borrador | **OK** |
| Confirmar contado | **OK** — stock FIFO + cargo CC + pago atómico (`SaleService::confirm` cash) |
| Labels ítems | **PARCIAL** — hay Producto/Equipo/Servicio/libre; falta distinguir Unidad vs Equipo armado en UI |
| Comprobante / estado documental | **FALTA** |
| Reutilizable | `QuotationService`, `SaleService` |

### C. Presupuesto → Venta crédito → Cobro

| Aspecto | Estado |
|---------|--------|
| Confirmar crédito | **OK** — stock + CC IN, sin ingreso financiero |
| Cobro posterior aplicado a la venta | **FALTA** — pago genérico; no `receipt_applications` |
| Venta parcial (parte cobrada + resto CC) | **FALTA** — solo `cash\|credit` |
| Doble contabilización | **OK hoy** en contado/crédito; preservar al integrar cargos/cobros |
| Reutilizable | `SaleService::confirm` credit path |

### D. Compra → Stock

| Aspecto | Estado |
|---------|--------|
| Contado → egreso financiero único | **OK** |
| Crédito → CC proveedor | **OK** |
| Ingreso stock + lotes FIFO | **OK** — `InventoryService::receiveFromPurchase` |
| Anulación con reverse | **OK** |
| Doble egreso | **No observado** |
| Reutilizable | `PurchaseService` completo |

### E. Producto → Unidad → Equipo

| Aspecto | Estado |
|---------|--------|
| Producto (definición) vs Unidad (instancia) | **OK** — `Product` / `InventoryUnit` |
| Condición ≠ Estado | **OK** — enums separados + historial `InventoryUnitEvent` |
| Estado “Reparación” | **FALTA** en `UnitStatus` (hay Available/InUse/Reserved/Sold/Scrapped) |
| Equipo armado con componentes/seriales/costo | **OK** — `EquipmentAssemblyService` |
| No consumir componentes dos veces | **OK** (tests Etapa 6/10) |
| Nav Inventario agrupado | **PARCIAL** — Stock/Compras en Inventario; Productos/Equipos en Maestros/Operaciones |
| Reutilizable | `InventoryUnitService`, `EquipmentAssemblyService` |

### F. Servicio / Remoto → Cargo → Cobro

| Aspecto | Estado |
|---------|--------|
| Cargo manual CC | **OK** — `ClientLedgerController` + `registerCharge` |
| OT → cargo al cerrar | **OK** — `WorkOrderService` |
| Servicio sin OT obligatoria | **PARCIAL** — posible vía cargo manual; falta UI “Cargos al cliente” tipificada |
| Cobro con aplicación | **FALTA** |
| Reutilizable | `ClientLedgerService`, OT opcional |

### Resumen gaps prioritarios

1. Código cliente único permanente (`code`, no PK).
2. Entidad **cargo comercial** + estados de cobro/documental.
3. Entidad **cobro (receipt)** + tabla **aplicaciones** explícitas.
4. Flujo deuda insuficiente A/B/C (nunca auto-factura).
5. Pago a cuenta + consumo de crédito en cargos futuros.
6. Regularizar CC (permiso + motivo; reutilizar adjustments).
7. Comprobantes opcionales + consulta “sin comprobante”.
8. Venta parcial; labels presupuestos; nav Inventario; estado unidad Reparación.
9. Permisos charges/receipts/regularize/edit_code.

### Duplicados / no reescribir

- **No** reemplazar `client_ledger_entries` ni `ClientLedgerService` — son el mayor CC.
- **No** rehacer compras/FIFO/equipos/abonos/presupuestos base.
- **No** tocar import 11E ni Dashboard 11F-1 (salvo enlaces).
- Nav/skins/search 11F-2: solo integrar rutas nuevas.

---

## Diseño de implementación (11F-3)

### Cliente código

- Columna `clients.code` (unsigned int, unique, not null after backfill).
- Formato UI: `sprintf('%04d', $code)` → `0001 — DAASA`.
- Auto-asignación al crear; inmutable salvo `clients.edit_code`.
- Búsqueda por código en index, selectores, global search.

### Cargos comerciales

Tipos: `subscription`, `sale`, `repair`, `installation`, `remote`, `service`, `authorized_adjustment`, `other`.

Estados cobro: `pending`, `partial`, `collected`, `voided`.

Estados documentales: `none`, `pending`, `associated`, `not_required`, `review`.

Al crear a crédito: cargo + CC IN (ledger Charge). Sin ingreso financiero.

Orígenes: manual, sale, subscription period, work order.

### Cobros

Rutas: `Comercial → Cobros` y `Cliente → CC → Registrar cobro`.

Confirmación atómica: movimiento ingreso + ledger Payment (CC OUT) + `receipt_applications` + actualización `amount_open` del cargo.

Modos de aplicación: auto por antigüedad, manual, parcial, multi-cargo.

### Deuda insuficiente

Mensaje + opciones A (crear cargo faltante y aplicar) / B (pago a cuenta) / C (cancelar). Nunca auto-cargo ni auto-factura.

### Pago a cuenta

Aplica a deuda abierta; excedente queda como saldo a favor (ledger neto positivo → display negativo verde). Cargos futuros pueden consumir crédito (`applyCredit` / aplicación automática opcional al crear cargo).

### Regularizar CC

Reutiliza `registerAdjustment` + metadata (`reason`, usuario, refs). Permiso `clients.regularize`. Nunca UPDATE de saldo.

### Comprobantes

Tabla `commercial_vouchers` (tipo Factura/NC/ND/Otro, PV, número, fecha, importe, campos fiscales futuros nullable). Asociación posterior sin duplicar cargo.

Consulta: operaciones con documental `none|pending|review`.

### Ventas

- Contado/crédito: crear `commercial_charge` además del ledger existente.
- Parcial: `payment_mode=partial` + `amount_paid` → cargo total + cobro parcial aplicado.
- `documental_status` en sales.

### Abonos

Al generar período: también `commercial_charge`. UI: Generar cargo ahora + estados cobro/documental.

### Anulaciones

Void (no delete): cobro revierte finanzas + CC + aplicaciones; cargo revierte CC y bloquea si hay aplicaciones posted.

### Permisos nuevos

- `charges.*` (view/create/void)
- `receipts.*` (view/create/apply/void)
- `clients.regularize`, `clients.edit_code`
- Acción `apply` en catálogo

### Migraciones

Solo las necesarias; backfill códigos y cargos desde ledger Charge posted existentes (sin tocar import 11E).

### Criterios de aceptación

1. Flujos A/B/C deuda insuficiente.
2. Matriz permisos cobros/regularizar.
3. Tests 1–28 + suite completa.
4. Smoke staging con datos TEST y cleanup.
5. Informe final §35; DETENERSE.

## Casos de prueba mínimos (referencia §32)

Código único · cargo→CC IN · cobro→finanzas+CC OUT · parcial · multi-cargo · multi-cobro · pago a cuenta · crédito consume · cargo desde cobro · sin comprobante · asociar después · abono · no duplica período · venta contado/crédito/parcial · no doble ingreso · compra contado/crédito · stock una vez · unidad condición/estado · equipo componentes · OT opcional · regularización · permisos · reversión cobro/cargo · CC rojo/verde.
