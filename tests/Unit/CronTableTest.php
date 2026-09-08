<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Cron\CronFile;
use SrvPanel\Agent\CronState;

/**
 * Wie eine Zeile mit Benutzerfeld gelesen wird — A6, `docs/111 §5`.
 *
 * ## Die Prüfkörper sind gemessen und nicht erfunden
 *
 * Sie stehen wörtlich so in den Dateien dieses Containers (`docs/81 §2.3t`):
 * `/etc/crontab` setzt seine Felder mit **Tabulatoren**, `/etc/cron.d/php` mit
 * **mehreren Leerzeichen**. Ein Leser, der eines von beiden voraussetzt, liest
 * die andere Datei falsch — und zwar lautlos: Aus dem Benutzerfeld wird dann
 * ein Stück des Kommandos oder umgekehrt.
 *
 * ## Warum die `@`-Form eigens geprüft wird
 *
 * Gemessen (M4, `cron -n -x load,pars,sch`): `/etc/cron.d` nimmt acht `@`-Namen
 * mit **einem** Zeitfeld statt fünf. Wer eine solche Zeile in fünf zerlegt,
 * zeigt `root` als Tag des Monats an, und das Kommando fehlt ganz.
 */
final class CronTableTest extends TestCase
{
    /** So steht es in `/etc/crontab` — die Felder mit Tabulatoren gesetzt. */
    private const CRONTAB = "# /etc/crontab: system-wide crontab\n"
        ."\n"
        ."SHELL=/bin/sh\n"
        ."#PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n"
        ."\n"
        ."17 *\t* * *\troot\tcd / && run-parts --report /etc/cron.hourly\n"
        ."25 6\t* * *\troot\ttest -x /usr/sbin/anacron || { cd / && run-parts --report /etc/cron.daily; }\n";

    /** So steht es in `/etc/cron.d/php` — die Felder mit mehreren Leerzeichen. */
    private const PHP_FILE = "# Look for and purge old sessions every 30 minutes\n"
        ."09,39 *     * * *     root   [ -x /usr/lib/php/sessionclean ] && if [ ! -d /run/systemd/system ]; then /usr/lib/php/sessionclean; fi\n";

    /**
     * Getrennt wird an Leerraum und nicht an einem Leerzeichen.
     */
    public function test_the_fields_are_split_at_whitespace(): void
    {
        $tabelle = $this->tabelle(self::CRONTAB);

        $this->assertCount(2, $tabelle['entries'], 'Aus zwei Zeilen werden zwei Einträge.');

        $this->assertSame('17 * * * *', $tabelle['entries'][0]['schedule']);
        $this->assertSame('root', $tabelle['entries'][0]['user']);
        $this->assertSame(
            'cd / && run-parts --report /etc/cron.hourly',
            $tabelle['entries'][0]['command'],
            'Das Kommando bleibt ungeteilt — es enthält Leerzeichen.',
        );
    }

    /**
     * Und dieselbe Form mit Leerzeichen statt Tabulatoren.
     *
     * **Beide Prüfkörper stehen nebeneinander, weil erst sie zusammen etwas
     * belegen.** Ein Leser, der an `\t` trennt, käme mit `/etc/crontab` durch
     * und stürbe an `/etc/cron.d/php`; einer, der an `' '` trennt, umgekehrt.
     */
    public function test_the_same_reader_takes_spaces(): void
    {
        $tabelle = $this->tabelle(self::PHP_FILE);

        $this->assertCount(1, $tabelle['entries']);
        $this->assertSame('09,39 * * * *', $tabelle['entries'][0]['schedule']);
        $this->assertSame('root', $tabelle['entries'][0]['user']);
        $this->assertStringStartsWith('[ -x /usr/lib/php/sessionclean ]', $tabelle['entries'][0]['command']);
        $this->assertStringEndsWith('fi', $tabelle['entries'][0]['command'], 'Das Kommando endet, wo die Zeile endet.');
    }

    /**
     * Eine Zuweisung ist Umgebung und keine Zeile.
     *
     * Ohne diese Unterscheidung stünde `SHELL=/bin/sh` als Zeitplan mit vier
     * Feldern in der Tabelle — und ein `MAILTO=""` als Zeitplan ohne jedes.
     */
    public function test_an_assignment_is_environment_and_not_an_entry(): void
    {
        $tabelle = $this->tabelle(self::CRONTAB);

        $this->assertSame(['SHELL' => '/bin/sh'], $tabelle['env']);

        foreach ($tabelle['entries'] as $eintrag) {
            $this->assertStringNotContainsString(
                'SHELL',
                $eintrag['schedule'],
                'Eine Zuweisung ist als Zeitplan gelesen worden.',
            );
        }
    }

    /**
     * Und die Anführungszeichen einer Zuweisung gehören nicht zum Wert.
     *
     * `MAILTO=""` schreibt dieses Panel selbst ({@see CronFile});
     * roh gelesen stünde auf der Seite `MAILTO=""` mit zwei Zeichen, die kein
     * Wert sind.
     */
    public function test_a_quoted_assignment_loses_its_quotes(): void
    {
        $tabelle = $this->tabelle("MAILTO=\"\"\nPATH=/usr/local/bin:/usr/bin:/bin\n");

        $this->assertSame(['MAILTO' => '', 'PATH' => '/usr/local/bin:/usr/bin:/bin'], $tabelle['env']);
        $this->assertSame([], $tabelle['entries']);
    }

    /**
     * Ein Kommentar ist keine Zeile — auch der auskommentierte `PATH` nicht.
     */
    public function test_a_comment_is_not_an_entry(): void
    {
        $tabelle = $this->tabelle(self::CRONTAB);

        $this->assertArrayNotHasKey(
            'PATH',
            $tabelle['env'],
            'Das auskommentierte #PATH= ist als Umgebung gelesen worden.',
        );
    }

