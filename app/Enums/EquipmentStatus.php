<?php

namespace App\Enums;

enum EquipmentStatus: string
{
    case Assembled = 'assembled';
    case Available = 'available';
    case Reserved = 'reserved';
    case Delivered = 'delivered';
    case InRepair = 'in_repair';
    case OutOfService = 'out_of_service';
    case Disassembled = 'disassembled';
    case Sold = 'sold';

    public function label(): string
    {
        return match ($this) {
            self::Assembled => 'Armado',
            self::Available => 'Disponible',
            self::Reserved => 'Reservado',
            self::Delivered => 'Entregado',
            self::InRepair => 'En reparación',
            self::OutOfService => 'Fuera de servicio',
            self::Disassembled => 'Desarmado',
            self::Sold => 'Vendido',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Assembled => [self::Available, self::Reserved, self::InRepair, self::Disassembled],
            self::Available => [self::Reserved, self::Delivered, self::InRepair, self::OutOfService, self::Sold, self::Disassembled],
            self::Reserved => [self::Available, self::Delivered, self::Sold, self::Disassembled],
            self::Delivered => [self::Available, self::InRepair, self::Sold],
            self::InRepair => [self::Available, self::OutOfService, self::Disassembled],
            self::OutOfService => [self::Available, self::Disassembled, self::Sold],
            self::Sold => [], // desarmado solo vía procedimiento admin explícito
            self::Disassembled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function allowsDisassembly(bool $adminOverride = false): bool
    {
        if ($this === self::Disassembled) {
            return false;
        }
        if (in_array($this, [self::Sold, self::Delivered], true)) {
            return $adminOverride;
        }

        return true;
    }
}
