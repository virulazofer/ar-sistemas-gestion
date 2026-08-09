# Permisos

Paquete: `spatie/laravel-permission`.

## Formato

`{area}.{accion}` — ejemplo: `users.create`, `movements.void`.

## Acciones

`view`, `create`, `edit`, `void`, `delete`, `export`, más acciones de dominio (`assemble`, `close`, `generate`, etc. según área).

No todas las áreas exponen todas las acciones (ver `config/permissions.php`).

## Roles semilla

| Rol | Alcance |
|-----|---------|
| Administrador | Todos los permisos |
| Operador | Operatoria cotidiana (sin admin de usuarios/permisos/settings.edit) |
| Consulta | Principalmente view/export |

## Etapa 7 (OT / Abonos)

- `work_orders.view|create|edit|void|close|cancel|consume_stock|charge|export`
- `subscriptions.view|create|edit|void|generate|cancel|export`

## Etapa 8 (Presupuestos / Ventas)

- `quotations.view|create|edit|send|accept|convert|cancel|export`
- `sales.view|create|edit|confirm|void|export`

## Etapa 9 (Reportes / Importaciones)

- `dashboard.view`
- `reports.view|finance|clients|suppliers|stock|sales|profitability|export`
- `imports.view|execute`
- `exports.execute`

## Enforcement

Las rutas protegidas usan middleware `permission:...`. Ocultar UI no alcanza: el backend responde 403.

## Matriz

UI en `/permissions` para editar permisos por rol.
