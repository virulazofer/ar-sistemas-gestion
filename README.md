# AR Sistemas - Gestión

Sistema de gestión personal + profesional IT.

## Estado

Aplicación lista para preparar staging (Etapa 11A).

Documentación: [`docs/`](docs/README.md)

## Inicio rápido (local)

```powershell
cd "C:\CURSOR\Sistema de Gestion"
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

### Administrador

- **Local / testing:** `php artisan migrate --seed` puede crear un admin de desarrollo vía `AdminUserSeeder` (solo en esos entornos). Opcional: definir `ADMIN_SEED_*` en `.env`.
- **Staging / producción:** no uses el seeder de admin. Creá el usuario con:

```bash
php artisan app:create-admin
```

Ingresá nombre, username, email y contraseña de forma interactiva. La contraseña no se imprime ni queda en el repositorio.

Tras el login en local vas a **Carga rápida** de movimientos (si tenés permiso).

## Scheduler

Comandos programados en `routes/console.php`:

| Comando | Frecuencia |
|---------|------------|
| `exchange-rates:update` | cada hora |
| `subscriptions:generate` | diario |

En el servidor (etapa posterior), el cron debe ejecutar:

```bash
* * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

## Tests

```bash
php artisan test
```

Suite MySQL (opcional, aislada): `php artisan test --group=mysql`
