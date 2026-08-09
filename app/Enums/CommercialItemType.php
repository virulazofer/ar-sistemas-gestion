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

    public function help(): string
    {
        return match ($this) {
            self::Product => 'Producto físico de stock (FIFO).',
            self::Equipment => 'Equipo ya armado/serializado existente y disponible.',
            self::Service => 'Trabajo o servicio (no mueve stock de productos).',
            self::Labor => 'Mano de obra / horas.',
            self::WorkOrder => 'Referencia a una orden de trabajo.',
            self::Free => 'Ítem descriptivo libre, sin catálogo.',
            self::BuildToOrder => 'Tipo/plantilla a fabricar; no fabrica automáticamente al presupuestar.',
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
