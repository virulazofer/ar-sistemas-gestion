<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    public function log(
        string $action,
        ?Model $entity = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'user_id' => Auth::id(),
            'action' => $action,
            'entity_type' => $entity ? $entity::class : null,
            'entity_id' => $entity?->getKey(),
            'old_values' => $this->sanitize($oldValues),
            'new_values' => $this->sanitize($newValues),
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'description' => $description,
        ]);
    }

    private function sanitize(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        unset($values['password'], $values['remember_token']);

        foreach ($values as $key => $value) {
            $keyLower = strtolower((string) $key);
            if (preg_match('/(binary|base64|content|payload|file_contents|image_data)/', $keyLower)) {
                unset($values[$key]);
                continue;
            }
            if (is_string($value) && strlen($value) > 2000) {
                $values[$key] = '[omitted:'.strlen($value).' chars]';
            }
        }

        return $values;
    }
}
