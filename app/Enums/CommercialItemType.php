<?php

namespace App\Enums;

enum CommercialItemType: string
{
    case Product = 'product';
    case Equipment = 'equipment';
    case Service = 'service';
    case Labor = 'labor';
    case WorkOrder = 'work_order';
    case Free = 'free';
    case BuildToOrder = 'build_to_order';
    case Subscription = 'subscription';

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Producto',
            self::Equipment => 'Equipo',
            self::Service => 'Servicio',
            self::Labor => 'Mano de obra',
            self::WorkOrder => 'OT',
            self::Free => 'Concepto libre',
            self::BuildToOrder => 'Equipo a fabricar',
            self::Subscription => 'Abono',
        };
    }

    public function consumesStock(): bool
    {
        return $this === self::Product;
    }

    public function sellsEquipment(): bool
    {
        return $this === self::Equipment;
    }
}
