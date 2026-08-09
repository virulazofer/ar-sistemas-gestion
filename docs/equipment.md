# Equipos armados (Etapa 6)

## Concepto

Un **equipo** es una unidad física individual (PC, servidor, etc.) armada consumiendo stock real vía FIFO (+ seriales cuando aplica).

## Flujo

```text
Tipo + plantilla
   ↓
Selección de productos / seriales
   ↓
Consumo InventoryService (FIFO / lote+serial)
   ↓
equipment_components + allocations
   ↓
Costo consolidado histórico
```

## Costos

El costo del equipo es la suma de costos FIFO reales de los componentes. No usa precio de lista ni cotización actual.

Cada componente conserva: moneda, cotización histórica, equivalentes ARS/USD, lote, allocation, serial.

## Serialización

`products.requires_serial`:

- Al ingresar stock: un serial por unidad.
- Al consumir/armar: obligatorio identificar la unidad.
- Serial único por producto; no se puede reutilizar mientras esté `consumed`.

## Estados

| Desde | Hacia permitidos |
|-------|------------------|
| assembled | available, reserved, in_repair, disassembled |
| available | reserved, delivered, in_repair, out_of_service, sold, disassembled |
| reserved | available, delivered, sold, disassembled |
| delivered | available, in_repair, sold |
| in_repair | available, out_of_service, disassembled |
| out_of_service | available, disassembled, sold |
| sold | (bloqueado; desarmado solo con override admin) |
| disassembled | (terminal) |

## Desarmado / reemplazo

- Desarmar: recupera componentes a stock (`returnRecovered`) con costo histórico; seriales vuelven a `available`.
- Reemplazo: recupera el viejo + consume el nuevo; historial conservado (`replaced_by_component_id`).

## Identificadores

`{code_prefix}-{######}` configurable por tipo (ej. `PC-000001`).

## Servicios

- `EquipmentTypeService` — tipos, plantillas, códigos
- `EquipmentAssemblyService` — armar, estado, desarmar, reemplazar
- `SerialInventoryService` — registro/consumo de seriales
- `InventoryService::consumeFromLot` / `returnRecovered`

## Permisos

`equipment.view|create|edit|void|assemble|disassemble|change_component|change_status|export`

## Reserva previa

Diseño preparado vía stock `reserve` existente; el armado actual consume directamente. Flujo reserva→armado puede enlazarse en etapas posteriores.

## Fuera de alcance

Ventas, facturación, AFIP, presupuestos, reparaciones operativas, abonos, eventos.
