<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Der Zustand einer Domain — und er folgt dem Agenten, nicht dem Klick.
 *
 * Dieselbe Aufteilung wie bei {@see SubscriptionStatus}, aus demselben Grund:
 * Zwischen dem Absenden des Formulars und der Antwort des Agenten gibt es
 * einen Zeitraum, in dem die Domain im Panel steht und auf dem Server noch
 * nichts von ihr weiß. Ohne einen eigenen Zustand dafür stünde sie als „aktiv"
 * da, und der erste Aufruf im Browser liefe ins Leere.
 *
 * **`Removing` ist der Zustand, den es ohne die zweite Grenze aus CLAUDE.md
 * nicht gäbe.** Eine Domain wird gelöscht, indem der Agent ihren vhost, ihren
 * Pool-Eintrag, ihre Protokolle und ihr Verzeichnis entfernt — und erst
 * *danach* verschwindet die Zeile. Bis dahin muss sie sichtbar bleiben, sonst
 * sieht der Betreiber ein Verzeichnis auf der Platte, zu dem das Panel nichts
 * mehr sagen kann.
 */
enum DomainStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Removing = 'removing';

    public function label(): string
    {
        return match ($this) {
            self::Provisioning => 'wird angelegt',
            self::Active => 'aktiv',
            self::Suspended => 'gesperrt',
            self::Removing => 'wird entfernt',
        };
    }

    /** Darf an dieser Domain gerade etwas geändert werden? */
    public function usable(): bool
    {
        return $this === self::Active;
    }

    /**
     * Läuft an dieser Domain gerade ein Vorgang?
     *
     * Die Oberfläche zeigt in diesem Fall keinen Knopf, sondern den Vorgang.
     * Ein zweiter Auftrag zur selben Domain wäre ein Wettlauf zweier
     * Agent-Läufe um dieselbe Konfigurationsdatei.
     */
    public function pending(): bool
    {
        return $this === self::Provisioning || $this === self::Removing;
    }
}
