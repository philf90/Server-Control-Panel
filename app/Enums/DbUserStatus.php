<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Der Zustand eines Datenbankbenutzers.
 *
 * **Hier steht die Sperre, und nur hier** (`docs/36 §6`). Ein gesperrtes
 * Abonnement erreicht seine Datenbanken über `ALTER USER … ACCOUNT LOCK`; das
 * Schema bleibt unberührt, die Daten bleiben, der Zugang ist zu. `Unlock` ist
 * die vollständige Umkehrung — ein `REVOKE` wäre es nicht, weil es sich merken
 * müsste, was es weggenommen hat.
 *
 * **Kein `Provisioning`.** Ein Benutzer entsteht in einem unmittelbaren Aufruf
 * und nicht über die Warteschlange, weil er ein Passwort trägt (`docs/36 §4`).
 * Wenn seine Zeile existiert, hat der Agent geantwortet — es gibt keinen
 * Zeitraum dazwischen, den ein Zustand beschreiben müsste.
 */
enum DbUserStatus: string
{
    case Active = 'active';
    case Locked = 'locked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'aktiv',
            self::Locked => 'gesperrt',
        };
    }
}
