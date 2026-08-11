<?php

namespace App\Enums;

enum ImportReviewStatus: string
{
    case Green = 'green';
    case Inferred = 'inferred';
    case Corrected = 'corrected';
    case Yellow = 'yellow';
    case Red = 'red';
    case PendingComplete = 'pending_complete';
    case Excluded = 'excluded';
    case Accepted = 'accepted';

    public function label(): string
    {
        return match ($this) {
            self::Green => 'Verde',
            self::Inferred => 'Inferido',
            self::Corrected => 'Corregido',
            self::Yellow => 'Amarillo',
            self::Red => 'Rojo',
            self::PendingComplete => 'Pendiente de completar',
            self::Excluded => 'Excluido',
            self::Accepted => 'Aceptado',
        };
    }

    /** Listo para importar (no requiere decisión humana adicional). */
    public function isImportReady(): bool
    {
        return match ($this) {
            self::Green, self::Inferred, self::Corrected, self::Accepted => true,
            default => false,
        };
    }
}
