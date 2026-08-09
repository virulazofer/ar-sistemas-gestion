# Backups (diseño)

Automatización operativa en Etapa 13. Desde ahora queda definido:

| Elemento | Enfoque |
|----------|---------|
| Base MySQL | `mysqldump` programado |
| Archivos | copia de `storage/app` |
| Retención | 7 diarios + 4 semanales (ajustable) |
| Verificación | restore de prueba periódica |
| Secretos | nunca en el repositorio |

En desarrollo local Etapa 1 (SQLite): respaldar `database/database.sqlite` y `storage/`.
