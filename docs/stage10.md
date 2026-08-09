# Etapa 10 — Validación integral MySQL

Suite de compatibilidad/integración sobre MySQL 8.x real (`ar_sistemas_test`).

## Comando

```powershell
$env:RUN_MYSQL_TESTS = "1"
$env:MYSQL_TEST_HOST = "127.0.0.1"
$env:MYSQL_TEST_PORT = "3306"
$env:MYSQL_TEST_DATABASE = "ar_sistemas_test"
$env:MYSQL_TEST_USERNAME = "root"
$env:MYSQL_TEST_PASSWORD = "TU_PASSWORD"
php artisan test --group=mysql
```

La suite diaria (`php artisan test`) **no** ejecuta estos tests (grupo `mysql` excluido en `phpunit.xml`).

## Estructura

| Ubicación | Rol |
|-----------|-----|
| `tests/Mysql/` | Integración Etapa 10 (sin `RefreshDatabase`/SQLite) |
| `tests/Feature/Stage2FinanceTest.php` | Test financiero MySQL legacy (`->group('mysql')`) |
| `tests/Pest.php` → `bootMysqlIntegration()` | Bootstrap común |

## Cobertura

Flujo transversal: cliente, proveedor, producto, compra USD, lotes FIFO, equipo+seriales, OT+CC, abono idempotente, presupuesto→venta, pago, dashboard/reportes, auditoría, DECIMAL/FK, rollback multi-módulo, lock InnoDB entre conexiones.
