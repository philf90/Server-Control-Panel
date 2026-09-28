<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Metrics\Daily;
use App\Support\Web\AccessCounts;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\WebAccessCount;
use SrvPanel\Agent\Site;
use Tests\Support\WithoutPhpComments;

/**
 * Ein ruhiger Vortag bekommt eine Null — und nur, wer ganz gelesen ist.
 *
 * ## Der Fund
 *
 * `docs/138 §0` Punkt 2, 28. September 2026, beim Ausschreiben des
 * Abnahmelaufs für B3: Eine Domain, auf die an einem Tag niemand zugreift,
 * lieferte für ihn keine Zeile, und die Tabelle bekam nichts. „Dreissig Zeilen
 * je Abo und Kennzahl" galt damit nur für Abonnements mit Verkehr an jedem Tag,
 * und die Kurve eines ruhigen rückte ihre Tage zusammen. `TrafficEraTest` hielt
 * die Lücke ausdrücklich fest und schob sie an B3 — gefüllt hat sie dort
 * niemand.
 *
 * > **Ein Tag ohne Anfrage ist kein Tag ohne Zahl — die Zahl ist null, und wer
 * > sie nicht ablegt, lässt die Kurve behaupten, es habe ihn nicht gegeben.**
 *
 * ## Die Regel, entschieden vom Betreiber
 *
 * Eine Null bekommt nur eine Domain, deren Protokolle ganz gelesen sind:
 * mindestens eine Datei, keine unlesbare Zeile, nicht liegengeblieben. Sonst
 * wird aus „nicht gemessen" ein „nichts gewesen". Gemessen wird beides — die
 * Null, wo sie hingehört, und ihr Ausbleiben an jeder der drei Stellen, an der
 * sie nicht hingehört.
 *
 * > **Eine Null, die aus „nicht gelesen" entsteht, ist schlimmer als keine —
 * > sie sieht aus wie eine Messung.**
 *
 * ## Was hier echt ist
 *
 * Die Aufteilung ist {@see AccessCounts::split()} selbst. Die Naht davor ist
 * es auch: Ob eine Domain ganz gelesen ist, sagen zwei Felder, die der Agent je
 * Domain schreibt, und ein Feld, das gelesen und nicht mehr geschrieben wird,
 * macht hier aus jeder Null eine Lücke — still. Der letzte Fall fährt deshalb
 * {@see WebAccessCount::overRoot()} über echte Dateien.
 */
final class QuietDayTest extends TestCase
{
    use WithoutPhpComments;

    private const HEUTE = '2026-09-21';

    private const GESTERN = '2026-09-20';

    private string $rig = '';

    protected function tearDown(): void
    {
        if ($this->rig !== '' && is_dir($this->rig)) {
            $this->remove($this->rig);
        }

        parent::tearDown();
    }

    /**
     * Eine Domain, wie der Agent sie meldet.
     *
     * @param  array<string, mixed>  $tage
     * @return array<string, mixed>
     */
    private function domain(array $tage, int $files = 3, int $unreadable = 0, string $domain = 'beispiel.de'): array
    {
        return [
            'subscription' => 'p1001',
            'domain' => $domain,
            'files' => $files,
            'unreadable' => $unreadable,
            'days' => $tage,
        ];
    }

    /** @return array<string, int> */
    private function tag(int $legacy = 0): array
    {
        return ['requests' => 4, 'sent' => 3011, 'received' => 372, 'errors' => 1, 'legacy' => $legacy];
    }

    /**
     * @param  list<array<string, mixed>>  $domains
     * @return array<string, mixed>
     */
    private function split(array $domains): array
    {
        return AccessCounts::split(['domains' => $domains], self::HEUTE);
    }

    public function test_a_domain_read_whole_without_a_line_yesterday_is_quiet(): void
    {
        foreach ([[], [self::HEUTE => $this->tag()]] as $tage) {
            $topf = $this->split([$this->domain($tage)]);

            $this->assertSame(
                [['subscription' => 'p1001', 'domain' => 'beispiel.de', 'day' => self::GESTERN]],
                $topf['quiet'],
                'Ganz gelesen und am Vortag keine Zeile — das ist eine Null, und zwar für den Vortag.',
            );
            $this->assertSame([], $topf['unread']);
            $this->assertSame([], $topf['countable']);
        }
    }

