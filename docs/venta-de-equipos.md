# Venta de equipos

Flujo comercial para vender un equipo armado existente, reutilizando el módulo de Ventas.

## Principios

1. Se vende la **unidad armada** (`equipment`), no se re-consumen componentes.
2. El **costo** es el histórico del armado (`total_cost_ars` / `total_cost_usd`).
3. El **precio** se puede sugerir con margen % sobre el costo (UI), pero se guarda como `unit_price` de la línea de venta.
4. Al confirmar la venta el equipo pasa a estado `sold` (ver `docs/sales.md` y `docs/equipment.md`).

## Flujo sugerido

1. Armar / tener el equipo en estado vendible (`available`, `reserved`, etc.).
2. Ventas → Nueva venta → ítem tipo **Equipo**.
3. Elegir equipo; la UI muestra costo y permite ingresar **margen %** para calcular precio = costo × (1 + margen/100).
4. Crear borrador → confirmar (crédito o contado).

## Limitación de precio en productos

El maestro de productos **no** tiene `sale_price`. Existe `reference_cost_usd` (costo de referencia), que **nunca** se muestra como “Precio”.
La columna Precio del listado queda preparada (`Product::displaySalePrice()`) y hoy muestra "—" hasta que exista un campo de venta real.
El precio comercial vive en la línea de venta / presupuesto (`unit_price`).
