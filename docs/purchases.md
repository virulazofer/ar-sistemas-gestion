# Proveedores y compras (Etapa 4)

## Alcance

Proveedores, cuenta corriente, compras (contado/crédito), costos históricos congelados, pagos atómicos e integración con finanzas. **Sin stock operativo** (Etapa 5).

## Convención de saldo (CC proveedor)

Perspectiva propia (nosotros):

| Valor | Significado |
|-------|-------------|
| Negativo | Le debemos al proveedor |
| Positivo | Crédito a nuestro favor |
| Cero | Sin saldo |

ARS y USD son **independientes**.

## Tipos CC proveedor

| Tipo | Efecto | ¿Mueve finanzas? |
|------|--------|------------------|
| `charge` (obligación) | − | No |
| `payment` | + | Sí (egreso atómico vía `SupplierPaymentService`) |
| `credit` | + | No |
| `credit_application` | − | No |
| `adjustment` | ± | No |

Estados: `posted` \| `voided`.

## Contado vs crédito

### Compra contado

1. Registra compra + líneas con costo histórico.
2. Un único egreso financiero (`financial_movement_id`).
3. **No** genera cargo en CC (no hay deuda).

Evita duplicar la salida: la compra contado *es* el pago efectivo.

**11F-7:** proveedor opcional en contado personal/ocasional (`counterparty_name`). No inventar proveedores para Super/Comidas/kiosco. Crédito sigue exigiendo proveedor (CC). Identificación de proveedores: **CUIT obligatorio** (sin DNI).

### Compra a crédito

1. Registra compra + líneas.
2. Genera obligación (`charge`) en CC (`obligation_ledger_entry_id`).
3. **Sin** movimiento bancario hasta el pago posterior.

### Pago posterior

`SupplierPaymentService::pay`:

1. egreso financiero con `supplier_id`;
2. asiento CC `payment` vinculado;
3. opcionalmente `purchase_id` para trazabilidad;
4. transacción atómica.

Pago superior a la deuda → saldo positivo (crédito a favor).

## Costos históricos

Cada línea guarda:

- precio original, moneda, cotización congelada;
- `unit_cost_ars` / `unit_cost_usd`;
- `line_total_ars` / `line_total_usd`.

No se recalcula si cambia la cotización oficial.

## Preparación FIFO (sin implementar stock)

En `purchase_items`:

- `product_id` (nullable, sin FK a products aún);
- `qty_pending_stock`;
- `stock_receipt_ready`.

Flujo futuro: COMPRA → entrada stock → lote FIFO → consumo.

## Comprobantes

Campos en compra: `voucher_type`, `voucher_letter`, `voucher_number`, fecha, proveedor, importe/moneda vía totales. Sin AFIP/ARCA.

## Documentos

Morph `documents` sobre `Supplier` y `Purchase` (módulo existente).

## Anulación

| Caso | Efecto |
|------|--------|
| Contado posted | Anula egreso financiero + marca compra `voided` |
| Crédito sin pagos vinculados | Anula obligación CC + compra `voided` |
| Crédito con pagos posted vinculados | **Bloqueado** |
| Documentos | Permanecen asociados |

No hay borrado físico de operaciones confirmadas.

## Servicios

- `App\Services\Suppliers\SupplierLedgerService`
- `App\Services\Suppliers\SupplierPaymentService`
- `App\Services\Purchases\PurchaseService`

## Permisos

- `suppliers.*` (incluye `void` para CC)
- `purchases.*` (incluye `void` para compras)
- Pagos a proveedor: `suppliers.create`

## Impuestos

Cabecera: `subtotal`, `tax_amount` (IVA), `other_taxes`, `discount_amount`, `total`. Por línea también hay impuestos/descuentos opcionales. Sin motor fiscal complejo.
