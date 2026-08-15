<?php

namespace App\Enums;

enum DocumentOptimizationStatus: string
{
    case Pending = 'pending';
    case Optimized = 'optimized';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case KeepOriginal = 'keep_original';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente de optimización',
            self::Optimized => 'Optimizado',
            self::Failed => 'Pendiente de optimización',
            self::Skipped => 'Sin optimizar',
            self::KeepOriginal => 'Conservar original',
        };
    }
}
