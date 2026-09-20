<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\WebAccessCount;
use SrvPanel\Agent\Site;

/**
 * Zählt `web.access.count` das, was in den Verzeichnissen steht?
 *
 * **Die Prüfkörper sind gemessen und nicht erfunden.** Die Zeile des neuen
 * Zeitalters ist die erste, die am 20. September 2026 auf `cloudsrv24` im
 * neuen Format gelandet ist — ein Scanner, der die Startseite abgeholt hat.
 * Sie trägt `200 687 … 990 172`: 687 Bytes Rumpf, 990 Bytes auf der Leitung.
 * Die 303 Bytes Unterschied sind der Kopf, und sie sind der ganze Grund, warum
 * `$bytes_sent` im Format steht (`docs/128` M2).
 *
 * Die Zeile des alten Zeitalters daneben ist dieselbe Anfrage ohne die beiden
 * Felder — so, wie `combined` sie geschrieben hätte.
 */
final class AccessCountTest extends TestCase
{
    /** Gemessen auf cloudsrv24, 20. September 2026, 22:19:18 +0200. */
    private const NEW_ERA = '66.132.172.177 - - [20/Sep/2026:22:19:18 +0200] "GET / HTTP/1.1" 200 687 "-" "Mozilla/5.0 (compatible; CensysInspect/1.1; +https://about.censys.io/)" 990 172';

    /** Dieselbe Anfrage, wie `combined` sie geschrieben hätte. */
    private const OLD_ERA = '66.132.172.177 - - [20/Sep/2026:22:19:18 +0200] "GET / HTTP/1.1" 200 687 "-" "Mozilla/5.0 (compatible; CensysInspect/1.1; +https://about.censys.io/)"';

    /** Derselbe Abruf, einen Tag früher — für die Trennung nach Tagen. */
    private const DAY_BEFORE = '66.132.172.177 - - [19/Sep/2026:23:59:59 +0200] "GET / HTTP/1.1" 404 120 "-" "curl/8.5.0" 300 90';

    private string $rig = '';

    protected function setUp(): void
    {
        $this->rig = sys_get_temp_dir().'/srvpanel-zaehler-'.bin2hex(random_bytes(6));
        mkdir($this->rig, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->rig);
    }

    private function remove(string $pfad): void
    {
        if (! is_dir($pfad)) {
            return;
        }

        foreach (scandir($pfad) ?: [] as $eintrag) {
            if ($eintrag === '.' || $eintrag === '..') {
                continue;
            }

            $kind = $pfad.'/'.$eintrag;
            is_dir($kind) ? $this->remove($kind) : unlink($kind);
        }

        rmdir($pfad);
    }

    /** @param  list<string>  $zeilen */
    private function log(string $abonnement, string $domain, string $datei, array $zeilen): void
    {
        // **Nicht selbst zusammengesetzt.** Ein Prüfstand, der den Pfad
        // eigenhändig baut, prüft seine eigene Kopie und nicht den Weg, den
        // der Agent geht — der Satz steht so in {@see Site::logsRootIn()}.
        $verzeichnis = Site::logsRootIn($this->rig, $abonnement).'/'.$domain;

        if (! is_dir($verzeichnis)) {
            mkdir($verzeichnis, 0o700, true);
        }

        file_put_contents($verzeichnis.'/'.$datei, implode("\n", $zeilen)."\n");
    }

    /** @return array{domains: list<array<string, mixed>>, pending: list<array<string, string>>, totals: array<string, int>} */
    private function counted(?float $deadline = null): array
    {
        return WebAccessCount::overRoot($this->rig, $deadline ?? (microtime(true) + 30));
    }

    public function test_it_counts_what_the_line_says(): void
    {
        $this->log('p1001', 'beispiel.de', 'access.log', [self::NEW_ERA]);

        $ergebnis = $this->counted();

        $this->assertCount(1, $ergebnis['domains']);

        $domain = $ergebnis['domains'][0];

        $this->assertSame('p1001', $domain['subscription']);
        $this->assertSame('beispiel.de', $domain['domain']);
        $this->assertSame([
            '2026-09-20' => ['requests' => 1, 'sent' => 990, 'received' => 172, 'errors' => 0],
        ], $domain['days']);
    }

