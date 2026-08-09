<?php

namespace App\Enums;

enum TaxCondition: string
{
    case ResponsableInscripto = 'responsable_inscripto';
    case Monotributista = 'monotributista';
    case Exento = 'exento';
    case ConsumidorFinal = 'consumidor_final';
    case NoResponsable = 'no_responsable';
    case Otra = 'otra';

    public function label(): string
    {
        return match ($this) {
            self::ResponsableInscripto => 'Responsable Inscripto',
            self::Monotributista => 'Monotributista',
            self::Exento => 'Exento',
            self::ConsumidorFinal => 'Consumidor Final',
            self::NoResponsable => 'No Responsable',
            self::Otra => 'Otra',
        };
    }
}
