<?php

namespace App\Enums;

enum MovementScope: string
{
    case Personal = 'personal';
    case Professional = 'professional';

    public function label(): string
    {
        return config('finance.scopes.'.$this->value, $this->value);
    }
}
