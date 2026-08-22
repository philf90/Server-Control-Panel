<?php

declare(strict_types=1);

namespace App\Support\Dns;

use SrvPanel\Agent\Acme\Dns\Packet;
use SrvPanel\Agent\Acme\Dns\Resolver;

/**
 * Der Abgleich selbst — ohne Modell, ohne Datenbank, ohne Uhr.
 *
 * **Warum das eine eigene Klasse ist.** Hier steht die Reihenfolge des ganzen
 * Merkmals: welche Namen gefragt werden, wie oft, was ein Fehlschlag bedeutet
 * und wie aus Antworten ein Urteil wird. Solange das in {@see Dns} stand,
 * hing es an Eloquent und `now()` und war damit nur in der CI zu fahren — und
 * **der einzige echte Fehler dieser Stufe steckte genau dort**: Ein Alias
 * ausserhalb seiner Zone liess die ganze Domain als „nicht erreichbar"
 * erscheinen. Gefunden hat ihn niemand, weil nichts ihn prüfen konnte.
 *
 * > **Der Fehler sitzt da, wo kein Test hinkommt — und das ist keine
 * > Beobachtung über den Zufall.**
 *
 * Derselbe Schnitt wie zwischen {@see Packet} und {@see Resolver}: Die Umformung ist prüfbar, der
 * Socket steht daneben und ist eine Handvoll Zeilen.
 *
 * **Ein Aufruf je Name und nicht einer je Domain.** Ein Alias darf jeden Namen
 * tragen (`Domains::parent()` sagt es wörtlich: „genau dafür gibt es ihn"), und
 * seine Sätze liegen dann auf ganz anderen Nameservern. Die der eigenen Zone zu
 * fragen ergäbe eine Antwort von jemandem, der nicht zuständig ist.
 */
final class Survey
{
    public function __construct(private readonly Measurement $measurement) {}

    /**
     * Was diese Namen brauchen, und was das DNS dazu sagt.
     *
     * @param  list<string>  $names  Die Namen, die nginx bedient
     * @param  list<string>  $addresses  Die Adressen dieses Servers
     * @param  string|null  $ca  Unsere CAA-Kennung; `null` heisst „unbekannt"
     * @return array{nameservers: list<string>, unasked: list<string>, records: list<array<string, mixed>>, authorities: list<array<string, mixed>>}
     */
    public function of(array $names, array $addresses, ?string $ca): array
    {
        $desired = DesiredRecords::forAll($names, $addresses);

        $nameservers = [];
        $measured = [];
        $authorities = [];

        /*
         * **Die Namen, für die die Messung nicht stattgefunden hat.**
         *
         * {@see Measurement} sagt es in seiner Beschreibung: `null` heisst „die
         * Messung hat nicht stattgefunden", und das ist etwas anderes als eine
         * Antwort ohne Sätze. Die Unterscheidung entstand hier — und wurde
         * verworfen, bevor sie jemand lesen konnte.
         *
         * In der Zwischenabnahme hat das einen Schritt gekostet
         * (`docs/74`, Befund 1): Der Bericht meldete „2 ohne Antwort", und um
         * zu wissen, ob der Agent gescheitert war oder die Zonen wirklich
         * schweigen, musste jemand ins Protokoll des Agenten sehen.
         *
         * > **Eine Auskunft, die entsteht und die niemand weitergibt, ist so
         * > gut wie keine.**
         */
        $unasked = [];

        foreach ($this->queries($names, $desired) as $name => $queries) {
            $answer = $this->measurement->of((string) $name, $queries);

            /*
             * **Ein Fehlschlag bleibt bei seinem Namen.** Scheitert die Frage
             * nach einem Alias, stehen dessen Einträge als „nicht erreichbar"
             * da — und die der Domain daneben so, wie sie gemessen wurden.
             */
            if ($answer === null) {
                $unasked[] = (string) $name;

                continue;
            }

            foreach ($answer['nameservers'] as $server) {
                if (! in_array($server, $nameservers, true)) {
                    $nameservers[] = $server;
                }
            }

            foreach ($answer['records'] as $record) {
                $measured[] = $record;
            }

            foreach ($answer['authorities'] as $entry) {
                $authorities[] = $this->authority($entry, (string) $name, $ca);
            }
        }

        return [
            'nameservers' => $nameservers,
            'unasked' => $unasked,
            'authorities' => $authorities,
            'records' => array_map(
                static fn (array $entry): array => [
                    'name' => $entry['name'],
                    'type' => $entry['type'],
                    'state' => $entry['state']->value,
                    'expected' => $entry['expected'],
                    'found' => $entry['found'],
                ],
                Comparison::of($desired, $measured),
            ),
        ];
    }

    /**
     * Was je Name gefragt wird.
     *
     * **Nach CAA wird für jeden Namen gefragt, auch ohne Sollzustand.** Führt
     * der Server keine öffentliche Adresse, gibt es keinen `A`-Satz zu
     * erwarten — ein CAA, das die Bestellung verbietet, gibt es trotzdem, und
     * es kostete dann Fehlversuche ohne jede Anzeige.
     *
     * @param  list<string>  $names
     * @param  list<array{name: string, type: string, expected: list<string>}>  $desired
     * @return array<string, list<array{name: string, type: string}>>
     */
    private function queries(array $names, array $desired): array
    {
        $byName = [];

        foreach ($names as $name) {
            $byName[$name][] = ['name' => $name, 'type' => 'CAA'];
        }

        foreach ($desired as $entry) {
            $byName[$entry['name']][] = ['name' => $entry['name'], 'type' => $entry['type']];
        }

        return $byName;
    }

    /**
     * Das Urteil über einen CAA-Satz.
     *
     * **Ein Name ohne Antwort bekommt kein Urteil.** `answered = 0` heisst
     * „nicht erreichbar", und daraus ein „kein CAA gefunden" zu machen wäre
     * eine Entwarnung, die niemand gemessen hat.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function authority(array $entry, string $fallback, ?string $ca): array
    {
        $silent = ($entry['answered'] ?? 0) === 0;

        $judgement = Authority::judge(
            is_array($entry['values'] ?? null) ? array_values($entry['values']) : [],
            $ca,
        );

        return [
            'name' => is_string($entry['name'] ?? null) ? $entry['name'] : $fallback,
            'state' => $silent ? 'unknown' : $judgement['state'],
            'reason' => $silent ? null : $judgement['reason'],
            'issuers' => $silent ? [] : $judgement['issuers'],
        ];
    }
}
