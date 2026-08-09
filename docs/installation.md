# Instalación local — Etapa 1

## Herramientas usadas en este entorno

En la máquina de desarrollo no había PHP/Composer/Node en PATH. Se instaló un toolchain portable en `C:\CURSOR\tools` (fuera del deploy de producción):

- PHP 8.4.24
- Composer PHAR
- MinGit
- Node 22

## Pasos

1. Configurar `.env` (ver `.env.example`)
2. `php artisan key:generate`
3. Crear SQLite o configurar MySQL
4. `php artisan migrate --seed`
5. `npm install && npm run build`
6. `php artisan serve`

## MySQL

El destino de producción es MySQL. Para usarlo en local:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ar_sistemas_gestion
DB_USERNAME=...
DB_PASSWORD=...
```

Luego `php artisan migrate --seed`.

## Pruebas

```
php artisan test
```
