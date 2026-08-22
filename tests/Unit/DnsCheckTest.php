<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Dns\Lookup;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Journal;
use SrvPanel\Agent\Ops\DnsCheck;
use SrvPanel\Agent\Runner;
use Tests\Support\ScriptedLookup;

/**
 * `dns.check` — was die autoritativen Nameserver wirklich ausliefern.
 *
 * **Gegen ein Doppel und nicht gegen ein echtes DNS.** Die Fälle, um die es
 * geht, lassen sich draussen nicht bestellen: ein Nameserver, der eine andere
 * Adresse ausliefert als der zweite; einer, der auf die erste Frage schweigt
 * und danach nicht mehr gefragt wird; eine Zone, deren Server sich uneinig
 * sind. Im Netz ist das ein Zufall von Sekunden.
 *
 * **Der Schnitt, den dieser Durchgang festhält:** Die Operation misst und
 * vergleicht nicht. Sie kennt den Sollzustand einer Domain nicht und soll ihn
 * nicht kennen — sonst gäbe es ihn zweimal.
 *
 * > **Der Agent misst, das Panel vergleicht.**
 */
final class DnsCheckTest extends TestCase
{
    private const ZONE = 'example.de';

    private const NS = ['198.51.100.1', '198.51.100.2'];

    private function context(): Context
    {
        $journal = new Journal('/dev/null');

        return new Context(new Runner($journal), $journal, static function (array $line): void {});
    }

    /**
     * @param  array<string, array<string, list<string>|null>>  $records
     * @param  array<string, list<array{flags: int, tag: string, value: string}>|null>  $caa
     * @param  list<string>  $servers
     */
    private function check(array $records = [], array $caa = [], ?array $servers = null): DnsCheck
    {
        return new DnsCheck(new ScriptedLookup($servers ?? self::NS, [], $records, $caa));
    }

    /**
     * @param  list<array{name: string, type: string}>  $queries
     * @return array{zone: string, queries: list<array{name: string, type: string}>}
     */
    private function args(array $queries, string $zone = self::ZONE): array
    {
        return ['zone' => $zone, 'queries' => $queries];
    }

    // ------------------------------------------------------------------
    // Messen
    // ------------------------------------------------------------------

    public function test_it_reports_what_the_nameservers_deliver(): void
    {
        $check = $this->check([
            '198.51.100.1' => ['example.de/1' => ['203.0.113.10']],
            '198.51.100.2' => ['example.de/1' => ['203.0.113.10']],
        ]);

        $result = $check->execute($this->args([['name' => 'example.de', 'type' => 'A']]), $this->context());

        $this->assertSame(self::ZONE, $result['zone']);
        $this->assertSame(self::NS, $result['nameservers']);
        $this->assertSame([], $result['authorities']);
        $this->assertSame([[
            'name' => 'example.de',
            'type' => 'A',
            'asked' => 2,
            'answered' => 2,
            'values' => ['203.0.113.10'],
            'consistent' => true,
        ]], $result['records']);
    }

    /**
     * Ein Name ohne Satz ist etwas anderes als ein Server ohne Antwort.
     *
     * **Das ist der Zustand, für den `Lookup` seit P7 `null` kennt.** Ohne den
     * Unterschied meldete die Oberfläche „der Eintrag fehlt", wenn in Wahrheit
     * der Nameserver des Kunden schweigt — und schickte ihn dorthin, wo nichts
     * zu ändern ist.
     */
    public function test_a_missing_record_is_not_an_unreachable_server(): void
    {
        $fehlt = $this->check([
            '198.51.100.1' => ['example.de/1' => []],
            '198.51.100.2' => ['example.de/1' => []],
        ])->execute($this->args([['name' => 'example.de', 'type' => 'A']]), $this->context());

        $stumm = $this->check([
            '198.51.100.1' => ['example.de/1' => null],
            '198.51.100.2' => ['example.de/1' => null],
        ])->execute($this->args([['name' => 'example.de', 'type' => 'A']]), $this->context());

        $this->assertSame(2, $fehlt['records'][0]['answered'], 'beide haben geantwortet');
        $this->assertSame([], $fehlt['records'][0]['values']);

        $this->assertSame(0, $stumm['records'][0]['answered'], 'keiner hat geantwortet');
        $this->assertSame([], $stumm['records'][0]['values']);

        // Beide haben eine leere Werteliste — unterschieden werden sie an
        // `answered`, und genau deshalb steht die Zahl im Ergebnis.
        $this->assertSame(2, $fehlt['records'][0]['asked']);
        $this->assertSame(2, $stumm['records'][0]['asked']);
    }

