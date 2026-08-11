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
            self::Product => 'Producto / Unidad de stock',
            self::Equipment => 'Equipo armado',
            self::Service => 'Servicio',
            self::Labor => 'Mano de obra',
            self::WorkOrder => 'OT',
            self::Free => 'Concepto libre',
            self::BuildToOrder => 'Equipo a fabricar',
            self::Subscription => 'Abono',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::Product => 'Producto (definición) / unidad física de stock consumida por FIFO.',
            self::Equipment => 'Equipo ya armado (PC, etc.) con componentes y seriales; no re-consume stock.',
            self::Service => 'Servicio o remoto (no mueve stock). Puede ir sin OT.',
            self::Labor => 'Mano de obra / horas.',
            self::WorkOrder => 'Referencia opcional a una orden de trabajo.',
            self::Free => 'Concepto libre descriptivo, sin catálogo.',
            self::BuildToOrder => 'Plantilla a fabricar; el presupuesto no fabrica ni mueve stock.',
            self::Subscription => 'Abono / cargo recurrente.',
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
