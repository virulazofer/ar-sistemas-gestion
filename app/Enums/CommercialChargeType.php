<?php

namespace App\Enums;

enum CommercialChargeType: string
{
    case Subscription = 'subscription';
    case Sale = 'sale';
    case Repair = 'repair';
    case Installation = 'installation';
    case Remote = 'remote';
    case Service = 'service';
    case AuthorizedAdjustment = 'authorized_adjustment';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Subscription => 'Abono',
            self::Sale => 'Venta',
            self::Repair => 'Reparación',
            self::Installation => 'Instalación',
            self::Remote => 'Remoto',
            self::Service => 'Servicio',
            self::AuthorizedAdjustment => 'Ajuste autorizado',
            self::Other => 'Otro',
        };
    }
}
