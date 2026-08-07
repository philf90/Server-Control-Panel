<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Der Zustand einer Datenbank.
 *
 * **Kein `Suspended`.** Ob eine Datenbank erreichbar ist, steht am
 * **Benutzer** und nicht am Schema (`docs/36 §6`): Gesperrt wird über
 * `ALTER USER … ACCOUNT LOCK`, und das Schema selbst bleibt unberührt. Ein
 * zweiter Zustand hier wäre eine zweite Antwort auf dieselbe Frage — und die
 * falsche fiele erst auf, wenn jemand ein gesperrtes Abonnement freigibt und
 * eine Datenbank „gesperrt" bleibt, deren Zugänge längst wieder offen sind.
 *
 * **`Removing` steht zwischen dem Absenden und der Antwort des Agenten.** Ohne
 * ihn stünde eine Datenbank in der Liste, als wäre nichts, während ihr
 * `DROP DATABASE` läuft — und ein zweiter Klick reihte einen zweiten Vorgang
 * ein.
 */
enum DatabaseStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Removing = 'removing';

    public function label(): string
    {
        return match ($this) {
            self::Provisioning => 'wird angelegt',
            self::Active => 'aktiv',
            self::Removing => 'wird entfernt',
        };
    }

    /** Darf an dieser Datenbank gerade etwas geändert werden? */
    public function usable(): bool
    {
        return $this === self::Active;
    }

    /**
     * Läuft gerade ein Vorgang?
     *
     * Die Oberfläche zeigt dann keinen Knopf, sondern den Vorgang — dieselbe
     * Frage wie {@see DomainStatus::pending()} und derselbe Zweck: Ein zweiter
     * Auftrag zu derselben Datenbank wäre ein Wettlauf zweier Agent-Läufe.
     */
    public function pending(): bool
    {
        return $this !== self::Active;
    }
}
