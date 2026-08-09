# Arquitectura financiera (Etapa 2)

## Principios

1. Integridad > comodidad
2. Cotizaciones históricas congeladas en cada movimiento
3. Saldos derivados de movimientos `posted`
4. Transferencias atómicas (2 movimientos + `transfer_id`)
5. Importes en `DECIMAL(18,2)` / cotizaciones `DECIMAL(18,6)` — nunca float de negocio
6. Ámbito Personal/Profesional en el movimiento, no duplicando cuentas

## Anulación (decisión)

**Estado `voided` + motivo + usuario + fecha**, sin movimiento inverso en Etapa 2.

Motivos:
- conserva el documento original intacto;
- evita ruido en reportes de actividad si se filtra por `posted`;
- anula ambas piernas de una transferencia juntas.

Los saldos solo suman movimientos `posted`.

## Transferencias

- Tipos: `transfer_out` + `transfer_in`
- Mismo `transfer_id` (UUID)
- **Misma moneda** (Etapa 2)
- Transacción DB; rollback total si falla

### Transferencias vs conversión de moneda

Una transferencia **normal** solo mueve valor entre cuentas de la **misma moneda**.

**No** debe usarse el flujo de transferencia para cambiar ARS↔USD (ni otras pares).

En una etapa futura deberá existir una operación específica:

**CONVERSIÓN / CAMBIO DE MONEDA**

con al menos:

| Campo | Uso |
|-------|-----|
| cuenta origen | egreso en moneda origen |
| moneda origen | ARS / USD / … |
| importe origen | monto debitado |
| cuenta destino | ingreso en moneda destino |
| moneda destino | distinta a la origen |
| importe destino | monto acreditado |
| cotización | congelada en la operación |
| fecha | operativa |
| usuario | quien registró |
| trazabilidad | vínculo entre piernas + auditoría |

Hasta implementarla, el sistema **rechaza** transferencias entre cuentas de distinta moneda.

## DolarAPI

- Provider: `App\Integrations\ExchangeRates\DolarApiProvider`
- Contrato: `ExchangeRateProvider`
- Servicio: `ExchangeRateService`
- Config: `config/finance.php` + env `DOLARAPI_*`
- Cotización usada: **venta oficial**

## Diferencias SQLite vs MySQL

| Tema | SQLite (dev/tests) | MySQL 8 (objetivo) |
|------|--------------------|--------------------|
| DECIMAL | Affinity numérica; OK para tests | DECIMAL nativo estricto |
| lockForUpdate | Limitado | Row locks reales |
| DATE_FORMAT vs strftime | `BalanceService` ramifica por driver | `DATE_FORMAT` |
| FK | Soportadas si habilitadas | Enforced |

Las migraciones están escritas para MySQL; no hay SQL exclusivo de SQLite en el dominio financiero salvo el helper de mes en reportes.

## Validación MySQL 8 (pre-Etapa 3)

### Estado

**NO APROBADA** — pendiente de ejecución real.

Al 2026-08-07, en el entorno de desarrollo actual:

- no hay cliente `mysql`/`mysqld` en PATH;
- no hay servicio MySQL/MariaDB instalado;
- el puerto `3306` en `127.0.0.1` no acepta conexiones;
- PHP sí tiene extensión `pdo_mysql` habilitada.

Por instrucción del proyecto: **no se instaló ni configuró MySQL sin autorización explícita**.

### Requisitos exactos del entorno

1. **MySQL Server 8.x** (o MariaDB 10.6+ compatible) escuchando en local o red accesible.
2. Base de datos vacía dedicada a tests, por ejemplo: `ar_sistemas_test`.
3. Usuario con permisos `CREATE`/`DROP`/`ALTER`/`INDEX`/`REFERENCES` sobre esa base.
4. Variables de entorno (o `.env` local, sin commitear secretos):

```env
RUN_MYSQL_TESTS=1
MYSQL_TEST_HOST=127.0.0.1
MYSQL_TEST_PORT=3306
MYSQL_TEST_DATABASE=ar_sistemas_test
MYSQL_TEST_USERNAME=...
MYSQL_TEST_PASSWORD=...
```

5. PHP 8.4+ con `pdo_mysql`, `bcmath`, `mbstring`, `openssl`.
6. Ejecutar:

```bash
php artisan test --filter=mysql
# o la suite completa con RUN_MYSQL_TESTS=1
php artisan test
```

### Criterio de aprobación

La validación MySQL se considerará aprobada solo cuando:

1. el test MySQL real haya corrido (no skipped);
2. DECIMAL se reporte como `decimal` en el schema;
3. migraciones, FKs, transferencias, rollback y saldos pasen contra MySQL;
4. se documenten y corrijan diferencias encontradas vs SQLite.

## FKs futuras en `movements`

Columnas nullable sin FK aún: `client_id`, `supplier_id`, `work_order_id`, `event_id`, `document_id`.
