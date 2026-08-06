<?php

declare(strict_types=1);

namespace App\Support\Tls;

use App\Enums\DomainType;
use App\Models\Domain;
use SrvPanel\Agent\Acme\DnsChallenge;
use SrvPanel\Agent\Acme\Store;

/**
 * Ob zu dieser Domain ein Platzhalter bestellt werden darf — und was er kostet.
 *
 * **Ein Platzhalter löst die Wochengrenze und kostet die Trennschärfe**
 * (`docs/34 §3`). Ein Abonnement mit vierzig Unterdomains verbraucht heute
 * vierzig Einträge je Woche; mit `*.example.de` sind es zwei. Dafür deckt das
 * Zertifikat jede Unterdomain der Zone — auch eine, die einem *anderen*
 * Abonnement gehört. Technisch ist damit nichts gewonnen oder verloren, der
 * Schlüssel liegt ohnehin root-eigen auf demselben Server; die Aussage nach
 * aussen ändert sich aber.
 *
 * **Deshalb wird an der Domain geprüft und nicht am eingetippten Namen.**
 * Käme der Name aus der Anfrage, bestellte jemand `*.fremde.de` — und das
 * scheitert erst an der Zertifizierungsstelle, mit einem verbrauchten
 * Fehlversuch, der für das ganze Konto zählt und damit für jeden Kunden dieses
 * Servers.
 *
 * **Und nur zu einer Basisdomain.** Ein Platzhalter zu einer Subdomain wäre
 * `*.blog.example.de` — zulässig, aber nicht das, was jemand meint, der auf
 * einer Subdomainseite auf „Platzhalter" klickt. Ein Alias hat gar keinen
 * eigenen Block. Gefragt wird deshalb der Typ, den das Panel ohnehin führt.
 */
final class WildcardOrder
{
    /**
     * Nur diese Arten sind Basisdomains eines Abonnements.
     *
     * `Subdomain` und `Alias` nicht: Die eine liegt schon unter einer anderen
     * Domain desselben Abonnements und wäre von deren Platzhalter gedeckt, die
     * andere hat keinen eigenen Server-Block.
     */
    public const BASE_TYPES = [DomainType::Main, DomainType::Addon];

    public function __construct(
        private readonly DnsProfile $profiles,
        private readonly DnsCredentials $credentials,
    ) {}

    /**
     * Die Namen einer Platzhalterbestellung — **der Stern zuerst**.
     *
     * **Das ist keine Kosmetik.** Der Ablageort eines Zertifikats entsteht aus
     * dem ersten Namen ({@see Store}); stünde die
     * Basisdomain vorn, läge der Platzhalter unter `example.de` und
     * überschriebe ein einfaches Zertifikat für denselben Namen. Mit dem Stern
     * vorn heisst der Ablageort `_wildcard.example.de` und stösst mit nichts
     * zusammen.
     *
     * **Und die Basisdomain gehört dazu.** `*.example.de` deckt `example.de`
     * nicht — wer nur den Stern bestellt, bekommt auf der Hauptdomain eine
     * Browserwarnung.
     *
     * @return list<string>
     */
    public static function names(Domain $domain): array
    {
        return ['*.'.$domain->name, $domain->name];
    }

    /** Die Art der Prüfung, die ein Platzhalter verlangt. */
    public static function challenge(): string
    {
        return DnsChallenge::TYPE;
    }

    /**
     * Ist diese Domain eine Basisdomain ihres Abonnements?
     *
     * Siehe die Klassenbeschreibung: Das ist die Frage, die die Grenze zwischen
     * zwei Kunden hält, und sie wird an der Domain beantwortet.
     */
    public static function isBase(Domain $domain): bool
    {
        return in_array($domain->type, self::BASE_TYPES, true);
    }

    /**
     * Fehlt etwas, um jetzt bestellen zu können? Dann steht hier, was.
     *
     * **Eine Auskunft und keine Ausnahme.** Die Seite soll sagen können, warum
     * der Knopf nicht geht — „es fehlen Zugangsdaten" ist etwas, das der
     * Betreiber beheben kann, und eine Bestellung, die stumm ins Leere läuft,
     * ist es nicht.
     */
    public function obstacle(Domain $domain): ?string
    {
        if (! self::isBase($domain)) {
            return 'Ein Platzhalter gehört zu einer Haupt- oder Zusatzdomain.';
        }

        if (! $this->hasCredentials($domain)) {
            return 'Für diese Domain sind keine DNS-Zugangsdaten hinterlegt; ohne sie geht DNS-01 nicht.';
        }

        return null;
    }

    public function possible(Domain $domain): bool
    {
        return $this->obstacle($domain) === null;
    }

    /**
     * Liegen für das Profil dieser Domain Zugangsdaten?
     *
     * **Gefragt wird der Agent, denn dort liegen sie** — nicht in der Datenbank
     * des Panels (`docs/34 §5`). Antwortet er nicht, ist die Antwort „nein":
     * Ein Knopf, der eine Bestellung auslöst, die mangels Zugangsdaten
     * scheitert, verbrennt einen Fehlversuch, der für jeden Kunden dieses
     * Servers zählt.
     */
    private function hasCredentials(Domain $domain): bool
    {
        return in_array($this->profile($domain), $this->credentials->profiles(), true);
    }

    /** Das Profil, unter dem die Zone dieser Domain geändert wird. */
    public function profile(Domain $domain): string
    {
        return $this->profiles->forDomain($domain);
    }

    /**
     * Namen des Abonnements, die ein Platzhalter **nicht** deckt.
     *
     * **Eine Grenze, die ACME selbst zieht** (`docs/34 §3`): `*.example.de`
     * deckt `a.b.example.de` nicht. Wer das betreibt, braucht `*.b.example.de`
     * dazu. Das ist kein Fehler des Panels — aber es ist eine Auskunft, die die
     * Oberfläche geben muss, statt eine Warnung im Browser entstehen zu lassen.
     *
     * @return list<string>
     */
    public static function uncovered(Domain $domain): array
    {
        $subscription = $domain->subscription;

        if ($subscription === null || ! self::isBase($domain)) {
            return [];
        }

        $depth = substr_count($domain->name, '.') + 1;
        $suffix = '.'.$domain->name;
        $found = [];

        foreach ($subscription->domains as $other) {
            foreach ($other->serverNames() as $name) {
                // Zwei Beschriftungen mehr als die Basis heisst: eine Ebene zu
                // tief für einen Platzhalter.
                if (str_ends_with($name, $suffix) && substr_count($name, '.') + 1 > $depth + 1) {
                    $found[] = $name;
                }
            }
        }

        sort($found);

        return array_values(array_unique($found));
    }
}