    /**
     * **Der Kopf wird mitgezählt, der Rumpf allein wäre zu wenig.**
     *
     * Die Gegenprobe zur Zahl darüber: 990 und nicht 687. Ohne diesen Test
     * stünde die Zahl da, ohne dass jemand sagt, welche der beiden sie ist —
     * und 687 sähe genauso richtig aus.
     */
    public function test_it_counts_the_wire_and_not_the_body(): void
    {
        $this->log('p1001', 'beispiel.de', 'access.log', [self::NEW_ERA]);

        $tage = $this->counted()['domains'][0]['days'];

        $this->assertSame(990, $tage['2026-09-20']['sent']);
        $this->assertNotSame(687, $tage['2026-09-20']['sent']);
    }

    /**
     * **Beide Dateien, und ihre Tage fallen zusammen.**
     *
     * Der Grund steht im Kopf von {@see WebAccessCount}: `logrotate` läuft
     * in einem Fenster und nicht zu einer Uhrzeit. Liegt derselbe Tag zur
     * Hälfte in `access.log` und zur Hälfte in `access.log.1`, muss eine Zahl
     * herauskommen und nicht zwei.
     */
    public function test_both_files_of_a_domain_are_added_up(): void
    {
        $this->log('p1001', 'beispiel.de', 'access.log', [self::NEW_ERA]);
        $this->log('p1001', 'beispiel.de', 'access.log.1', [self::NEW_ERA, self::DAY_BEFORE]);

        $domain = $this->counted()['domains'][0];

        $this->assertSame(2, $domain['files']);
        $this->assertSame(2, $domain['days']['2026-09-20']['requests']);
        $this->assertSame(1980, $domain['days']['2026-09-20']['sent']);

        // Und der Tag davor bleibt ein eigener Tag — samt seinem 404.
        $this->assertSame(1, $domain['days']['2026-09-19']['requests']);
        $this->assertSame(1, $domain['days']['2026-09-19']['errors']);
    }

    /**
     * **Eine Zeile des alten Zeitalters wird gezählt und nicht verrechnet.**
     *
     * Sie trägt die beiden Zahlen nicht; sie mitzurechnen hiesse, Bytes zu
     * erfinden. Sie zu verschweigen hiesse, dass ein Server-Block, der noch
     * das alte Format schreibt, wie eine stille Domain aussieht.
     */
    public function test_a_legacy_line_is_tallied_and_not_added(): void
    {
        $this->log('p1001', 'beispiel.de', 'access.log', [self::NEW_ERA, self::OLD_ERA, self::OLD_ERA]);

        $domain = $this->counted()['domains'][0];

        $this->assertSame(3, $domain['lines']);
        $this->assertSame(1, $domain['parsed']);
        $this->assertSame(2, $domain['legacy']);
        $this->assertSame(0, $domain['unreadable']);
        $this->assertSame(990, $domain['days']['2026-09-20']['sent']);
    }

    /**
     * **Ein Verzeichnis mit Punkt ist kein Abonnement.**
     *
     * `tests/plattenkurve-messen.sh` legt seinen Prüfkörper als
     * `.plattenkurve-probe.<pid>` genau in dieser Wurzel ab. Ohne diese Regel
     * stünde er als Abonnement in der Auswertung.
     */
    public function test_a_dotted_directory_is_no_subscription(): void
    {
        $this->log('p1001', 'beispiel.de', 'access.log', [self::NEW_ERA]);
        $getarnt = Site::logsRootIn($this->rig, '.plattenkurve-probe.4242').'/getarnt.example';
        mkdir($getarnt, 0o700, true);
        file_put_contents($getarnt.'/'.Site::ACCESS_LOG, self::NEW_ERA."\n");

        $ergebnis = $this->counted();

        $this->assertCount(1, $ergebnis['domains']);
        $this->assertSame('p1001', $ergebnis['domains'][0]['subscription']);
    }

    /**
     * **Das Budget lässt liegen und verschweigt nicht.**
     *
     * Eine abgelaufene Frist zählt nichts mehr — aber sie meldet jede Domain,
     * die deshalb ungezählt blieb. Ein Ergebnis mit leerer Domainliste und
     * leerer `pending`-Liste wäre die Auskunft „dieser Server hat keinen
     * Verkehr", und die wäre falsch.
     */
    public function test_an_exhausted_budget_leaves_the_rest_pending(): void
    {
        $this->log('p1001', 'beispiel.de', 'access.log', [self::NEW_ERA]);
        $this->log('p1002', 'zweite.de', 'access.log', [self::NEW_ERA]);

        $ergebnis = $this->counted(microtime(true) - 1);

        $this->assertSame([], $ergebnis['domains']);
        $this->assertCount(2, $ergebnis['pending']);
        $this->assertSame(
            [['subscription' => 'p1001', 'domain' => 'beispiel.de'], ['subscription' => 'p1002', 'domain' => 'zweite.de']],
            $ergebnis['pending'],
        );
    }

