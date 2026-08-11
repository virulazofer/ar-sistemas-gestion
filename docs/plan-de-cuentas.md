# Plan de cuentas

Jerarquía contable usada por categorías financieras y movimientos.

## Conceptos

- **Cuenta contable** (`chart_accounts`): estructura de Activo / Pasivo / Patrimonio / Ingreso / Gasto / Resultado.
- **Categoría financiera** ≠ cuenta financiera (caja/banco). La categoría clasifica el movimiento y puede apuntar a una cuenta del plan.
- **Cuenta financiera** (`financial_accounts`): dónde vive el dinero (efectivo, banco, billetera, tarjeta).

## UI

- Listado jerárquico: Maestros → Plan de cuentas
- Mapa interactivo: árbol desde la base de datos
- Alta/edición con padre, tipo, código y vista de impacto (“usado por”)

## Impacto

Al editar una cuenta se muestra cuántas categorías, subcategorías y movimientos la referencian. No se borran movimientos históricos al cambiar el plan.
