<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

#[Fillable(['key', 'value', 'type', 'group', 'label', 'description'])]
class Setting extends Model
{
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = Cache::remember("setting.{$key}", 300, fn () => static::query()->where('key', $key)->first());

        if (! $setting) {
            return $default;
        }

        return match ($setting->type) {
            'bool', 'boolean' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
            'int', 'integer' => (int) $setting->value,
            'json' => json_decode($setting->value ?? 'null', true),
            default => $setting->value ?? $default,
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
