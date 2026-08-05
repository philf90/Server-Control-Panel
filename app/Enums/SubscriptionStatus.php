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

    /**
     * Dieselbe Frage als Werteliste — für eine Abfrage.
     *
     * **Abgeleitet und nicht abgeschrieben.** Ein `whereIn('status',
     * ['active'])` in einem Controller wäre eine zweite Fassung von
     * {@see usable()}, und beim nächsten benutzbaren Zustand zöge nur eine von
     * beiden mit. Hier fällt die Liste aus der Methode heraus, die die Frage
     * ohnehin beantwortet.
     *
     * @return list<string>
     */
    public static function usableValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->usable()),
        ));
    }

    /**
     * Die Beschriftung — für eine Spalte, ein Abzeichen, eine Auswahl.
     *
     * Sie steht für sich allein und ist **kein Satzteil**. Wer sie in einen
     * Satz einsetzt, bekommt Deutsch wie „Das Abonnement ist wird angelegt" —
     * genau das ist passiert, und zwar erst auf dem Server im Abnahmelauf,
     * weil in diesem Zustand sonst niemand eine Domain anlegt. Für einen Satz
     * gibt es {@see self::sentence()}.
     */
    public function label(): string
    {
        return match ($this) {
            self::Provisioning => 'wird angelegt',
            self::Active => 'aktiv',
            self::Suspended => 'gesperrt',
            self::Cancelled => 'gekündigt',
        };
    }

    /**
     * Derselbe Zustand als Aussage über das Abonnement.
     *
     * Das Verb steht hier drin und nicht im Satzrahmen des Aufrufers: „ist"
     * passt zu drei Zuständen und zum vierten nicht, und ein Rahmen, der für
     * die meisten Fälle stimmt, ist der Grund, warum der eine übrige erst
     * beim Kunden auffällt.
     */
    public function sentence(): string
    {
        return match ($this) {
            self::Provisioning => 'wird gerade angelegt',
            self::Active => 'ist aktiv',
            self::Suspended => 'ist gesperrt',
            self::Cancelled => 'ist gekündigt',
        };
    }
}