    /**
     * **Zeilen an anderen Tagen machen den Vortag nicht laut.** Auf
     * `cloudsrv24` liegen in einer Domain ohne Verkehr acht Zeilen aus dem
     * August im alten Format, und sie bleiben im Lesebereich (`docs/134 §7`,
     * Befund 8). Für den Vortag sagen sie nichts.
     */
    public function test_lines_on_other_days_leave_yesterday_quiet(): void
    {
        $topf = $this->split([$this->domain(['2026-08-15' => $this->tag(legacy: 8), self::HEUTE => $this->tag()])]);

        $this->assertCount(1, $topf['quiet']);
        $this->assertSame([], $topf['skipped'], 'Ein alter Tag im alten Format ist kein übersprungener Vortag.');
    }

    public function test_a_counted_yesterday_is_not_quiet(): void
    {
        $topf = $this->split([$this->domain([self::GESTERN => $this->tag()])]);

        $this->assertCount(1, $topf['countable']);
        $this->assertSame([], $topf['quiet'], 'Ein Vortag mit Zeilen bekommt seine Zahl und nicht zusätzlich eine Null.');
        $this->assertSame([], $topf['unread']);
    }

    public function test_a_skipped_yesterday_is_not_quiet(): void
    {
        $topf = $this->split([$this->domain([self::GESTERN => $this->tag(legacy: 1)])]);

        $this->assertCount(1, $topf['skipped']);
        $this->assertSame([], $topf['quiet'], 'Ein Tag im gemischten Format hatte Zeilen — eine Null wäre erfunden.');
        $this->assertSame([], $topf['unread']);
    }

    /**
     * **Eine unlesbare Zeile trägt keinen Tag, und es kann der Vortag gewesen
     * sein.** Eine abgeschnittene `.gz` liefert genau so eine — ihre Zeilen
     * bis zum Schnitt und dazu eine halbe (`AccessLog::countFile()`).
     */
    public function test_an_unreadable_line_leaves_yesterday_without_a_number(): void
    {
        $topf = $this->split([$this->domain([], unreadable: 1)]);

        $this->assertSame([], $topf['quiet'], 'Mit einer unlesbaren Zeile ist die Domain nicht ganz gelesen.');
        $this->assertSame(
            [['subscription' => 'p1001', 'domain' => 'beispiel.de', 'day' => self::GESTERN, 'files' => 3, 'unreadable' => 1]],
            $topf['unread'],
        );
    }

    /** Ohne eine einzige Datei gibt es kein Protokoll, das „nichts" sagen könnte. */
    public function test_a_domain_without_a_file_has_no_log_to_say_nothing(): void
    {
        $topf = $this->split([$this->domain([], files: 0)]);

        $this->assertSame([], $topf['quiet']);
        $this->assertCount(1, $topf['unread']);
        $this->assertSame(0, $topf['unread'][0]['files']);
    }

    /**
     * **Ein Vortag, dessen Werte sich nicht lesen lassen, hatte trotzdem
     * Zeilen.** Gefragt wird deshalb, ob er vorkommt, bevor gefragt wird, ob
     * sein Inhalt taugt.
     */
    public function test_a_yesterday_whose_values_cannot_be_read_is_not_quiet(): void
    {
        $topf = $this->split([$this->domain([self::GESTERN => 'Unrat'])]);

        $this->assertSame([], $topf['quiet']);
        $this->assertSame([], $topf['unread']);
        $this->assertSame([], $topf['countable']);
    }

    /**
     * **Eine Meldung, der die beiden Felder fehlen, gibt keine Null.** Schickt
     * der Agent `files` oder `unreadable` eines Tages nicht mehr, wird aus
     * jeder Null eine Lücke und nicht umgekehrt — die Richtung, in die ein
     * solcher Fehler fallen muss.
     */
    public function test_a_report_without_the_two_fields_leaves_no_zero(): void
    {
        $topf = AccessCounts::split(['domains' => [[
            'subscription' => 'p1001',
            'domain' => 'beispiel.de',
            'days' => [],
        ]]], self::HEUTE);

        $this->assertSame([], $topf['quiet']);
        $this->assertCount(1, $topf['unread']);
    }

    /** Was der Agent liegen liess, ist weder ruhig noch in einem Topf des Tages. */
    public function test_a_pending_domain_is_neither_quiet_nor_unread(): void
    {
        $topf = AccessCounts::split([
            'domains' => [],
            'pending' => [['subscription' => 'p1001', 'domain' => 'beispiel.de']],
        ], self::HEUTE);

        $this->assertSame(1, $topf['incomplete']);
        $this->assertSame([], $topf['quiet']);
        $this->assertSame([], $topf['unread']);
    }

