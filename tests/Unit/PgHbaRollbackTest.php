<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\ManagedBlock;
use SrvPanel\Agent\Ops\PgRemoteAccess;
use SrvPanel\Agent\Pg\Hba;

/**
 * Eine ungültige Zeile führt zur **alten Datei** zurück — nicht zu einer Meldung.
 *
 * ## Warum dieser Wächter der wichtigste dieser Stufe ist
 *
 * Eine kaputte `pg_hba.conf` ist bei einem *Reload* folgenlos und bei einem
 * *Neustart* tödlich (`docs/38 §14.2`, M16 und M17). Gemessen am 11. August
 * 2026 auf einem echten Debian-Cluster, mit genau der Datei, die ohne Schritt 4
 * liegenbliebe:
 *
 *     LOG:  invalid IP mask "scram-sha-256": Name or service not known
 *     pg_ctl: could not start server
 *     16  main  5432  down
 *
 * **Der Cluster kommt nicht hoch.** Alle Kunden ohne Datenbank, und die Ursache
 * ist eine Datei, die vor einem Monat geschrieben wurde — die teuerste Bauart
 * von Fehler, die dieses Projekt kennt: einer, der zwischen Ursache und Wirkung
 * eine Wartungsfrist legt.
 *
 * ## Was hier geprüft wird, und was nicht
 *
 * **Nicht der Quelltext.** `docs/38 §18` verlangt „gegen einen echten Cluster,
 * nicht am Quelltext", und der Grund ist: Ein `assertStringContainsString` auf
 * `ManagedBlock::put($path, $before)` bestünde auch dann, wenn der Aufruf in einem Zweig
 * stünde, den nichts erreicht.
 *
 * **Ein Cluster steht in der CI aber nicht zur Verfügung.** Was hier deshalb
 * läuft, ist der *echte Ablauf* gegen eine *echte Datei* — {@see
 * PgRemoteAccess::apply()}, dieselbe Methode, die die Operation ruft — und nur
 * das Urteil von PostgreSQL ist gestellt. Das ist die Hälfte, die sich ohne
 * Server prüfen lässt; die andere steht gemessen im Klassenkopf oben und in
 * `docs/38 §14.2`.
 *
 * **Der Bruch dazu** (`tests/waechter-brechen.sh`): den Rückweg durch ein
 * `log()` ersetzen — also `ManagedBlock::put($path, $before)` aus {@see
 * PgRemoteAccess::apply()} nehmen und nur noch werfen.
 */
