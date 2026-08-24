<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Die drei Ebenen aus §6.1 des Plans — die **Mandantenfrage** und sonst nichts.
 *
 * Dieses Enum beantwortet „wen sieht dieses Konto". Es beantwortet nicht „was
 * darf es". Bis zum 24. August 2026 stand hier, das sei dasselbe: „Bewusst kein
 * Rollen- und Rechte-Baukasten: Drei feste Ebenen decken den Bedarf eines
 * Hosting-Panels ab." Der erste Halbsatz gilt weiter, der zweite nicht mehr —
 * der Betreiber hat zwei Verwaltungsrollen entschieden, **Betreiber** und
 * **Administrator**, wobei der zweite verwaltet und Kritisches weder sieht noch
 * bedient. Das Modell steht in `docs/74 §11` (A9); gebaut ist es noch nicht.
 *
 * Für den Zusatzbenutzer gilt der alte Satz unverändert: Was er darf, steht
 * nicht an seinem Konto, sondern an der Zuordnung zum Abonnement — derselbe
 * Mensch kann in einem Abonnement Dateien schreiben und in einem anderen nur
 * lesen.
 *
 * **Und deshalb bekommt dieses Enum keinen vierten Fall.** Beide
 * Verwaltungsrollen sehen den ganzen Server; verschieden ist nur, was sie
 * dürfen. Wer die zweite Achse hier hineinlegt, legt sie in die falsche Frage —
 * und richtet dabei still Schaden an, weil die Methoden unten als *Gleichheit
 * mit einem Fall* geschrieben sind und nicht als Zugehörigkeit zu einer Menge:
 * Ein neuer Fall wäre augenblicklich `isAdmin() === false` und
 * `belongsToCustomer() === true`, an 52 Stellen in `app/` und `routes/`. Die
 * Mandantenklammer setzte ihn auf `whereRaw('0 = 1')`, weil er keinen Kunden
 * hat. Es fiele zur sicheren Seite — und das ist kein Trost: Der neue Betreiber
 * sähe eine leere Kundenliste, und niemand käme auf einen Enum-Fall als
 * Ursache.
 *
 * `AccountTypeAxisTest` steht als Stolperdraht davor.
 */
enum AccountType: string
{
    case Admin = 'admin';
    case Customer = 'customer';
    case Additional = 'additional';

    /**
     * Sieht dieses Konto den ganzen Server?
     *
     * **Das ist keine Aussage darüber, was es darf.** Beide Verwaltungsrollen
     * aus `docs/74 §11` antworten hier `true`; sie unterscheiden sich an den
     * Gates, nicht an dieser Zeile.
     */
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
