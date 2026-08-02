<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Account;

/**
 * Wer gerade welche Abonnements sehen darf.
 *
 * Das ist die erste der vier Schichten aus §6.2, und sie ist die einzige, die
 * auch dann noch greift, wenn jemand eine `where`-Bedingung vergisst.
 *
 * **Der Grundzustand ist „nichts".** Nicht „alles", nicht „das erste
 * Abonnement" — nichts. Ein Kommando, ein Job, ein Test, ein neuer Controller:
 * Solange niemand einen Mandanten gesetzt hat, liefert jede mandantengebundene
 * Abfrage eine leere Menge. Das ist unbequem und genau deshalb richtig. Der
 * umgekehrte Grundzustand hätte die Eigenschaft, dass ein vergessener Aufruf
 * nicht auffällt, solange nur ein Kunde im System ist — und dann auffällt,
 * wenn der zweite dazukommt.
 *
 * **Drei Zustände, ausdrücklich unterschieden:**
 *
 * - *nichts* (Grundzustand) — jede Abfrage ist leer.
 * - *eingeschränkt* auf eine Liste von Abonnement-IDs — der Normalfall für
 *   Kunden und Zusatzbenutzer.
 * - *unbeschränkt* — der Admin. Muss ausdrücklich gesetzt werden, damit im
 *   Code sichtbar ist, wo die Klammer absichtlich offen steht.
 */
final class Tenancy
{
    /** @var list<int>|null Null heißt: keine Einschränkung gesetzt. */
    private ?array $subscriptionIds = null;

    private bool $unrestricted = false;

    /**
     * Der Admin sieht alles.
     *
     * Kein Automatismus über den Kontotyp: Diese Methode wird an genau den
     * Stellen aufgerufen, an denen die Klammer offen stehen soll, und ist
     * dadurch auffindbar.
     */
    public function allowAll(): void
    {
        $this->unrestricted = true;
        $this->subscriptionIds = null;
    }

    /**
     * @param  list<int>  $subscriptionIds
     */
    public function restrictTo(array $subscriptionIds): void
    {
        $this->unrestricted = false;
        $this->subscriptionIds = array_values(array_unique(array_map(intval(...), $subscriptionIds)));
    }

    /**
     * Den Mandanten aus einem Konto ableiten.
     *
     * Für Zusatzbenutzer sind es die ausdrücklich zugewiesenen Abonnements,
     * für Kunden alle des eigenen Kundenkontos — und für Kunden mit
     * Unterkunden (später, §5.4) über die Zugehörigkeitskette auch deren.
     * Genau deshalb steht hier eine Abfrage und kein `where customer_id = ?`.
     */
    public function forAccount(Account $account): void
    {
        if ($account->type->isAdmin()) {
            $this->allowAll();

            return;
        }

        $this->restrictTo($account->accessibleSubscriptionIds());
    }

    /** Zurück in den Grundzustand: nichts ist sichtbar. */
    public function reset(): void
    {
        $this->unrestricted = false;
        $this->subscriptionIds = null;
    }

    public function unrestricted(): bool
    {
        return $this->unrestricted;
    }

    /**
     * Die sichtbaren Abonnements, oder eine leere Liste im Grundzustand.
     *
     * @return list<int>
     */
    public function subscriptionIds(): array
    {
        return $this->subscriptionIds ?? [];
    }

    public function isSet(): bool
    {
        return $this->unrestricted || $this->subscriptionIds !== null;
    }

    /**
     * Etwas ohne Mandantenklammer ausführen.
     *
     * Für Ersteinrichtung, Wartungskommandos und den Vorgangs-Arbeiter, der
     * einen Auftrag erst laden muss, um zu wissen, zu wem er gehört. Der
     * vorige Zustand wird wiederhergestellt, auch wenn der Rückruf wirft —
     * ohne das würde ein Fehler mitten in einem Kommando die Klammer für den
     * Rest des Prozesses offen lassen.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function withoutRestriction(callable $callback): mixed
    {
        $previousIds = $this->subscriptionIds;
        $previousUnrestricted = $this->unrestricted;

        $this->allowAll();

        try {
            return $callback();
        } finally {
            $this->subscriptionIds = $previousIds;
            $this->unrestricted = $previousUnrestricted;
        }
    }
}
