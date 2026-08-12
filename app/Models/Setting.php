<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

#[Fillable(['key', 'value', 'type', 'group', 'label', 'description'])]
class Setting extends Model
{
    /**
     * Cache solo arrays serializables. Nunca Eloquent: con CACHE_STORE=database
     * deserializar modelos produce __PHP_Incomplete_Class → HTTP 500 (p.ej. /plan-de-cuentas/mapeo).
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $cacheKey = "setting.{$key}";
        $payload = Cache::get($cacheKey);

        // Bust legacy caches that stored the Eloquent model (or incomplete class).
        if (is_object($payload) || ($payload !== null && ! is_array($payload))) {
            Cache::forget($cacheKey);
            $payload = null;
        }

        if (! is_array($payload)) {
            $setting = static::query()->where('key', $key)->first();
            $payload = $setting === null
                ? ['_miss' => true]
                : [
                    'type' => (string) $setting->type,
                    'value' => $setting->value,
                ];
            Cache::put($cacheKey, $payload, 300);
        }

        if (! empty($payload['_miss'])) {
            return $default;
        }

        return match ($payload['type'] ?? 'string') {
            'bool', 'boolean' => filter_var($payload['value'] ?? null, FILTER_VALIDATE_BOOLEAN),
            'int', 'integer' => (int) ($payload['value'] ?? 0),
            'json' => json_decode($payload['value'] ?? 'null', true),
            default => $payload['value'] ?? $default,
        };
    }

    public static function setValue(string $key, mixed $value, string $type = 'string'): void
    {
        $stored = match ($type) {
            'bool', 'boolean' => $value ? '1' : '0',
            'json' => json_encode($value),
            default => (string) $value,
        };

        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $stored, 'type' => $type]
        );

        Cache::forget("setting.{$key}");
    }
}
