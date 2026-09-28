<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin  = 'admin';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Admin  => 'Administrador',
            self::Member => 'Miembro',
        };
    }

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }

    public function isMember(): bool
    {
        return $this === self::Member;
    }
}
