<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Der DNS-Abgleich einer Domain, auf eine Marke zusammengezogen.
 *
 * ## Wofür das gut ist
 *
 * Der Bereich an der Domain (`docs/72 §2.7`) sagt je Name und Satz, was ist —
 * fünf Zustände, dazu CAA und die ungefragten Namen. Das ist die richtige
 * Auskunft **an der Domain** und die falsche **in einer Liste**: Wer zwanzig
 * Domains untereinander sieht, will nicht fünf Zustände je Zeile lesen, sondern
 * wissen, welche Zeile er anklicken muss.
 *
 * > **Eine Liste beantwortet nicht dieselbe Frage wie eine Seite. Sie
 * > beantwortet, welche Seite man aufschlagen muss.**
 *
 * Gewünscht hat es der Betreiber am 22. August 2026 während der Bilderrunde,
 * mit zwei Zuständen: „ok" und „benötigt Aufmerksamkeit".
 *
 * ## Warum es drei sind und nicht zwei
 *
 * **„Noch nie geprüft" ist keins von beidem.** Jede frisch angelegte Domain
 * ist genau dieser Fall, und sie als „in Ordnung" zu führen wäre eine
 * Entwarnung ohne Messung — derselbe Fehler, vor dem `Dns::last()` mit seinem
 * `null` warnt und `Settings::diskQuota()` davor.
 *
 * `Badge` nennt seinen vierten Rang „kein Zustand, eine Abwesenheit". Genau
 * das ist gemeint.
 *
 * > **Eine Marke, die „in Ordnung" sagt, weil niemand gemessen hat, ist keine
 * > Auskunft, sondern eine Vermutung mit Farbe.**
 *
 * ## Warum „zeigt woandershin" hier Aufmerksamkeit bekommt
 *
 * `docs/72 §2.3` hält fest, dass dieser Zustand **kein Fehler** ist: Wer über
 * ein CDN fährt, hat ihn absichtlich und soll nicht angeblafft werden. Er
 * bekommt an der Domain deshalb `warn` und nicht `critical`.
 *
 * Diese Marke ändert daran nichts — sie trägt denselben Rang `warn`, den die
 * Zeile an der Domain schon trägt. Die Liste sagt damit nicht „kaputt",
 * sondern „hier steht etwas, das nicht der Regelfall ist". Wer es absichtlich
 * so hat, sieht dieselbe gelbe Marke wie auf der Domainseite und keine neue.
 *
 * ## Die Regel, nach der entschieden wird
 *
 * Aufmerksamkeit bekommt, was **irgendwo** vom Regelfall abweicht: ein Satz,
 * der nicht {@see DnsRecordState::Here} ist; ein CAA, das die eigene Stelle
 * nicht nennt; ein Name, der gar nicht gefragt werden konnte; eine Zone ohne
 * erreichbaren Nameserver.
 *
 * **Und ein unbekannter Zustand ebenfalls.** Kommt ein sechster
 * {@see DnsRecordState} dazu und niemand denkt an diese Stelle, ist die
 * Antwort „nachsehen" und nicht „in Ordnung":
 *
 * > **Ein Zusammenzug, der Unbekanntes für gut hält, wird beim nächsten
 * > Zustand still falsch.**
 */
enum DnsHealth: string
{
    /** Es ist noch nie gemessen worden. */
    case Unchecked = 'unchecked';

    /** Jeder erwartete Satz steht richtig, und nichts steht einer Bestellung im Weg. */
    case Fine = 'fine';

    /** Irgendetwas weicht ab — die Domainseite sagt was. */
    case Attention = 'attention';

    public function label(): string
    {
        return match ($this) {
            self::Unchecked => 'ungeprüft',
            self::Fine => 'in Ordnung',
            self::Attention => 'nachsehen',
        };
    }

    /**
     * Welchen Rang die Zustandsmarke trägt.
     *
     * **Hier und nicht in der Vue-Datei** — dieselbe Begründung wie bei
     * {@see DnsRecordState::badge()}: Eine `v-if`-Kette daneben wäre eine
     * zweite Fassung derselben Regel, und die zweite veraltet.
     *
     * @return 'ok'|'warn'|'critical'|'neutral'
     */
    public function badge(): string
    {
        return match ($this) {
            self::Unchecked => 'neutral',
            self::Fine => 'ok',
            self::Attention => 'warn',
        };
    }

    /**
     * Der Zusammenzug eines Befundes — `null` heisst „noch nie geprüft".
     *
     * **`null` und ein leerer Befund sind nicht dasselbe**, und der
     * Unterschied ist der Grund für {@see self::Unchecked}: `null` heisst, es
     * hat keine Messung gegeben. Ein Befund **mit** leerer Satzliste heisst,
     * es hat eine gegeben und dem Server fehlt eine öffentliche Adresse — das
     * ist eine Abweichung und keine Abwesenheit.
     *
     * @param  array<string, mixed>|null  $findings
     */
    public static function of(?array $findings): self
    {
        if ($findings === null) {
            return self::Unchecked;
        }

        $records = is_array($findings['records'] ?? null) ? $findings['records'] : [];

        /*
         * **Ein Befund ohne einen einzigen Satz ist keine Entwarnung.** Er
         * entsteht, wenn dem Server jede öffentliche Adresse fehlt — die
         * Domainseite schreibt dort „Für diese Domain ist kein Sollzustand
         * bekannt". Ohne diese Zeile stünde daneben „in Ordnung".
         */
        if ($records === []) {
            return self::Attention;
        }

        foreach ($records as $record) {
            $state = is_array($record) && is_string($record['state'] ?? null)
                ? DnsRecordState::tryFrom($record['state'])
                : null;

            if ($state !== DnsRecordState::Here) {
                return self::Attention;
            }
        }

        if (self::listOf($findings, 'unasked') !== []) {
            return self::Attention;
        }

        if (self::listOf($findings, 'nameservers') === []) {
            return self::Attention;
        }

        foreach (is_array($findings['authorities'] ?? null) ? $findings['authorities'] : [] as $authority) {
            if (is_array($authority) && ($authority['state'] ?? null) === 'refused') {
                return self::Attention;
            }
        }

        return self::Fine;
    }

    /**
     * Ein Feld des Befundes als Liste — alles andere zählt als leer.
     *
     * @param  array<string, mixed>  $findings
     * @return list<mixed>
     */
    private static function listOf(array $findings, string $key): array
    {
        return is_array($findings[$key] ?? null) ? array_values($findings[$key]) : [];
    }
}
