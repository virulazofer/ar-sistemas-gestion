<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Staging/producción: no crear admin predecible. Usar: php artisan app:create-admin
        if (! app()->environment(['local', 'testing'])) {
            if ($this->command) {
                $this->command->warn(
                    'AdminUserSeeder omitido en '.app()->environment().
                    '. Creá el administrador con: php artisan app:create-admin'
                );
            }

            return;
        }

        // Solo local/testing: facilita desarrollo y suites (migrate --seed / MySQL group).
        $password = (string) env('ADMIN_SEED_PASSWORD', 'password');

        $admin = User::query()->updateOrCreate(
            ['email' => env('ADMIN_SEED_EMAIL', 'admin@arsistemas.local')],
            [
                'name' => env('ADMIN_SEED_NAME', 'Administrador'),
                'username' => env('ADMIN_SEED_USERNAME', 'admin'),
                'password' => $password,
                'status' => User::STATUS_ACTIVE,
                'theme' => User::THEME_LIGHT,
                'email_verified_at' => now(),
            ]
        );

        $admin->syncRoles(['Administrador']);
    }
}
