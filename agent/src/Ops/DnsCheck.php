<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Acme\Dns\Lookup;
use SrvPanel\Agent\Acme\Dns\Packet;
use SrvPanel\Agent\Acme\Dns\Resolver;
use SrvPanel\Agent\Acme\Dns\Zones;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\DomainName;
use SrvPanel\Agent\Op;

/**
 * Was die autoritativen Nameserver einer Zone wirklich ausliefern.
 *
 * **Diese Operation misst und vergleicht nicht.** Was eine Domain haben *soll*,
 * weiss das Panel — welche Adressen dieser Server hat, welche Domains es gibt,
 * welche Betriebsart eingestellt ist. Was sie *hat*, weiss nur, wer eine
 * Steckdose aufmachen darf. Der Schnitt liegt genau dort:
 *
 * > **Der Agent misst, das Panel vergleicht.**
 *
 * Ein Agent, der den Sollzustand kennt, hätte eine zweite Fassung davon — und
 * die zweite ist die, die veraltet.
 *
 * **Nicht verändernd, also ohne Vorgang** — dieselbe Aufteilung wie bei
 * {@see DnsCredentialList}: Nachsehen ändert nichts, und eine Seite, die bei
 * jedem Aufruf eine Zeile ins Protokoll schreibt, öffnet man nicht gern.
 *
 * **Gefragt werden die autoritativen Server und nicht der Systemauflöser.** Der
 * antwortet aus seinem Zwischenspeicher, und für diese Frage ist das besonders
 * schädlich: Der Kunde stellt seinen Eintrag beim Anbieter um, sieht hier nach,
 * und die Anzeige sagt zwanzig Minuten lang weiter „zeigt woandershin". Er
 * stellt zurück — und dann ist es wirklich falsch.
 *
 * > **Ein Zwischenspeicher, der eine Anleitung beantwortet, macht aus einer
 * > Hilfe eine Irreführung.**
 */
final class DnsCheck implements Op
{
    /**
     * So viele Fragen je Aufruf.
     *
     * Eine Domain braucht `A`, `AAAA` und `CAA` für sich und `A`/`AAAA` für
     * `www` — also fünf. Sechzehn lässt Luft und ist weit unter dem, was einen
     * fremden Nameserver stört.
     */
    public const MAX_QUERIES = 16;

    /**
     * Und so viele Server.
     *
     * Eine Zone hat üblicherweise zwei bis vier. Die Grenze steht hier, weil
     * jede Frage an jeden Server geht: Ohne sie wäre die Laufzeit das Produkt
     * zweier Zahlen, die beide von aussen kommen.
     */
    public const MAX_SERVERS = 4;

    /** Was von aussen als Typ ankommen darf — eine Positivliste, keine Zahl. */
    public const TYPES = [
        'A' => Packet::TYPE_A,
        'AAAA' => Packet::TYPE_AAAA,
        'TXT' => Packet::TYPE_TXT,
        'CAA' => Packet::TYPE_CAA,
    ];

    public function __construct(private readonly Lookup $lookup = new Resolver) {}

    public static function name(): string
    {
        return 'dns.check';
    }

