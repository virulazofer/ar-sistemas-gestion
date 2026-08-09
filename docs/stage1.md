# Stage 1 changelog

## Migraciones creadas

- `2026_08_07_225659_create_permission_tables.php` (Spatie)
- `2026_08_07_225731_create_settings_table.php`
- `2026_08_07_225732_create_audit_logs_table.php`
- `2026_08_07_225733_add_stage1_fields_to_users_table.php` (`username`, `status`, `theme`)

## Seeders

- `RolesAndPermissionsSeeder`
- `SettingsSeeder`
- `AdminUserSeeder`

## Principales archivos de aplicación

- `config/permissions.php`
- `app/Services/AuditLogger.php`
- `app/Models/{User,Setting,AuditLog}.php`
- Controllers: User, RolePermission, Setting, Theme, AuditLog
- Vistas: layout, dashboard, users, permissions matrix, settings, audit
- Tests: `tests/Feature/Stage1CoreTest.php`