    /**
     * Die `@`-Form trägt ihren Zeitplan in einem Feld.
     *
     * Gemessen sind acht Namen; geprüft wird die **Gestalt** und nicht der
     * Name — ob cron ihn kennt, entscheidet cron, und ein Nachbau seines
     * Parsers wäre dessen zweite Fassung.
     */
    #[DataProvider('shorthands')]
    public function test_the_at_form_carries_one_time_field(string $name): void
    {
        $tabelle = $this->tabelle("@$name\troot\t/usr/bin/backup --alle\n");

        $this->assertCount(1, $tabelle['entries'], sprintf('@%s ergibt keinen Eintrag.', $name));
        $this->assertSame('@'.$name, $tabelle['entries'][0]['schedule']);
        $this->assertSame('root', $tabelle['entries'][0]['user']);
        $this->assertSame('/usr/bin/backup --alle', $tabelle['entries'][0]['command']);
    }

    /**
     * Die acht Namen, die cron in `/etc/cron.d` angenommen hat (M4).
     *
     * @return list<array{string}>
     */
    public static function shorthands(): array
    {
        return array_map(
            static fn (string $n): array => [$n],
            ['reboot', 'yearly', 'annually', 'monthly', 'weekly', 'daily', 'midnight', 'hourly'],
        );
    }

    /**
     * Eine Zeile, der ein Feld fehlt, wird nicht zu einem halben Eintrag.
     *
     * **Der Rückfall ist Weglassen und nicht Raten.** Ein Eintrag mit leerem
     * Kommando sähe auf der Seite aus wie eine Zeile, die nichts tut — und das
     * ist eine Aussage über den Server, die niemand gemessen hat.
     */
    public function test_a_short_line_is_no_entry(): void
    {
        $this->assertSame([], $this->tabelle("17 * * * * root\n")['entries'], 'Fünf Zeitfelder und ein Benutzer, kein Kommando.');
        $this->assertSame([], $this->tabelle("@daily root\n")['entries'], 'Ein Zeitfeld und ein Benutzer, kein Kommando.');
    }

    /**
     * Eine nicht lesbare Datei ist keine leere.
     */
    public function test_an_unreadable_file_is_not_an_empty_one(): void
    {
        $zustand = CronState::read(
            ['/etc/cron.d/geheim' => null, CronState::CRONTAB => self::CRONTAB],
            [],
            false,
        );

        $geheim = $this->finde($zustand['tables'], '/etc/cron.d/geheim');

        $this->assertFalse($geheim['readable'], 'Eine Datei ohne Inhalt steht als lesbar da.');
        $this->assertSame([], $geheim['entries']);
        $this->assertTrue($zustand['readable'], 'Eine einzelne unlesbare Datei nimmt die ganze Antwort mit.');
    }

    /**
     * Erst wenn **keine** Quelle lesbar war, ist die Antwort es nicht.
     */
    public function test_nothing_readable_is_said_once(): void
    {
        $zustand = CronState::read([CronState::CRONTAB => null], [], false);

        $this->assertFalse($zustand['readable']);
        $this->assertSame('unreadable', $zustand['reason']);
        $this->assertContains($zustand['reason'], CronState::REASONS, 'Der Grund steht nicht in der Aufzählung.');
    }

    /**
     * Die Dateien des Panels sind als solche erkennbar.
     */
    public function test_the_own_files_are_marked(): void
    {
        $zustand = CronState::read([
            CronState::CRONTAB => self::CRONTAB,
            '/etc/cron.d/srvpanel-p1139' => "15 3 * * *\tp1139\t/usr/lib/srvpanel/cron-run 1234\n",
            '/etc/cron.d/e2scrub_all' => "30 3 * * 0\troot\t/sbin/e2scrub_all\n",
        ], [], false);

        $this->assertTrue($this->finde($zustand['tables'], '/etc/cron.d/srvpanel-p1139')['owned']);
        $this->assertFalse($this->finde($zustand['tables'], '/etc/cron.d/e2scrub_all')['owned']);
        $this->assertFalse($this->finde($zustand['tables'], CronState::CRONTAB)['owned']);
    }

    /**
     * Ohne diese Zahl wäre jede Behauptung oben auch bei einem Leser grün, der
     * gar nichts liest.
     */
    public function test_the_probes_produce_something(): void
    {
        $this->assertGreaterThan(
            0,
            count($this->tabelle(self::CRONTAB)['entries']) + count($this->tabelle(self::PHP_FILE)['entries']),
            'Die Prüfkörper ergeben keinen einzigen Eintrag — dann prüft dieser Wächter nichts.',
        );
    }

    /**
     * @return array{path: string, owned: bool, readable: bool, entries: list<array{schedule: string, user: string, command: string}>, env: array<string, string>}
     */
    private function tabelle(string $inhalt): array
    {
        $zustand = CronState::read([CronState::CRONTAB => $inhalt], [], false);

        return $zustand['tables'][0];
    }

    /**
     * @param  list<array{path: string, owned: bool, readable: bool, entries: list<array{schedule: string, user: string, command: string}>, env: array<string, string>}>  $tabellen
     * @return array{path: string, owned: bool, readable: bool, entries: list<array{schedule: string, user: string, command: string}>, env: array<string, string>}
     */
    private function finde(array $tabellen, string $pfad): array
    {
        foreach ($tabellen as $tabelle) {
            if ($tabelle['path'] === $pfad) {
                return $tabelle;
            }
        }

        $this->fail(sprintf('Die Tabelle %s steht nicht in der Antwort.', $pfad));
    }
}
