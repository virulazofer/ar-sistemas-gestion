<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 11F-8: no bloquear admins existentes al activar verificación de correo.
 * Traduce labels de configuración residuales.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'email_verified_at')) {
            DB::table('users')
                ->whereNull('email_verified_at')
                ->update(['email_verified_at' => now()]);
        }

        if (Schema::hasTable('settings')) {
            $updates = [
                'app.default_theme' => [
                    'label' => 'Tema predeterminado',
                    'description' => 'Tema inicial para nuevos usuarios (Claro u Oscuro).',
                    'group' => 'apariencia',
                ],
                'app.display_name' => [
                    'label' => 'Nombre visible',
                    'description' => 'Nombre mostrado en la interfaz.',
                    'group' => 'general',
                ],
                'app.timezone' => [
                    'label' => 'Zona horaria',
                    'description' => 'Zona horaria operativa del sistema.',
                    'group' => 'general',
                ],
            ];

            foreach ($updates as $key => $payload) {
                DB::table('settings')->where('key', $key)->update($payload);
            }

            DB::table('settings')
                ->where('group', 'appearance')
                ->update(['group' => 'apariencia']);
        }
    }

    public function down(): void
    {
        // Irreversible a propósito: verificación de correo y labels ES se conservan.
    }
};
