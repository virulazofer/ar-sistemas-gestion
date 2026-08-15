# Scheduler (Laravel)

Definido en `routes/console.php`.

| Comando | Frecuencia | Propósito |
|---------|------------|-----------|
| `php artisan exchange-rates:update` | `hourly` | Cotización oficial actual (DolarAPI) → histórico local |
| `php artisan subscriptions:generate` | `daily` | Cargos de abonos vencidos (idempotente) |
| `php artisan documents:cleanup-temp` | `daily` | Temporales de captura documental (34B) |

## Cron en el servidor (etapa posterior — no crear todavía)

```cron
* * * * * cd /www/boscacci.com.ar/ar.boscacci.com.ar && php artisan schedule:run >> /dev/null 2>&1
```

Usar el binario PHP CLI del hosting que disponga de las extensiones necesarias.

## Notas

- `withoutOverlapping()` evita corridas concurrentes del mismo comando.
- La cotización se acumula en `exchange_rates` (no sobrescribe histórico).
- Los abonos no generan cargos duplicados por `period_key`.
