<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomerStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'aktiv',
            self::Suspended => 'gesperrt',
        };
    }
}