    /**
     * **Die Summen sagen, was der Nachtlauf protokollieren kann.**
     *
     * „6 Domains gezählt" ist keine Auskunft. „6 Domains, 7 Zeilen, davon 6 aus
     * dem alten Zeitalter" ist eine.
     */
    public function test_the_totals_add_up_over_all_domains(): void
    {
        $this->log('p1001', 'beispiel.de', 'access.log', [self::NEW_ERA, self::OLD_ERA]);
        $this->log('p1001', 'zweite.de', 'access.log', [self::NEW_ERA]);
        $this->log('p1002', 'dritte.de', 'access.log', ['völliger Unrat']);

        $summe = $this->counted()['totals'];

        $this->assertSame(3, $summe['domains']);
        $this->assertSame(3, $summe['files']);
        $this->assertSame(4, $summe['lines']);
        $this->assertSame(2, $summe['parsed']);
        $this->assertSame(1, $summe['legacy']);
        $this->assertSame(1, $summe['unreadable']);
    }

    /**
     * **Eine Domain ohne Protokoll ist keine Lücke.**
     *
     * Fünf der sechs Domains auf `cloudsrv24` hatten am Messtag keine einzige
     * Zeile. Sie müssen trotzdem in der Liste stehen — sonst wäre „hat noch
     * niemand aufgerufen" nicht von „wurde nicht gezählt" zu unterscheiden.
     */
    public function test_a_domain_without_a_log_is_still_reported(): void
    {
        mkdir(Site::logsRootIn($this->rig, 'p1001').'/stille.de', 0o700, true);

        $domain = $this->counted()['domains'][0];

        $this->assertSame('stille.de', $domain['domain']);
        $this->assertSame(0, $domain['files']);
        $this->assertSame(0, $domain['lines']);
        $this->assertSame([], $domain['days']);
    }

    /**
     * **Die Wurzel kommt aus der Konstante und nie aus den Argumenten.**
     *
     * Das ist die erste Grenze und kein Stil: Eine Operation, der man sagen
     * kann, wo sie lesen soll, ist ein Leser für beliebige Dateien mit
     * Systemrechten. Geprüft wird am Quelltext, weil ein Aufruf mit `root` im
     * Argument heute schlicht ignoriert würde — und ein Test, der das zeigt,
     * bliebe auch dann grün, wenn jemand die Zeile später einbaut.
     */
    /**
     * **Und die Operation setzt den Pfad nicht selbst zusammen.**
     *
     * Die Prüfungen darüber liefen auch dann durch, wenn `WebAccessCount` den
     * Aufbau `…/logs/…` eigenhändig bildete — solange beide Zeichenketten
     * zufällig übereinstimmen. Sie gingen erst auseinander, wenn jemand
     * {@see Site} aufräumt, und dann stünde der Fehler in einer Operation, die
     * seit Monaten niemand angefasst hat.
     *
     * > **Zwei Stellen, die dieselbe Zeichenkette bilden, sind kein Fehler —
     * > sie sind einer, der auf seinen Tag wartet.**
     */
    public function test_the_operation_does_not_build_the_path_itself(): void
    {
        $quelle = file_get_contents(__DIR__.'/../../agent/src/Ops/WebAccessCount.php');

        $this->assertIsString($quelle);
        $this->assertStringContainsString('Site::logsRootIn(', $quelle);
        $this->assertStringNotContainsString("'/logs'", $quelle);
        $this->assertStringNotContainsString("'/logs/'", $quelle);
    }

    public function test_the_root_never_comes_from_the_arguments(): void
    {
        $quelle = file_get_contents(__DIR__.'/../../agent/src/Ops/WebAccessCount.php');

        $this->assertIsString($quelle);
        $this->assertStringContainsString('self::overRoot(self::VHOSTS,', $quelle);

        preg_match_all("/\\\$args\\['([a-z_]+)'\\]/", $quelle, $treffer);

        $this->assertSame(['budget_seconds'], array_values(array_unique($treffer[1])));
    }
}
