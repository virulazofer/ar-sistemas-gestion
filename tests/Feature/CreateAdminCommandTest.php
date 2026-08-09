<?php

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

test('app:create-admin crea administrador con rol', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->artisan('app:create-admin', [
        '--name' => 'Admin Seguro',
        '--username' => 'adminseguro',
        '--email' => 'admin.seguro@example.com',
        '--password' => 'SecurePass123!',
    ])->assertSuccessful();

    $user = User::query()->where('email', 'admin.seguro@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->hasRole('Administrador'))->toBeTrue();
    expect($user->username)->toBe('adminseguro');
});

test('AdminUserSeeder no crea admin predecible fuera de local/testing', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->app['env'] = 'staging';

    $this->seed(AdminUserSeeder::class);

    expect(User::query()->where('email', 'admin@arsistemas.local')->exists())->toBeFalse();
});
