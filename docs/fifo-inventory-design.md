# Diseño anticipado FIFO (sin implementar en Etapa 1)

Verificación arquitectónica pedida antes de Etapa 1: el stock futuro puede incorporar FIFO **sin rediseñar** el modelo conceptual.

## Principio

- `products.stock` (o equivalente) = cantidad denormalizada para lectura rápida.
- Toda variación pasa por un **servicio transaccional** que:
  1. escribe movimientos de inventario;
  2. consume/crea lotes FIFO;
  3. actualiza el stock denormalizado;
  4. registra costos congelados usados.

## Tablas previstas (Etapas 5–6)

### Origen de costos (Etapa 4 — ya preparado)

Las líneas de compra (`purchase_items`) ya conservan cantidad pendiente de stock (`qty_pending_stock`), flag `stock_receipt_ready`, costos unitarios ARS/USD y cotización congelada. En Etapa 5 los lotes FIFO deben nacer desde esas líneas, no desde una segunda lógica de costos.

### `inventory_lots` (lotes / entradas)

| Campo | Uso |
|-------|-----|
| id | PK |
| product_id | FK producto |
| received_at | fecha ingreso |
| supplier_id | proveedor (nullable) |
| purchase_id / purchase_item_id | origen compra |
| qty_received | cantidad ingresada |
| qty_remaining | cantidad disponible |
| unit_cost | costo unitario |
| currency_id | moneda |
| exchange_rate_id / rate_value | cotización congelada |
| cost_ars / cost_usd | equivalentes |
| timestamps | |

Índice sugerido: `(product_id, received_at, id)` para consumir el lote más antiguo con `qty_remaining > 0`.

### `inventory_movements`

Movimientos de trazabilidad (compra, consumo, armado, ajuste, etc.) sin editar stock a mano.

### `inventory_movement_lot_allocations`

Desglose FIFO al consumir:

| Campo | Uso |
|-------|-----|
| inventory_movement_id | movimiento de salida |
| inventory_lot_id | lote consumido |
| qty | cantidad tomada del lote |
| unit_cost | costo del lote (congelado) |

### Equipos armados

`assembled_unit_components` referenciará allocations/lotes para saber exactamente qué lotes formaron cada PC y su costo real.

## Por qué no hay que rediseñar después

1. No se asume “último costo” en el diseño.
2. El stock denormalizado nunca es fuente de valuación.
3. Los costos históricos viven en lotes + allocations.
4. Las tablas de Etapa 1 (`users`, `settings`, `audit_logs`, Spatie) no colisionan con este modelo.

## Fuera de alcance ahora

Diseño implementado en Etapa 5 (`docs/stock.md`). Equipos armados siguen en etapas posteriores.
