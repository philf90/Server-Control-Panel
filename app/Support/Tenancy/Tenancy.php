<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Account;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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

    /**
     * Die Domain-Einschränkung je Abonnement — für Zusatzbenutzer (§6.1).
     *
     * **Warum eine Zuordnung und keine flache Liste.** Ein Zusatzbenutzer kann
     * in einem Abonnement auf zwei Domains beschränkt sein und in einem
     * zweiten uneingeschränkt arbeiten. Eine flache Liste erlaubter Domain-IDs
     * könnte das nicht ausdrücken: Sie hätte im zweiten Abonnement entweder
     * alles verborgen oder hätte dort einmal ermittelte IDs festgehalten — und
     * eine Domain, die danach entsteht, wäre für ihn unsichtbar geblieben, bis
     * sich jemand daran erinnert, die Liste neu zu bauen.
     *
     * **Der Grundzustand ist hier `[]` und heißt „keine Einschränkung" — nicht
     * „nichts sichtbar".** Das ist die Umkehrung dessen, was für Abonnements
     * gilt, und der Grund liegt eine Ebene höher: Wer kein Abonnement sieht,
     * sieht auch keine Domain darin. Die Verweigerung im Grundzustand hat
     * bereits stattgefunden; eine zweite an derselben Kette würde nichts
     * zusätzlich schützen, aber jede Abfrage eines Kunden leer laufen lassen,
     * solange niemand ausdrücklich „alle Domains" sagt.
     *
     * @var array<int, list<int>> Abonnement-ID => erlaubte Domain-IDs
     */
    private array $domainRestrictions = [];

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
        $this->domainRestrictions = [];
    }

    /**
     * **Beide Einschränkungen in einem Aufruf**, und das ist kein Komfort.
     *
     * Hier stand zuerst eine zweite Methode `restrictDomains()`. Damit hing
     * die Domain-Einschränkung daran, dass ein Aufrufer sie nach
     * `restrictTo()` auch setzt — und wer sie vergisst, bekommt keinen
     * Fehler, sondern einen Zusatzbenutzer, der alle Domains seines
     * Abonnements sieht statt der beiden, die ihm zugewiesen sind. Ein
     * vergessener zweiter Aufruf ist genau die Sorte Lücke, die niemandem
     * auffällt, weil alles funktioniert.
     *
     * @param  list<int>  $subscriptionIds
     * @param  array<int, list<int>>  $domainRestrictions  Abonnement-ID => erlaubte Domain-IDs
     */
    public function restrictTo(array $subscriptionIds, array $domainRestrictions = []): void
    {
        $this->unrestricted = false;
        $this->subscriptionIds = array_values(array_unique(array_map(intval(...), $subscriptionIds)));

        $restrictions = [];

        foreach ($domainRestrictions as $subscriptionId => $domainIds) {
            $restrictions[(int) $subscriptionId] = array_values(array_unique(array_map(intval(...), $domainIds)));
        }

        $this->domainRestrictions = $restrictions;
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

        $this->restrictTo(
            $account->accessibleSubscriptionIds(),
            $account->domainRestrictions(),
        );
    }

    /** Zurück in den Grundzustand: nichts ist sichtbar. */
    public function reset(): void
    {
        $this->unrestricted = false;
        $this->subscriptionIds = null;
        $this->domainRestrictions = [];
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
     * Die Domain-Einschränkungen, leer bei „keine".
     *
     * @return array<int, list<int>>
     */
    public function domainRestrictions(): array
    {
        return $this->domainRestrictions;
    }

    /**
     * Die zweite Klammer: nur bestimmte Domains innerhalb eines Abonnements.
     *
     * Sie steht hier und nicht im Modell, weil ab P4 Zertifikate und ab P9
     * Statistiken an einer Domain hängen — dieselbe Bedingung, nur mit
     * `domain_id` statt `id` als Spalte. Wer sie dort abschreibt, schreibt
     * beim dritten Mal etwas leicht anderes.
     *
     * **Die Bedingung ist bewusst „nicht eingeschränkt ODER erlaubt".** Ein
     * schlichtes `whereIn(domain_id, …)` hätte die Abonnements ohne
     * Einschränkung mit ausgeschlossen — der Zusatzbenutzer sähe dort nichts
     * mehr, obwohl er dort alles darf.
     *
     * **`covariant`, weil der Aufrufer einen engeren Erbauer hat.** Im
     * globalen Filter von {@see Domain} ist es ein `Builder<Domain>`, und
     * `Builder<TModel>` ist bei Laravel nicht kovariant — ohne die Angabe wäre
     * die Übergabe ein Typfehler. Sie ist gefahrlos, weil diese Methode nur
     * Bedingungen über Spaltennamen setzt und nie ein Modell in den Erbauer
     * schreibt.
     *
     * @param  Builder<covariant Model>  $builder
     * @param  string  $subscriptionColumn  Spalte mit der Abonnement-ID
     * @param  string  $domainColumn  Spalte mit der Domain-ID
     */
    public function applyDomainRestriction(Builder $builder, string $subscriptionColumn, string $domainColumn): void
    {
        if ($this->unrestricted || $this->domainRestrictions === []) {
            return;
        }

        $restricted = array_keys($this->domainRestrictions);

        $builder->where(function (Builder $query) use ($restricted, $subscriptionColumn, $domainColumn): void {
            // Alles, was in einem Abonnement ohne Domain-Einschränkung liegt.
            $query->whereNotIn($subscriptionColumn, $restricted);

            foreach ($this->domainRestrictions as $subscriptionId => $domainIds) {
                if ($domainIds === []) {
                    // Eine Zuweisung ohne einzige Domain ist eine Aussage und
                    // keine Lücke: In diesem Abonnement ist keine Domain
                    // sichtbar. Ohne diese Zeile führte `whereIn(…, [])` zum
                    // selben Ergebnis — aber erst, nachdem jemand die leere
                    // Liste als Versehen gelesen hätte.
                    continue;
                }

                $query->orWhere(function (Builder $inner) use ($subscriptionId, $domainIds, $subscriptionColumn, $domainColumn): void {
                    $inner->where($subscriptionColumn, $subscriptionId)
                        ->whereIn($domainColumn, $domainIds);
                });
            }
        });
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
        $previousDomains = $this->domainRestrictions;

        $this->allowAll();

        try {
            return $callback();
        } finally {
            $this->subscriptionIds = $previousIds;
            $this->unrestricted = $previousUnrestricted;
            $this->domainRestrictions = $previousDomains;
        }
    }
}