final class PgHbaRollbackTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'hba').'.conf';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path.'.srvpanel.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    /**
     * Der Ausgangszustand: Debians Vorgabe **mit** der Zeile für das
     * Zurückspielen darüber.
     *
     * **Die Zeile gehört in jeden Fall dieses Tests**, denn sie ist das, was
     * der Rückweg zu verlieren droht — der zweite verwaltete Bereich in
     * derselben Datei (`docs/38 §14`, Frage 2 der Übergabe).
     */
    private function existing(): string
    {
        return Hba::prepend(
            "local   all             postgres                                peer\n"
            ."local   all             all                                     peer\n"
            ."host    all             all             127.0.0.1/32            scram-sha-256\n"
            .'# Eine Zeile des Betreibers, die niemand anfassen darf.'."\n"
            ."host    all             +buchhaltung    10.0.0.0/8              reject\n"
        );
    }

    /**
     * Eine Zeile, die PostgreSQL annimmt — für den Erfolgsfall.
     *
     * **Die Marke steht auf einer eigenen Zeile, und das ist kein Geschmack.**
     * In einem einzeiligen Block ist `@return` Fliesstext und die Angabe damit
     * weg; PHPStan meldet „no value type specified", und zwar erst in der CI.
     * Genau so ist diese Zeile am 11. August 2026 rot geworden — die Falle
     * steht in `CLAUDE.md` und hat trotzdem wieder zugeschlagen.
     *
     * @return list<string>
     */
    private function good(): array
    {
        return [Hba::rule('x7f3a91c2b40e15d6_shop', 'x7f3a91c2b40e15d6_web', '203.0.113.5/32')];
    }

    /**
     * Der Rückweg legt die Datei Byte für Byte zurück.
     *
     * **Byte für Byte und nicht „im Wesentlichen".** Was hier verlorengehen
     * kann, sind drei verschiedene Dinge — die Zeile für das Zurückspielen, die
     * Regel des Betreibers und die Reihenfolge, in der beide stehen —, und
     * jedes davon fällt einzeln nicht auf.
     */
    public function test_a_rejected_block_restores_the_file_byte_for_byte(): void
    {
        file_put_contents($this->path, $this->existing());
        $vorher = (string) file_get_contents($this->path);

        try {
            PgRemoteAccess::apply(
                $this->path,
                $this->good(),
                static function (): void {},
                static fn (): array => [['line' => 138, 'error' => 'invalid IP mask "scram-sha-256"']],
            );

            $this->fail('Ein abgewiesener Block muss den Vorgang scheitern lassen.');
        } catch (AgentException $error) {
            $this->assertStringContainsString(
                'Zeile 138',
                $error->getMessage(),
                'Die Meldung nennt die Zeilennummer nicht — ohne sie sucht der Betreiber in 130 Zeilen.',
            );
        }

        $this->assertSame(
            $vorher,
            (string) file_get_contents($this->path),
            'Nach dem Rückweg steht nicht mehr die Datei da, die vorher dastand.',
        );
    }

    /**
     * Und die Meldung nennt den **Text** der beanstandeten Zeile.
     *
     * **Die Nummer allein zeigt auf eine Datei, die es nicht mehr gibt.**
     * Gemessen im Abnahmelauf (`docs/45 §5`): 140 Zeilen vor dem Versuch,
     * „Zeile 136" in der Meldung. Zwischendurch war der alte Block heraus und
     * der neue ans Ende gehängt — und der Rückweg hat diesen Stand längst
     * wieder ersetzt, bevor jemand die Meldung liest.
     *
     * > **Eine Zeilennummer aus einem Zwischenstand zeigt auf eine Datei, die
     * > niemand mehr öffnen kann.**
     *
     * Der Text ist in beiden Ständen derselbe, danach lässt sich also suchen.
     * Die Nummer bleibt daneben stehen — sie ist nicht falsch, sie ist nur
     * allein nicht genug.
     *
     * **Der Bruch dazu** (`tests/waechter-brechen.sh`): in `apply()` wieder
     * `sprintf('Zeile %d: %s', …)` ohne den Text bilden.
     */
    public function test_the_message_quotes_the_offending_line(): void
    {
        file_put_contents($this->path, $this->existing());

        /*
         * Die Nummer wird **gerechnet und nicht getippt**: Sie muss auf die
         * Regel in der abgewiesenen Fassung zeigen, und wo die steht, hängt an
         * {@see ManagedBlock::render()}. Eine feste Zahl hier ginge beim nächsten
         * Zusatz zum Block lautlos daneben — und der Test bestünde weiter,
         * weil er dann den Zweig ohne Text prüft.
         */
        $kandidat = explode("\n", ManagedBlock::render($this->existing(), $this->good(), $this->path));
        $nummer = 0;

        foreach ($kandidat as $index => $zeile) {
            if (str_contains($zeile, '203.0.113.5/32')) {
                $nummer = $index + 1;

                break;
            }
        }

        $this->assertGreaterThan(0, $nummer, 'Die Regel steht nicht in der abgewiesenen Fassung.');

        try {
            PgRemoteAccess::apply(
                $this->path,
                $this->good(),
                static function (): void {},
                static fn (): array => [['line' => $nummer, 'error' => 'erfunden']],
            );

            $this->fail('Ein abgewiesener Block muss den Vorgang scheitern lassen.');
        } catch (AgentException $error) {
            $this->assertStringContainsString(
                '203.0.113.5/32',
                $error->getMessage(),
                'Die Meldung nennt den Text der beanstandeten Zeile nicht. Die Nummer allein zählt in '
                .'einer Fassung, die der Rückweg schon ersetzt hat.',
            );

            $this->assertStringContainsString(
                'nicht in der Datei, die jetzt dasteht',
                $error->getMessage(),
                'Die Meldung sagt nicht, dass die Nummer in einer anderen Fassung zählt — dann sucht '
                .'der Betreiber an der Nummer in der falschen Datei.',
            );
        }
    }

    /**
     * **Der Rückweg darf den anderen verwalteten Bereich nicht mitnehmen.**
     *
     * Das ist der eigentliche Fund dieser Stufe. `Hba::ensure()` stellt der
     * Datei eine Zeile voran, damit das Zurückspielen überhaupt anfangen kann;
     * sie muss über `local all all peer` stehen, sonst kommt die befristete
     * Rolle nicht herein — gemessen: `FATAL: Peer authentication failed`.
     *
     * Nimmt der Rückweg sie weg, **merkt es niemand**: Der Fernzugriff meldet
     * einen sauberen Fehlschlag, der Server läuft, und erst das nächste
     * Zurückspielen — Wochen später — scheitert an einer Meldung, die auf eine
     * Authentifizierungsmethode zeigt statt auf diesen Vorgang hier.
     *
     * > **Ein Fehlerweg, der selbst fehlschlagen kann, ist kein Fehlerweg.**
     */
    public function test_the_rollback_keeps_the_line_for_restoring_a_dump(): void
    {
        file_put_contents($this->path, $this->existing());

        try {
            PgRemoteAccess::apply(
                $this->path,
                $this->good(),
                static function (): void {},
                static fn (): array => [['line' => 138, 'error' => 'erfunden']],
            );
        } catch (AgentException) {
            // Erwartet — geprüft wird, was die Datei danach ist.
        }

        $danach = (string) file_get_contents($this->path);

        $this->assertStringContainsString(
            Hba::RULE,
            $danach,
            'Der Rückweg hat die Zeile für das Zurückspielen mitgenommen. Auffallen würde das erst '
            .'beim nächsten Zurückspielen, mit einer Meldung über peer-Authentifizierung.',
        );

        $this->assertLessThan(
            strpos($danach, 'local   all             all'),
            strpos($danach, Hba::RULE),
            'Die Zeile steht nicht mehr über der peer-Zeile und kommt damit nie zum Zug.',
        );
    }

    /**
     * Nach dem Rückweg wird ein zweites Mal nachgeladen.
     *
     * **Sonst bedient der Server weiter aus einer Datei, die es nicht mehr
     * gibt.** Ein Reload ist gnädig (M16): Er behält die alten Regeln, wenn die
     * neue Datei nicht trägt. Genau deshalb steht der Server nach Schritt 3 auf
     * einem Stand, den die Platte nicht mehr trägt — und ohne den zweiten
     * Reload bliebe er darauf, bis irgendwann jemand neu startet.
     */
    public function test_the_rollback_reloads_again(): void
    {
        file_put_contents($this->path, $this->existing());

        $reloads = 0;

        try {
            PgRemoteAccess::apply(
                $this->path,
                $this->good(),
                static function () use (&$reloads): void {
                    $reloads++;
                },
                static fn (): array => [['line' => 138, 'error' => 'erfunden']],
            );
        } catch (AgentException) {
            // Erwartet.
        }

        $this->assertSame(
            2,
            $reloads,
            'Nach dem Rückweg fehlt der zweite Reload — der Server bedient dann aus einer Datei, '
            .'die auf der Platte nicht mehr steht.',
        );
    }

    /**
     * Und im Erfolgsfall bleibt der Block stehen — mit allem drumherum.
     *
     * **Die Gegenprobe zum Rückweg.** Ein Wächter, der nur den Fehlerfall
     * kennt, bestünde auch dann, wenn die Operation *immer* zurückrollte.
     */
    public function test_an_accepted_block_stays_and_leaves_everything_else_alone(): void
    {
        file_put_contents($this->path, $this->existing());
        $vorher = (string) file_get_contents($this->path);

        $ergebnis = PgRemoteAccess::apply(
            $this->path,
            $this->good(),
            static function (): void {},
            static fn (): array => [],
        );

        $danach = (string) file_get_contents($this->path);

        $this->assertTrue($ergebnis['changed'], 'Ein neuer Block ist eine Änderung.');
        $this->assertSame($this->good(), ManagedBlock::managed($danach), 'Der Block steht nicht in der Datei.');
        $this->assertStringContainsString(Hba::RULE, $danach, 'Die Zeile für das Zurückspielen fehlt.');
        $this->assertStringContainsString(
            'host    all             +buchhaltung    10.0.0.0/8              reject',
            $danach,
            'Die Regel des Betreibers ist nicht mehr da — der Bestand ist Gesetz.',
        );

        $this->assertSame(
            $vorher,
            ManagedBlock::render($danach, [], $this->path),
            'Nimmt man den Block wieder heraus, steht nicht der Ausgangsstand da — irgendetwas '
            .'ausserhalb der Marken hat sich mitverändert.',
        );
    }

    /**
     * Der Block steht **hinter** dem Bestand und nicht davor.
     *
     * Sonst gewänne eine Zeile von uns über ein `reject` des Betreibers: In
     * `pg_hba.conf` entscheidet die erste passende Zeile, und `docs/28 §6` hält
     * dieselbe Falle für nginx fest. „Der Bestand ist Gesetz" ist sonst eine
     * Behauptung.
     */
    public function test_the_block_goes_below_what_the_operator_wrote(): void
    {
        file_put_contents($this->path, $this->existing());

        PgRemoteAccess::apply(
            $this->path,
            $this->good(),
            static function (): void {},
            static fn (): array => [],
        );

        $danach = (string) file_get_contents($this->path);

        $this->assertGreaterThan(
            strpos($danach, '+buchhaltung'),
            strpos($danach, ManagedBlock::BEGIN),
            'Der verwaltete Block steht über der Regel des Betreibers und hebelt sie damit aus.',
        );
    }

    /**
     * Zweimal derselbe Sollzustand ändert nichts und lädt nicht neu.
     *
     * **Ein Signal an den Server für nichts ist nicht umsonst** — und, wichtiger:
     * Eine Operation, die bei jedem Aufruf schreibt, macht aus jedem Formular
     * eines Kunden ein Risiko an einer Datei, deren Fehler beim nächsten
     * Neustart zündet.
     */
    public function test_the_same_state_twice_writes_nothing(): void
    {
        file_put_contents($this->path, $this->existing());

        $reloads = 0;
        $reload = static function () use (&$reloads): void {
            $reloads++;
        };
        $clean = static fn (): array => [];

        PgRemoteAccess::apply($this->path, $this->good(), $reload, $clean);
        $nachErstem = (string) file_get_contents($this->path);

        $zweites = PgRemoteAccess::apply($this->path, $this->good(), $reload, $clean);

        $this->assertFalse($zweites['changed'], 'Derselbe Stand gilt als Änderung.');
        $this->assertSame(1, $reloads, 'Der zweite Lauf hat noch einmal neu geladen.');
        $this->assertSame($nachErstem, (string) file_get_contents($this->path), 'Die Datei hat sich bewegt.');
    }

    /**
     * Eine leere Liste nimmt den Block heraus — und lässt keinen leeren Rumpf.
     *
     * Ein `# BEGIN` mit `# END` und nichts dazwischen sähe beim nächsten Lesen
     * aus wie „ein Bereich ohne Regeln" und wäre in Wahrheit „hier war einmal
     * einer". Der Unterschied fällt genau dann auf, wenn jemand ihn von Hand
     * beurteilen muss.
     */
    public function test_no_networks_leaves_no_block_behind(): void
    {
        file_put_contents($this->path, $this->existing());
        $vorher = (string) file_get_contents($this->path);

        PgRemoteAccess::apply($this->path, $this->good(), static function (): void {}, static fn (): array => []);
        PgRemoteAccess::apply($this->path, [], static function (): void {}, static fn (): array => []);

        $danach = (string) file_get_contents($this->path);

        $this->assertSame($vorher, $danach, 'Nach dem Entfernen steht nicht der Ausgangsstand da.');
        $this->assertStringNotContainsString(ManagedBlock::BEGIN, $danach, 'Ein leerer Rumpf ist liegengeblieben.');
    }

    /**
     * **Die Datei ist gesperrt, solange der Vorgang läuft — vom Lesen bis zum Rückweg.**
     *
     * Der Grund ist der zweite Schreiber in derselben Datei
     * ({@see Hba::ensure()}), und er ist nachgestellt worden,
     * nicht ausgedacht. Am 11. August 2026 verlor jeder der drei möglichen
     * Verläufe etwas:
     *
     * | wer schreibt | was verschwindet |
     * |---|---|
     * | der Block auf einem Stand von vor `ensure()` | die Zeile |
     * | `ensure()` auf einem Stand von vor dem Block | der Block |
     * | **der Rückweg** legt den Stand von Schritt 1 zurück | die Zeile |
     *
     * Der Agent gabelt je Verbindung, zwei Operationen sind also zwei Prozesse
     * — ein Merker im Speicher hilft hier nichts, und deshalb wird die Sperre
     * selbst geprüft und nicht ein Ergebnis, das auch ohne sie herauskäme.
     *
     * **Gemessen wird sie von einem zweiten Deskriptor auf dieselbe Datei.**
     * `flock` sperrt je *offener Datei* und nicht je Prozess: Ein zweites
     * `fopen` mit `LOCK_NB` scheitert auch im selben Prozess, wenn das erste
     * die Sperre hält. Genau diese Eigenschaft hat den Deadlock erzeugt, den
     * die verschachtelte Sperre einmal hatte — hier trägt sie den Wächter.
     */
    public function test_the_file_is_locked_while_the_block_is_written(): void
    {
        file_put_contents($this->path, $this->existing());

        $gesperrt = null;

        PgRemoteAccess::apply(
            $this->path,
            $this->good(),
            function () use (&$gesperrt): void {
                $zweiter = fopen($this->path.'.srvpanel.lock', 'c');

                if ($zweiter === false) {
                    return;
                }

                $gesperrt = ! flock($zweiter, LOCK_EX | LOCK_NB);
                fclose($zweiter);
            },
            static fn (): array => [],
        );

        $this->assertTrue(
            $gesperrt,
            'Während der Block geschrieben wird, ist pg_hba.conf nicht gesperrt. Ein zweiter Vorgang '
            .'— etwa Hba::ensure() aus einem Zurückspielen — kann sich dazwischenschieben, und der '
            .'Rückweg legt danach einen Stand zurück, in dem seine Zeile fehlt.',
        );
    }

    /**
     * Und die Sperre steht sich nicht selbst im Weg.
     *
     * **Der Fund, der zwei Minuten Stillstand gekostet hat.** `flock` sperrt je
     * offener Datei; ein zweiter `fopen`-Aufruf im selben Prozess wartet auf
     * den ersten und damit auf sich selbst. Die Operation hatte genau eine
     * Verschachtelung zu viel und stand — kein Fehler, keine Meldung, nur
     * nichts.
     *
     * Ein Wächter dafür ist unbequem (schlägt er fehl, hängt er), und er gehört
     * trotzdem hierher: Wer den nächsten Aufrufer schreibt, der die Sperre schon
     * hält, soll es an dieser Stelle erfahren und nicht an einem Vorgang, der
     * nie zurückkommt.
     */
    public function test_the_lock_does_not_block_itself(): void
    {
        file_put_contents($this->path, $this->existing());

        $ergebnis = ManagedBlock::locked($this->path, fn (): array => PgRemoteAccess::apply(
            $this->path,
            $this->good(),
            static function (): void {},
            static fn (): array => [],
        ));

        $this->assertTrue($ergebnis['changed'], 'Der verschachtelte Aufruf hat nichts geschrieben.');
    }

    /**
     * Ein `# BEGIN` ohne `# END` wird nicht repariert, sondern gemeldet.
     *
     * **Geraten wird an dieser Datei nicht.** Wer die Endmarke von Hand
     * entfernt hat, hinterlässt einen Zustand, in dem „bis wohin gehört uns
     * das" keine Antwort hat — und die falsche Antwort schreibt entweder eine
     * Regel des Betreibers weg oder lässt einen zweiten Block entstehen.
     */
    public function test_a_half_written_block_stops_instead_of_guessing(): void
    {
        file_put_contents($this->path, $this->existing()."\n".ManagedBlock::BEGIN."\nhost    a   b   c   d\n");
        $vorher = (string) file_get_contents($this->path);

        $this->expectException(AgentException::class);

        try {
            PgRemoteAccess::apply($this->path, $this->good(), static function (): void {}, static fn (): array => []);
        } finally {
            $this->assertSame(
                $vorher,
                (string) file_get_contents($this->path),
                'Die Datei ist trotz des Abbruchs verändert worden.',
            );
        }
    }
}