    /**
     * **Die Naht zum Agenten, über echte Dateien.** Drei Domains unter einem
     * Abonnement: eine mit einer Zeile vom Vortag, eine, deren einzige Zeile
     * drei Tage älter ist, und eine ohne Datei. Gezählt wird mit
     * {@see WebAccessCount::overRoot()}, aufgeteilt mit {@see AccessCounts::split()}.
     *
     * Hiesse eines der beiden Felder beim Agenten anders, stünde die ruhige
     * Domain unter „nicht ganz gelesen", und dieser Fall wäre rot — die
     * übrigen Fälle hier sähen es nicht, weil sie die Meldung selbst bauen.
     */
    public function test_the_agent_reports_what_makes_a_domain_quiet(): void
    {
        $this->rig = sys_get_temp_dir().'/srvpanel-ruhig-'.bin2hex(random_bytes(6));

        $this->line('beschaeftigt.de', self::GESTERN);
        $this->line('ruhig.de', '2026-09-17');
        mkdir($this->logs('leer.de'), 0o700, true);

        $topf = AccessCounts::split(WebAccessCount::overRoot($this->rig, microtime(true) + 30), self::HEUTE);

        $this->assertSame(['beschaeftigt.de'], array_column($topf['countable'], 'domain'));
        $this->assertSame(
            [['subscription' => 'p1001', 'domain' => 'ruhig.de', 'day' => self::GESTERN]],
            $topf['quiet'],
            'Die ruhige Domain muss als ruhig ankommen — sonst liest der Nachtlauf ein Feld, das der Agent nicht schreibt.',
        );
        $this->assertSame(
            [['subscription' => 'p1001', 'domain' => 'leer.de', 'day' => self::GESTERN, 'files' => 0, 'unreadable' => 0]],
            $topf['unread'],
        );
    }

    /**
     * **Und der Nachtlauf gibt beides weiter.** Die Regel oben steht in
     * {@see AccessCounts}; ob `srvpanel:traffic` die ruhigen Tage und die
     * Lücken auch an {@see Daily::record()} gibt, sagt dort niemand. Gelesen
     * wird die Argumentliste des Aufrufs, ohne Kommentare — der Absatz darüber
     * nennt dieselben Namen.
     */
    public function test_the_nightly_run_hands_over_the_quiet_days_and_the_gaps(): void
    {
        $quelle = file_get_contents(__DIR__.'/../../app/Console/Commands/CollectTraffic.php');

        $this->assertIsString($quelle);
        $this->assertSame(
            1,
            preg_match('/\$daily->record\((?<argumente>.*?)\);/s', $this->withoutComments($quelle), $aufruf),
            'Der Nachtlauf ruft Daily::record() nicht mehr — oder nicht mehr in dieser Form.',
        );

        foreach (["\$split['countable']", "\$split['quiet']", "\$split['skipped']", "\$split['unread']"] as $topf) {
            $this->assertStringContainsString(
                $topf,
                $aufruf['argumente'],
                sprintf('Der Nachtlauf gibt %s nicht an Daily::record() weiter.', $topf),
            );
        }
    }

    private function logs(string $domain): string
    {
        return Site::logsRootIn($this->rig, 'p1001').'/'.$domain;
    }

    /** Eine Zeile im Format `srvpanel` an einem Tag — dieselbe Form wie in `TrafficRotationTest`. */
    private function line(string $domain, string $day): void
    {
        if (! is_dir($this->logs($domain))) {
            mkdir($this->logs($domain), 0o700, true);
        }

        file_put_contents($this->logs($domain).'/access.log', sprintf(
            "203.0.113.9 - - [%s:12:00:00 +0200] \"GET / HTTP/1.1\" 200 512 \"-\" \"ruhig\" 700 90\n",
            (new DateTimeImmutable($day))->format('d/M/Y'),
        ), FILE_APPEND);
    }

    private function remove(string $pfad): void
    {
        foreach (scandir($pfad) ?: [] as $eintrag) {
            if ($eintrag === '.' || $eintrag === '..') {
                continue;
            }

            $voll = $pfad.'/'.$eintrag;

            is_dir($voll) && ! is_link($voll) ? $this->remove($voll) : unlink($voll);
        }

        rmdir($pfad);
    }
}