    public static function mutating(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(array $args, Context $context): array
    {
        $zone = DomainName::normalize($args['zone'] ?? null, 'zone');
        $queries = $this->queries($args, $zone);

        $servers = array_slice($this->lookup->nameservers($zone), 0, self::MAX_SERVERS);

        $records = [];
        $authorities = [];

        // **Ein Server, der auf eine Frage nicht antwortet, wird nicht
        // sechzehnmal gefragt.** Sonst kostet ein einziger toter Nameserver
        // das Produkt aus Fragen und Zeitlimit — und der Vorgang stirbt an
        // seinem eigenen, während die anderen Server längst geantwortet haben.
        //
        // Der Preis steht daneben: Ein einzelnes verlorenes UDP-Paket nimmt
        // diesen Server für den ganzen Lauf heraus. Sichtbar bleibt es, weil
        // `answered` dann kleiner ist als `asked`.
        $stumm = [];

        foreach ($queries as $query) {
            $antworten = [];

            foreach ($servers as $server) {
                if (isset($stumm[$server])) {
                    continue;
                }

                $antwort = $query['type'] === Packet::TYPE_CAA
                    ? $this->lookup->authorities($server, $query['name'])
                    : $this->lookup->records($server, $query['name'], $query['type']);

                if ($antwort === null) {
                    $stumm[$server] = true;

                    continue;
                }

                $antworten[] = $antwort;
            }

            $ergebnis = [
                'name' => $query['name'],
                'type' => $query['label'],
                'asked' => count($servers),
                'answered' => count($antworten),
            ] + $this->merge($antworten);

            if ($query['type'] === Packet::TYPE_CAA) {
                $authorities[] = $ergebnis;
            } else {
                $records[] = $ergebnis;
            }
        }

        return [
            'zone' => $zone,
            'nameservers' => $servers,
            'records' => $records,
            'authorities' => $authorities,
        ];
    }

    /**
     * Die geprüften Fragen.
     *
     * **Jeder Name muss in der Zone liegen**, und gefragt wird das über
     * {@see Zones} — die eine Stelle, die entscheidet, welche Zone zu einem
     * Namen gehört. Der Grund ist nicht Ordnungsliebe: Gefragt werden die
     * Nameserver *dieser* Zone, und ein Name ausserhalb bekäme damit eine
     * Antwort von einem Server, der für ihn nicht zuständig ist. Die sähe aus
     * wie ein Befund.
     *
     * @param  array<string, mixed>  $args
     * @return list<array{name: string, type: int, label: string}>
     */
    private function queries(array $args, string $zone): array
    {
        $raw = $args['queries'] ?? null;

        if (! is_array($raw) || $raw === []) {
            throw AgentException::badRequest('Es ist keine Frage angegeben.');
        }

        if (count($raw) > self::MAX_QUERIES) {
            throw AgentException::badRequest(sprintf(
                'Mehr als %d Fragen auf einmal gehen nicht.',
                self::MAX_QUERIES,
            ));
        }

        $queries = [];

        foreach (array_values($raw) as $index => $entry) {
            if (! is_array($entry)) {
                throw AgentException::badRequest(sprintf('Die %d. Frage hat nicht die erwartete Form.', $index + 1));
            }

            $name = DomainName::normalize($entry['name'] ?? null, 'name');
            $label = is_string($entry['type'] ?? null) ? strtoupper($entry['type']) : '';

            if (! isset(self::TYPES[$label])) {
                throw AgentException::badRequest('Diesen Satztyp gibt es hier nicht.', ['type' => $label]);
            }

            if (Zones::pick($name, [$zone]) === null) {
                throw AgentException::badRequest('Der Name liegt nicht in dieser Zone.', ['name' => $name, 'zone' => $zone]);
            }

            $queries[] = ['name' => $name, 'type' => self::TYPES[$label], 'label' => $label];
        }

        return $queries;
    }

    /**
     * Was die Server zusammen gesagt haben — und ob sie sich einig waren.
     *
     * **Uneinigkeit ist ein eigener Zustand und kein Mittelwert.** Liefert der
     * eine Nameserver eine andere Adresse als der andere, ist die Domain für
     * die Hälfte der Welt woanders — das ist ein Befund und kein Rundungsfehler.
     * Aufgeführt wird deshalb die Vereinigung **und** das Kennzeichen dazu.
     *
     * @param  list<list<mixed>>  $antworten
     * @return array{values: list<mixed>, consistent: bool}
     */
    private function merge(array $antworten): array
    {
        $values = [];
        $fingerprints = [];

        foreach ($antworten as $antwort) {
            $einzeln = [];

            foreach ($antwort as $wert) {
                $key = is_array($wert) ? json_encode($wert) : (string) $wert;
                $einzeln[] = $key;

                if (! array_key_exists((string) $key, $values)) {
                    $values[(string) $key] = $wert;
                }
            }

            sort($einzeln);
            $fingerprints[] = implode("\x00", $einzeln);
        }

        return [
            'values' => array_values($values),
            // Ein einziger Antwortender ist mit sich einig; keiner auch. Das
            // ist kein Trick — „uneinig" braucht zwei, die etwas sagen.
            'consistent' => count(array_unique($fingerprints)) <= 1,
        ];
    }
}