    /**
     * Uneinige Nameserver sind ein Befund und kein Mittelwert.
     *
     * Liefert der eine eine andere Adresse als der andere, ist die Domain für
     * die Hälfte der Welt woanders.
     */
    public function test_disagreeing_nameservers_are_reported(): void
    {
        $result = $this->check([
            '198.51.100.1' => ['example.de/1' => ['203.0.113.10']],
            '198.51.100.2' => ['example.de/1' => ['203.0.113.99']],
        ])->execute($this->args([['name' => 'example.de', 'type' => 'A']]), $this->context());

        $this->assertFalse($result['records'][0]['consistent']);
        $this->assertSame(['203.0.113.10', '203.0.113.99'], $result['records'][0]['values']);
    }

    /**
     * Und dieselben Werte in anderer Reihenfolge sind keine Uneinigkeit.
     *
     * **Ohne diese Zeile wäre die vorige keine Messung.** Ein Vergleich, der die
     * Reihenfolge mitliest, meldet jede Zone mit zwei Adressen als uneinig —
     * und das ist der Normalfall, nicht der Befund.
     */
    public function test_the_same_values_in_another_order_are_no_disagreement(): void
    {
        $result = $this->check([
            '198.51.100.1' => ['example.de/1' => ['203.0.113.10', '203.0.113.11']],
            '198.51.100.2' => ['example.de/1' => ['203.0.113.11', '203.0.113.10']],
        ])->execute($this->args([['name' => 'example.de', 'type' => 'A']]), $this->context());

        $this->assertTrue($result['records'][0]['consistent']);
        $this->assertSame(['203.0.113.10', '203.0.113.11'], $result['records'][0]['values']);
    }

    /**
     * Ein Server, der schweigt, wird nicht noch fünfzehnmal gefragt.
     *
     * **Sonst kostet ein einziger toter Nameserver das Produkt aus Fragen und
     * Zeitlimit** — und der Vorgang stirbt an seinem eigenen, während die
     * anderen Server längst geantwortet haben.
     */
    public function test_a_silent_server_is_asked_once_and_then_left_alone(): void
    {
        $lookup = new ZaehlendesLookup(self::NS, ['198.51.100.1']);
        $check = new DnsCheck($lookup);

        $check->execute($this->args([
            ['name' => 'example.de', 'type' => 'A'],
            ['name' => 'www.example.de', 'type' => 'A'],
            ['name' => 'example.de', 'type' => 'AAAA'],
        ]), $this->context());

        $this->assertSame(1, $lookup->fragen['198.51.100.1'] ?? 0, 'der stumme Server einmal');
        $this->assertSame(3, $lookup->fragen['198.51.100.2'] ?? 0, 'der antwortende dreimal');
    }

    public function test_caa_records_come_back_separately(): void
    {
        $eintrag = ['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org'];

        $result = $this->check(caa: [
            '198.51.100.1/example.de' => [$eintrag],
            '198.51.100.2/example.de' => [$eintrag],
        ])->execute($this->args([['name' => 'example.de', 'type' => 'CAA']]), $this->context());

        $this->assertSame([], $result['records'], 'CAA gehört nicht unter die Werte');
        $this->assertSame([[
            'name' => 'example.de',
            'type' => 'CAA',
            'asked' => 2,
            'answered' => 2,
            'values' => [$eintrag],
            'consistent' => true,
        ]], $result['authorities']);
    }

    /** Mehr als vier Nameserver werden nicht gefragt. */
    public function test_at_most_four_servers_are_asked(): void
    {
        $result = $this->check(servers: ['1.1.1.1', '2.2.2.2', '3.3.3.3', '4.4.4.4', '5.5.5.5'])
            ->execute($this->args([['name' => 'example.de', 'type' => 'A']]), $this->context());

        $this->assertCount(DnsCheck::MAX_SERVERS, $result['nameservers']);
        $this->assertSame(DnsCheck::MAX_SERVERS, $result['records'][0]['asked']);
    }

    /** Eine Zone ohne bekannte Nameserver ergibt kein Ergebnis und keinen Fehler. */
    public function test_a_zone_without_nameservers_answers_with_nothing_found(): void
    {
        $result = $this->check(servers: [])
            ->execute($this->args([['name' => 'example.de', 'type' => 'A']]), $this->context());

        $this->assertSame([], $result['nameservers']);
        $this->assertSame(0, $result['records'][0]['asked']);
        $this->assertSame(0, $result['records'][0]['answered']);
    }

    // ------------------------------------------------------------------
    // Was abgewiesen wird
    // ------------------------------------------------------------------

    /**
     * Ein Name ausserhalb der Zone wird abgewiesen.
     *
     * **Nicht aus Ordnungsliebe.** Gefragt werden die Nameserver *dieser* Zone;
     * ein fremder Name bekäme damit eine Antwort von einem Server, der für ihn
     * nicht zuständig ist — und die sähe aus wie ein Befund.
     */
    public function test_a_name_outside_the_zone_is_refused(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('liegt nicht in dieser Zone');

        $this->check()->execute(
            $this->args([['name' => 'fremd.invalid', 'type' => 'A']]),
            $this->context(),
        );
    }

