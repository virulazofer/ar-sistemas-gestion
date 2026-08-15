# AR Sistemas - Gestión

Aplicación web de gestión personal y profesional IT.

## Etapa actual

**Etapa 9 — Dashboard, reportes e importaciones** implementada en desarrollo local.

## Documentación

- [Arquitectura](architecture.md)
- [Finanzas](finance.md)
- [Clientes / CC](clients.md)
- [Proveedores / Compras](purchases.md)
- [Stock / FIFO](stock.md)
- [Equipos armados](equipment.md)
- [OT / Abonos](work-orders.md)
- [Presupuestos / Ventas](sales.md)
- [Reportes / Importaciones](reports-imports.md)
- [11E Pendientes de carga (futuro)](stage11e-pendientes-de-carga.md)
- [Validación MySQL](mysql-validation.md)
- [Documentos / PWA 12A](documents-pwa.md)
- [Security review 12A](stage12a-security-review.md)
- [Scheduler](scheduler.md)
- [Permisos](permissions.md)
- [FIFO / stock futuro](fifo-inventory-design.md)
- [Backups](backups.md)
- [Instalación](installation.md)
- [Etapa 1](stage1.md)
- [Etapa 2](stage2.md)
- [Etapa 3](stage3.md)
- [Etapa 4](stage4.md)
- [Etapa 5](stage5.md)
- [Etapa 6](stage6.md)
- [Etapa 7](stage7.md)
- [Etapa 8](stage8.md)
- [Etapa 9](stage9.md)

- PHP 8.4+
- Composer
- Node.js 20+ (assets Vite)
- MySQL 8.x (destino de producción)
- SQLite permitido solo para desarrollo/pruebas locales de Etapa 1

## Instalación local

```bash
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # si usás SQLite
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

Usuario administrador:

- **Local/testing:** `php artisan migrate --seed` puede crear un admin de desarrollo (`AdminUserSeeder` solo en esos entornos). Opcionalmente configurá `ADMIN_SEED_*` en `.env`.
- **Staging/producción:** `php artisan app:create-admin` (interactivo). No uses contraseñas de desarrollo ni las documentes.
## Documentación

- [Arquitectura](architecture.md)
- [Permisos](permissions.md)
- [FIFO / stock futuro](fifo-inventory-design.md)
- [Backups](backups.md)
- [Instalación](installation.md)
