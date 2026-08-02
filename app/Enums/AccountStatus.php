<?php

declare(strict_types=1);

namespace App\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';

    public function canSignIn(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'aktiv',
            self::Disabled => 'deaktiviert',
        };
    }
}
