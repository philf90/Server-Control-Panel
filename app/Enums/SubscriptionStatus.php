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
    /**
     * Angelegt im Panel, noch nicht auf dem System.
     *
     * Der Zustand zwischen dem Absenden des Formulars und dem Ende von
     * `subscription.provision`. Er ist nicht Zierde: Ohne ihn stünde ein
     * Abonnement in der Liste als „aktiv", während es weder Systembenutzer
     * noch Verzeichnis hat — und die erste Aktion darin scheiterte mit einer
     * Meldung über einen fehlenden Pfad. `usable()` ist hier `false`, damit
     * die Policy gar nicht erst hineinlässt.
     */
    case Provisioning = 'provisioning';

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
            self::Provisioning => 'wird angelegt',
            self::Active => 'aktiv',
            self::Suspended => 'gesperrt',
            self::Cancelled => 'gekündigt',
        };
    }
}
