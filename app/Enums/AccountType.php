<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Die drei Ebenen aus §6.1 des Plans.
 *
 * Bewusst kein Rollen- und Rechte-Baukasten: Drei feste Ebenen decken den
 * Bedarf eines Hosting-Panels ab, und was der Zusatzbenutzer darf, steht nicht
 * an seinem Konto, sondern an der Zuordnung zum Abonnement — derselbe Mensch
 * kann in einem Abonnement Dateien schreiben und in einem anderen nur lesen.
 */
enum AccountType: string
{
    case Admin = 'admin';
    case Customer = 'customer';
    case Additional = 'additional';

    /** Sieht dieses Konto den ganzen Server? */
    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }

    /**
     * Hängt dieses Konto an einem Kunden?
     *
     * Für Admins ist `customer_id` leer — und das ist keine Nachlässigkeit,
     * sondern die Bedingung, an der die Mandantenklammer erkennt, dass hier
     * nicht eingeschränkt wird.
     */
    public function belongsToCustomer(): bool
    {
        return $this !== self::Admin;
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Customer => 'Kunde',
            self::Additional => 'Zusatzbenutzer',
        };
    }
}
