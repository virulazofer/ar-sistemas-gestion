<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Capturado = 'CAPTURADO';
    case PendienteDeAnalisis = 'PENDIENTE_DE_ANALISIS';
    case Analizado = 'ANALIZADO';
    case Asociado = 'ASOCIADO';
    case Descartado = 'DESCARTADO';

    public function label(): string
    {
        return match ($this) {
            self::Capturado => 'Capturado',
            self::PendienteDeAnalisis => 'Pendiente de análisis',
            self::Analizado => 'Analizado',
            self::Asociado => 'Asociado',
            self::Descartado => 'Descartado',
        };
    }
}
