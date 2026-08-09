<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;
use Throwable;

class CreateAdminCommand extends Command
{
    protected $signature = 'app:create-admin
                            {--name= : Nombre completo}
                            {--username= : Nombre de usuario}
                            {--email= : Email}
                            {--password= : Contraseña (preferí el prompt interactivo)}';

    protected $description = 'Crea el primer usuario administrador sin hardcodear credenciales.';

    public function handle(): int
    {
        if (! Role::query()->where('name', 'Administrador')->exists()) {
            $this->error('No existe el rol Administrador. Ejecutá primero: php artisan db:seed --class=RolesAndPermissionsSeeder');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: $this->ask('Nombre');
        $username = $this->option('username') ?: $this->ask('Username');
        $email = $this->option('email') ?: $this->ask('Email');

        $password = $this->option('password');
        if ($password) {
            $this->warn('Se recibió --password por CLI. Preferí el prompt interactivo para no dejarla en el historial del shell.');
            $confirmation = $password;
        } else {
            $password = $this->secret('Contraseña');
            $confirmation = $this->secret('Confirmación de contraseña');
        }

        $validator = Validator::make(
            [
                'name' => $name,
                'username' => $username,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $confirmation,
            ],
            [
                'name' => ['required', 'string', 'max:120'],
                'username' => ['required', 'string', 'max:60', 'alpha_dash', 'unique:users,username'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ],
            [],
            [
                'name' => 'nombre',
                'username' => 'username',
                'email' => 'email',
                'password' => 'contraseña',
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $data = $validator->validated();

        try {
            $user = User::query()->create([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => $data['password'],
                'status' => User::STATUS_ACTIVE,
                'theme' => User::THEME_LIGHT,
                'email_verified_at' => now(),
            ]);

            $user->syncRoles(['Administrador']);
        } catch (Throwable $e) {
            $this->error('No se pudo crear el administrador: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Administrador creado correctamente.');
        $this->line('Email: '.$user->email);
        $this->line('Username: '.$user->username);
        $this->comment('La contraseña no se muestra ni se almacena en texto plano.');

        return self::SUCCESS;
    }
}
