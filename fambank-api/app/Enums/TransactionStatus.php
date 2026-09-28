<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case Pending   = 'pending';
    case Confirmed = 'confirmed';
    case Rejected  = 'rejected';   // rechazada por el admin
    case Cancelled = 'cancelled';  // cancelada por el propio member

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'Pendiente',
            self::Confirmed => 'Confirmada',
            self::Rejected  => 'Rechazada',
            self::Cancelled => 'Cancelada',
        };
    }

    /** Estado terminal: ya no puede cambiar de estado. */
    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
