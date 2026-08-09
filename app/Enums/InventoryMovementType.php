<?php

namespace App\Enums;

enum InventoryMovementType: string
{
    case Receipt = 'receipt';
    case Issue = 'issue';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case Reserve = 'reserve';
    case Release = 'release';
    case Consume = 'consume';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Ingreso',
            self::Issue => 'Salida',
            self::AdjustmentIn => 'Ajuste positivo',
            self::AdjustmentOut => 'Ajuste negativo',
            self::TransferOut => 'Transferencia (salida)',
            self::TransferIn => 'Transferencia (entrada)',
            self::Reserve => 'Reserva',
            self::Release => 'Liberación de reserva',
            self::Consume => 'Consumo',
        };
    }

    public function affectsOnHand(): bool
    {
        return match ($this) {
            self::Reserve, self::Release => false,
            default => true,
        };
    }

    public function onHandSign(): int
    {
        return match ($this) {
            self::Receipt, self::AdjustmentIn, self::TransferIn => 1,
            self::Issue, self::AdjustmentOut, self::TransferOut, self::Consume => -1,
            self::Reserve, self::Release => 0,
        };
    }

    public function reservedSign(): int
    {
        return match ($this) {
            self::Reserve => 1,
            self::Release => -1,
            default => 0,
        };
    }

    public function consumesLots(): bool
    {
        return in_array($this, [
            self::Issue,
            self::AdjustmentOut,
            self::TransferOut,
            self::Consume,
        ], true);
    }

    public function createsLot(): bool
    {
        return in_array($this, [
            self::Receipt,
            self::AdjustmentIn,
            self::TransferIn,
        ], true);
    }
}
