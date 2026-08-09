<?php

namespace App\Enums;

enum MovementStatus: string
{
    case Posted = 'posted';
    case Voided = 'voided';
}
