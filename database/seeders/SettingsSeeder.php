<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            [
                'key' => 'app.display_name',
                'value' => 'AR Sistemas - Gestión',
                'type' => 'string',
                'group' => 'general',
                'label' => 'Nombre visible',
                'description' => 'Nombre mostrado en la interfaz.',
            ],
            [
                'key' => 'app.default_theme',
                'value' => 'light',
                'type' => 'string',
                'group' => 'apariencia',
                'label' => 'Tema predeterminado',
                'description' => 'Tema inicial para nuevos usuarios (Claro u Oscuro).',
            ],
            [
                'key' => 'app.timezone',
                'value' => 'America/Argentina/Buenos_Aires',
                'type' => 'string',
                'group' => 'general',
                'label' => 'Zona horaria',
                'description' => 'Zona horaria operativa del sistema.',
            ],
        ];

        foreach ($defaults as $setting) {
            Setting::query()->updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}
