# Plan de cuentas

Jerarquía contable usada por categorías financieras y movimientos.

## Conceptos (no confundir)

| Concepto | Modelo | Qué es |
|----------|--------|--------|
| **Cuenta contable** | `chart_accounts` | Estructura Activo / Pasivo / Patrimonio / Ingreso / Gasto / Resultado |
| **Categoría / subcategoría** | `categories` / `subcategories` | Clasificación operativa del movimiento |
| **Cuenta financiera** | `financial_accounts` | Dónde vive el dinero (efectivo, banco, billetera, tarjeta) |

```
Movimiento
├─ Cuenta financiera  → Caja ARS / Banco / Visa (pasivo)
├─ Categoría → Subcategoría  → clasificación del día a día
└─ Cuenta contable (plan)  → resuelta por mapeo dinámico
```

## Precedencia de mapeo

1. Subcategoría (`subcategories.chart_account_id`)
2. Categoría (`categories.chart_account_id`)
3. Default por tipo de movimiento (ingreso/gasto) en settings
4. Sin asignar (`null`)

Las reglas son dinámicas: al **crear** un movimiento se resuelve en el momento. Materializar el plan en movimientos **ya existentes** solo vía **preview + auditoría + aplicar** (UI Mapeo). No reescribir cientos de filas a mano.

## UI

- Listado jerárquico con **totales reales** (ARS / cantidad de movimientos)
- Alerta roja si hay N movimientos sin cuenta → asistente de mapeo
- Mapa interactivo
- Herramienta de mapeo: asignar cat/sub, defaults por tipo, crear cuenta inline, preview/aplicar
- Alta/edición con padre, tipo, código y vista de impacto (“usado por”)

## Impacto

Al editar una cuenta se muestra cuántas categorías, subcategorías y movimientos la referencian. Cambiar el mapeo **no** recalcula FX congelado ni toca histórico 11E/11E-R.
