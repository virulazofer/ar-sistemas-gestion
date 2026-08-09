# Validación MySQL — checklist operativo

## Filosofía de suites

| Suite | Motor | Comando | MySQL requerido |
|-------|--------|---------|-----------------|
| **Diaria / desarrollo** | SQLite `:memory:` | `php artisan test` | No |
| **Compatibilidad** | MySQL 8.x | `php artisan test --group=mysql` | Sí |

El grupo `mysql` está **excluido** de la suite normal en `phpunit.xml`.  
Así, `php artisan test` nunca ejecuta el test MySQL aunque existan `MYSQL_TEST_*` o `RUN_MYSQL_TESTS=1`.

## Estado actual

| Ítem | Resultado |
|------|-----------|
| `pdo_mysql` en PHP | Sí |
| Suite SQLite diaria | Aislada (grupo `mysql` excluido) |
| Suite MySQL (`--group=mysql`) | Explícita / bajo demanda |
| DB destructiva de pruebas | `ar_sistemas_test` |
| Migraciones compatibles MySQL 8 | Sí |

No instalar ni configurar MySQL sin autorización explícita.

## Base de datos de prueba

```sql
CREATE DATABASE ar_sistemas_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Usuario con privilegios DDL+DML sobre esa DB (`migrate:fresh --seed` es destructivo).

## Variables de entorno (solo suite MySQL)

| Variable | Default | Uso |
|----------|---------|-----|
| `RUN_MYSQL_TESTS` | — | Debe ser `1` o el test se skipea |
| `MYSQL_TEST_HOST` | `127.0.0.1` | Host |
| `MYSQL_TEST_PORT` | `3306` | Puerto |
| `MYSQL_TEST_DATABASE` | `ar_sistemas_test` | Base |
| `MYSQL_TEST_USERNAME` | `root` | Usuario |
| `MYSQL_TEST_PASSWORD` | _(vacío)_ | Contraseña |

## Comandos

### A) Suite normal (SQLite)

```powershell
php artisan test
```

No corre el grupo `mysql`.

### B) Suite MySQL

```powershell
$env:RUN_MYSQL_TESTS = "1"
$env:MYSQL_TEST_HOST = "127.0.0.1"
$env:MYSQL_TEST_PORT = "3306"
$env:MYSQL_TEST_DATABASE = "ar_sistemas_test"
$env:MYSQL_TEST_USERNAME = "root"
$env:MYSQL_TEST_PASSWORD = "TU_PASSWORD"
php artisan test --group=mysql
```

Ejecuta `migrate:fresh --seed` sobre `ar_sistemas_test` y operaciones financieras reales.

## Aislamiento

Cada comando es un **proceso PHP separado**. El test MySQL puede cambiar `config('database.default')` a `mysql` sin contaminar la suite SQLite (otro proceso / grupo excluido).

## Etapa 10 — validación integral

Además del test financiero Stage 2, la suite `--group=mysql` incluye `tests/Mysql/Stage10IntegrationTest.php`:

- Flujo transversal multi-módulo (servicios de aplicación).
- FIFO obligatorio 10×60 + 5×70 → consumir 12 = USD 740.
- Rollback intencional en confirmación de venta.
- Concurrencia real InnoDB: `lockForUpdate` entre dos conexiones + `innodb_lock_wait_timeout=1`.

Ver `docs/stage10.md`.

## Compatibilidad MySQL 8 — índices

MySQL limita nombres de índices a **64 caracteres**.

| Migración | Índice | Nombre |
|-----------|--------|--------|
| `2026_08_07_230110_create_exchange_rates_table` | `(base_currency_id, quote_currency_id, rate_type, rate_at)` | `exchange_rates_lookup_idx` |

Error típico sin la corrección: `SQLSTATE[42000] ... 1059 Identifier name ... is too long`.
