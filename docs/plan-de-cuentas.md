# Plan de cuentas

Jerarquía contable usada por categorías financieras y movimientos.

## Conceptos (no confundir)

| Concepto | Modelo | Qué es | Dónde se ve |
|----------|--------|--------|-------------|
| **Cuenta financiera** | `financial_accounts` | Dónde vive el dinero (efectivo, banco, billetera, tarjeta) | Finanzas → Cuentas |
| **Categoría / subcategoría** | `categories` / `subcategories` | Clasificación operativa del día a día | Finanzas → Categorías |
| **Cuenta contable (plan)** | `chart_accounts` | Estructura Activo / Pasivo / Patrimonio / Ingreso / Gasto / Resultado | Finanzas → Plan de cuentas |
| **Cuenta corriente (CC)** | `client_ledger_entries` (+ cargos/recibos) | Deuda / crédito del cliente (no es caja ni plan) | Clientes → CC |

```
Movimiento (ingreso/gasto/transferencia)
├─ Cuenta financiera  → Caja ARS / Banco / Visa (pasivo)
├─ Categoría → Subcategoría  → clasificación operativa
├─ Cuenta contable (plan)  → resuelta por mapeo dinámico
└─ (opcional) Cliente / CC  → cobro aplicado sin duplicar ingreso
```

Organigrama de impacto en reportes:

```
Movimiento
  → Categoría / Subcategoría
      → Mapeo (precedencia abajo)
          → Cuenta contable
              → Reportes por plan / árbol con totales
  → Cuenta financiera
      → Saldos de caja/banco/tarjeta
  → CC (si aplica)
      → Ranking a cobrar / a favor (convención presentación)
```

## Precedencia de mapeo

1. Subcategoría (`subcategories.chart_account_id`)
2. Categoría (`categories.chart_account_id`)
3. Default por tipo de movimiento (ingreso/gasto) en settings
4. Sin asignar (`null`)

Las reglas son dinámicas: al **crear** un movimiento se resuelve en el momento. Materializar el plan en movimientos **ya existentes** solo vía **preview + auditoría + aplicar** (UI Mapeo). No reescribir cientos de filas a mano.

## Eliminación de cuentas del plan

No hay “indeleble por diseño”. Desde Editar → Eliminar:

- **Reasignar** referencias (categorías, subcategorías, movimientos, defaults de tipo) a otra cuenta
- **Dejar sin asignar** (`null`)
- **Cancelar**
- **Hijas**: reparentar al destino/padre, o bloquear mientras existan

Queda auditoría `chart_account_deleted`.

## UI

- Listado jerárquico con **totales reales** (ARS / cantidad de movimientos)
- Alerta roja si hay N movimientos sin cuenta → asistente de mapeo
- Mapa interactivo
- Herramienta de mapeo: asignar cat/sub, defaults por tipo, crear cuenta inline, preview/aplicar
- Alta/edición con padre, tipo, código, vista de impacto (“usado por”) y eliminación real

## Impacto

Al editar una cuenta se muestra cuántas categorías, subcategorías, movimientos e hijas la referencian. Cambiar el mapeo **no** recalcula FX congelado ni toca histórico 11E/11E-R.
