<?php

namespace App\Enums;

enum WorkOrderStatus: string
{
    case Open = 'open';
    case Diagnosing = 'diagnosing';
    case WaitingApproval = 'waiting_approval';
    case WaitingParts = 'waiting_parts';
    case InRepair = 'in_repair';
    case Ready = 'ready';
    case Delivered = 'delivered';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierta',
            self::Diagnosing => 'En diagnóstico',
            self::WaitingApproval => 'Esperando aprobación',
            self::WaitingParts => 'Esperando repuesto',
            self::InRepair => 'En reparación',
            self::Ready => 'Lista',
            self::Delivered => 'Entregada',
            self::Closed => 'Cerrada',
            self::Cancelled => 'Cancelada',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Closed, self::Cancelled], true);
    }

    public function isEditable(): bool
    {
        return ! $this->isTerminal();
    }
}
