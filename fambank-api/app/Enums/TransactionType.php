<?php

namespace App\Enums;

enum TransactionType: string
{
    case Deposit    = 'deposit';
    case Withdrawal = 'withdrawal';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Deposit    => 'Depósito',
            self::Withdrawal => 'Retiro',
            self::Adjustment => 'Ajuste',
        };
    }

    /** Indica si el tipo suma saldo (depósito) o lo resta (retiro). Adjustment es neutro. */
    public function affectsBalance(): int
    {
        return match ($this) {
            self::Deposit    =>  1,
            self::Withdrawal => -1,
            self::Adjustment =>  0,
        };
    }
}
