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
- **Cuenta contable** = plan (`chart_accounts`) — patrimonial; no copia del árbol cat/sub
- **Naturaleza → Categoría → Subcategoría** = clasificación operativa única
- **Ámbito** Personal/Profesional = independiente de categoría
- Pendiente operativo = sin categoría (cat/sub OK sin plan **no** es incompleto)

Ver diagrama: `docs/11f8-classification-model.md`

## Dry-run (sin aplicar masa)

```bash
php artisan classification:dry-run-11f8 --export --json=exports/11f8/dry-run.json
```

## No tocar

- Reset comercial 2026-08-12
- Reimport histórico 11E
- Aplicar reclasificación masiva sin aprobación explícita del usuario
