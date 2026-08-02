<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Der Zustand eines Abonnements.
 *
 * Der Unterschied zwischen „gesperrt" und „gekündigt" ist kein Etikett: Ein
 * gesperrtes Abonnement behält seine Daten und seinen Systembenutzer und kann
 * jederzeit zurückkommen; ein gekündigtes wartet auf das Aufräumen. Wer beides
 * in ein Feld „inaktiv" wirft, kann später nicht mehr sagen, was gelöscht
 * werden darf.
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    /** Darf in diesem Zustand noch gearbeitet werden? */
    public function usable(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'aktiv',
            self::Suspended => 'gesperrt',
            self::Cancelled => 'gekündigt',
        };
    }
}
