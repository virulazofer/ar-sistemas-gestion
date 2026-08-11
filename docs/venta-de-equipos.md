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

## Qué no hace

- No fabrica automáticamente (`build_to_order` sigue bloqueado hasta fabricación).
- No vuelve a descontar stock de componentes.
- No inventa precio de lista en el maestro de productos (el precio vive en el documento de venta).

## Ayuda en pantalla

Ver `x-page-help` en ventas y equipos (`config/help.php` → `sales`, `equipment`, `equipment_sale`).
