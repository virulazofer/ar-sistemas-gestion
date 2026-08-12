# 11F-8 — Auditoría previa (reutilización)

## Reutilizar (no duplicar)

| Pieza | Ubicación | Uso en 11F-8 |
|-------|-----------|--------------|
| Plan de cuentas | `ChartAccount`, `ChartAccountController`, `ChartAccountSeeder` | Árbol, CRUD, totales |
| Mapeo cat/sub → plan | `ChartAccountMappingService` | Precedencia + materialización con preview |
| Defaults tipo | `Setting chart_mapping.type_defaults` | Migrados a **Reglas de imputación** (tipo de movimiento) |
| Categorías / subs | `Category`, `Subcategory`, `CategoryController` | Reclasificación admin con preview |
| Contador sin cuenta | `countMovementsWithoutAccount()` | Alerta clickeable → listado real |
| Reportes | `ReportController` + columnas ES | Completar totales ES |
| Auth Breeze | password reset controllers/views | Activar verification + admin “enviar enlace” |
| Ayuda | `config/help.php` | Topics nuevos / actualizados |

## Distinciones de modelo

- **Cuenta financiera** = caja/banco/billetera/tarjeta (`financial_accounts`)
- **Cuenta contable** = plan (`chart_accounts`)
- **Categoría / subcategoría** = clasificación operativa
- **Ámbito** Personal/Profesional = independiente de categoría

## No tocar

- Reset comercial 2026-08-12
- Reimport histórico 11E
- Migraciones semánticas ambiguas Comida/Auto/Miranda/MYU (solo análisis + infra)
