<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Suite MySQL: sin RefreshDatabase/SQLite — cada test hace migrate:fresh sobre ar_sistemas_test.
pest()->extend(TestCase::class)
    ->group('mysql')
    ->in('Mysql');

function seedPermissions(): void
{
    test()->seed(RolesAndPermissionsSeeder::class);
}

/**
 * Conecta la app a MySQL de pruebas y deja el schema fresco con seeders.
 * Requiere RUN_MYSQL_TESTS=1 y servidor alcanzable.
 */
function bootMysqlIntegration(): void
{
    if (env('RUN_MYSQL_TESTS') !== '1') {
        test()->markTestSkipped(
            'Suite MySQL: definí RUN_MYSQL_TESTS=1 y credenciales MYSQL_TEST_*. '.
            'Ejecutar: php artisan test --group=mysql'
        );
    }

    config([
        'database.default' => 'mysql',
        'database.connections.mysql.host' => env('MYSQL_TEST_HOST', '127.0.0.1'),
        'database.connections.mysql.port' => env('MYSQL_TEST_PORT', '3306'),
        'database.connections.mysql.database' => env('MYSQL_TEST_DATABASE', 'ar_sistemas_test'),
        'database.connections.mysql.username' => env('MYSQL_TEST_USERNAME', 'root'),
        'database.connections.mysql.password' => env('MYSQL_TEST_PASSWORD', ''),
    ]);

    \Illuminate\Support\Facades\DB::purge('mysql');

    try {
        \Illuminate\Support\Facades\DB::connection('mysql')->getPdo();
    } catch (\Throwable $e) {
        test()->fail(
            'Validación MySQL no ejecutada: no hay servidor MySQL alcanzable. '.
            'Host='.config('database.connections.mysql.host').
            ' Port='.config('database.connections.mysql.port').
            ' DB='.config('database.connections.mysql.database').
            ' Error: '.$e->getMessage().
            ' Ver docs/mysql-validation.md'
        );
    }

    \Illuminate\Support\Facades\DB::reconnect('mysql');
    test()->artisan('migrate:fresh', ['--seed' => true]);

    $admin = \App\Models\User::where('email', 'admin@arsistemas.local')->firstOrFail();
    test()->actingAs($admin);
}

function makeAdmin(array $attributes = []): User
{
    seedPermissions();

    $user = User::factory()->create($attributes);
    $user->assignRole('Administrador');

    return $user;
}

function makeUserWithPermissions(array $permissions, array $attributes = []): User
{
    seedPermissions();

    $user = User::factory()->create($attributes);
    $user->givePermissionTo($permissions);

    return $user;
}
