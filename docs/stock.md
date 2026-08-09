# Stock, productos y FIFO (Etapa 5)

## Principios

1. **Los movimientos de inventario son la fuente de verdad histórica.**
2. `products.qty_on_hand` / `qty_reserved` son **caché denormalizada**.
3. Valuación y costo de salida salen de **lotes + allocations FIFO**, nunca del promedio ni de la cotización actual.
4. `disponible = actual − reservado`.

## Productos

- Tipo: `physical` | `service` (solo físicos tienen stock).
- Categorías/subcategorías propias (`product_categories`), distintas de las financieras.
- SKU estable para futura importación.
- Stock 0 permitido (catálogo).

## Movimientos

Tipos: `receipt`, `issue`, `adjustment_in`, `adjustment_out`, `transfer_out`, `transfer_in`, `reserve`, `release`, `consume`.

Estados: `posted` | `voided` (sin borrado físico).

## Lotes FIFO

Orden de consumo: `received_at ASC`, `id ASC`.

Cada lote conserva: costo unitario original, moneda, cotización congelada, equivalentes ARS/USD, compra/línea/proveedor.

## Integración compras

Compra confirmada con `product_id` en línea física:

```text
COMPRA → línea → receipt → lote → +stock
```

Una sola operación de ingreso (no duplicar). Líneas sin producto dejan `qty_pending_stock` para ingreso posterior.

Anular compra: anula receipts si el lote no fue consumido; si ya hubo consumo, bloquea.

## Concurrencia

Operaciones de stock usan `DB::transaction` + `lockForUpdate` sobre producto y lotes FIFO. Dos consumos concurrentes no pueden tomar las mismas unidades.

## Stock negativo

Setting `stock.allow_negative` (default `false`). Por defecto se rechaza.

## Reconstrucción

`StockBalanceService::rebuildProduct` / `rebuildAll`:

- Recalcula `qty_on_hand` y `qty_reserved` sumando movimientos `posted`.
- Recalcula `qty_remaining` de lotes = recibido − allocations de movimientos posted.
- UI: `/stock/reconstruir` (`stock.rebuild`).

## Servicios

| Servicio | Rol |
|----------|-----|
| `ProductService` | Alta/edición catálogo |
| `InventoryService` | Ingresos, salidas, ajustes, reservas, transferencias, void |
| `FifoService` | Plan de consumo FIFO |
| `StockBalanceService` | Cache, valuación, reconstrucción |

## Permisos

- `products.view|create|edit|void|export`
- `stock.view|create|edit|void|adjust|transfer|consume|rebuild|export`

## Fuera de alcance (Etapa 6+)

Equipos armados, series, ventas, facturación, AFIP, Google Sheets.