    /**
     * Und ein Name, der nur so aussieht, als läge er darin.
     *
     * `bösexample.de` endet auf `example.de` und liegt trotzdem nicht darin.
     * Verglichen wird beschriftungsweise, und zwar an der einen Stelle, die das
     * darf.
     */
    public function test_a_name_that_only_looks_like_it_belongs_is_refused(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('liegt nicht in dieser Zone');

        $this->check()->execute(
            $this->args([['name' => 'boesexample.de', 'type' => 'A']]),
            $this->context(),
        );
    }

    /** Die Zone selbst ist ein zulässiger Name — die Gegenprobe zu den beiden. */
    public function test_the_zone_itself_is_a_valid_name(): void
    {
        $result = $this->check()->execute(
            $this->args([['name' => 'example.de', 'type' => 'A']]),
            $this->context(),
        );

        $this->assertSame('example.de', $result['records'][0]['name']);
    }

    /** @param mixed $type Was als Satztyp ankommt */
    #[DataProvider('unzulaessigeTypen')]
    public function test_only_the_four_known_types_are_accepted(mixed $type): void
    {
        $this->expectException(AgentException::class);

        $this->check()->execute(
            $this->args([['name' => 'example.de', 'type' => $type]]),
            $this->context(),
        );
    }

    /** @return iterable<string, array{mixed}> */
    public static function unzulaessigeTypen(): iterable
    {
        yield 'ein Typ, den es gibt, aber hier nicht' => ['MX'];
        yield 'ein erfundener' => ['WATDENN'];
        yield 'die Zahl statt des Namens' => [1];
        yield 'leer' => [''];
        yield 'nichts' => [null];
    }

    /** Kleinschreibung wird angenommen — sonst scheitert es an der Bequemlichkeit. */
    public function test_the_type_may_be_written_in_lower_case(): void
    {
        $result = $this->check()->execute(
            $this->args([['name' => 'example.de', 'type' => 'aaaa']]),
            $this->context(),
        );

        $this->assertSame('AAAA', $result['records'][0]['type']);
    }

    public function test_too_many_queries_are_refused(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Fragen auf einmal');

        $queries = array_fill(0, DnsCheck::MAX_QUERIES + 1, ['name' => 'example.de', 'type' => 'A']);

        $this->check()->execute($this->args($queries), $this->context());
    }

    /** Und die Gegenprobe: genau die Höchstzahl geht durch. */
    public function test_exactly_the_maximum_is_accepted(): void
    {
        $queries = array_fill(0, DnsCheck::MAX_QUERIES, ['name' => 'example.de', 'type' => 'A']);

        $result = $this->check()->execute($this->args($queries), $this->context());

        $this->assertCount(DnsCheck::MAX_QUERIES, $result['records']);
    }

    public function test_a_call_without_queries_is_refused(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('keine Frage');

        $this->check()->execute(['zone' => self::ZONE, 'queries' => []], $this->context());
    }

    public function test_a_query_that_is_not_a_list_is_refused(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('erwartete Form');

        $this->check()->execute(['zone' => self::ZONE, 'queries' => ['nur ein Wort']], $this->context());
    }

    /** Die Operation verändert nichts und sagt das auch. */
    public function test_it_declares_itself_as_reading_only(): void
    {
        $this->assertFalse(DnsCheck::mutating());
        $this->assertSame('dns.check', DnsCheck::name());
    }
}

/**
 * Ein Doppel, das mitzählt, wie oft es gefragt wurde.
 *
 * Steht hier und nicht unter `tests/Support`, weil es genau eine Frage
 * beantwortet — und die gehört zu diesem Durchgang.
 */
final class ZaehlendesLookup implements Lookup
{
    /** @var array<string, int> */
    public array $fragen = [];

    /**
     * @param  list<string>  $servers
     * @param  list<string>  $stumm  Diese Server antworten nie
     */
    public function __construct(
        private readonly array $servers,
        private readonly array $stumm = [],
    ) {}

    /** @return list<string> */
    public function nameservers(string $name): array
    {
        return $this->servers;
    }

    /** @return list<string>|null */
    public function records(string $server, string $name, int $type): ?array
    {
        $this->fragen[$server] = ($this->fragen[$server] ?? 0) + 1;

        return in_array($server, $this->stumm, true) ? null : [];
    }

    /** @return list<array{flags: int, tag: string, value: string}>|null */
    public function authorities(string $server, string $name): ?array
    {
        $this->fragen[$server] = ($this->fragen[$server] ?? 0) + 1;

        return in_array($server, $this->stumm, true) ? null : [];
    }
}
