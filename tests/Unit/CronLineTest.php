<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Cron\CronFile;
use SrvPanel\Agent\Cron\Schedule;

/**
 * In eine Cron-Zeile kommt kein Kundentext — geprüft an der erzeugten Zeichenkette.
 *
 * **Der Wächter zu `docs/51 §11`, und er hat einen gemessenen Anlass.** `docs/60
 * §7` hat den Angriff aus §10.1 nicht nachgelesen, sondern gefahren: Eine Datei
 * mit einem Zeilenumbruch im Befehlsteil erzeugt eine zweite Zeile, die sich ihr
 * Benutzerfeld selbst aussucht, und die von ihr angelegte Datei gehörte **root**.
 *
 * Deshalb prüft dieser Wächter nicht, ob eine Prüfung existiert, sondern was am
 * Ende in der Datei steht:
 *
 * > **Ein Wächter, der die Absicht prüft, hat über das Ergebnis nichts gesagt.**
 *
 * Jede Zeile der erzeugten Datei muss vollständig aus Dingen bestehen, die das
 * Panel selbst gebildet hat — Zeitplan, Systembenutzer, Wrapperpfad, Nummer. Der
 * Befehl des Kunden kommt in dieser Datei nicht vor, und zwar nicht, weil er
 * maskiert wird, sondern weil er woanders liegt.
 */
final class CronLineTest extends TestCase
{
    /**
     * Die Form, die eine Jobzeile haben darf — und keine andere.
     *
     * Der Zeitplan besteht aus Ziffern, `*`, `,`, `-`, `/` und Leerzeichen; dann
     * ein Tabulator, der Systembenutzer, ein Tabulator, der Wrapper und eine
     * Nummer. `\z` und nicht `$`, damit nichts hinter einem Zeilenumbruch
     * durchgeht — dieselbe Falle, gegen die `AnchoredPatternTest` steht.
     */
    private const LINE = '/\A[0-9*,\/ -]+\t[a-z][a-z0-9]*\t\/usr\/lib\/srvpanel\/cron-run [0-9]+\z/';

    /**
     * Der Kern: Was ein Kunde schickt, steht nachher nicht in der Datei.
     *
     * Die Nutzlast ist wörtlich die aus `docs/60 §7` — Zeilenumbruch, zweite
     * Zeile mit `root`, Prozentzeichen. Sie geht hier durch die Stelle, die die
     * Datei baut, und darf in ihr nicht wieder auftauchen.
     */
    public function test_no_customer_text_reaches_the_file(): void
    {
        $payload = "harmlos\n* * * * *\troot\ttouch /tmp/uebernommen\ndate +%Y";

        $content = CronFile::render('p1001', [
            ['id' => 1234, 'schedule' => $this->schedule('15', '3', '*', '*', '*')],
        ]);

        self::assertStringNotContainsString('uebernommen', $content);
        self::assertStringNotContainsString('%', $content);
        self::assertStringNotContainsString($payload, $content);
        self::assertStringNotContainsString("\troot\t", $content);

        // Und die Gegenprobe: Es steht überhaupt etwas drin. Ohne sie wäre jede
        // Behauptung darüber, was fehlt, auch von einer leeren Datei erfüllt.
        self::assertStringContainsString('/usr/lib/srvpanel/cron-run 1234', $content);
    }

    /**
     * Jede Zeile ist entweder Kopf oder hat genau die erlaubte Form.
     *
     * Geprüft wird über einen Bestand aus mehreren Jobs, damit die Aussage nicht
     * an einem einzigen Beispiel hängt.
     */
    public function test_every_job_line_has_exactly_the_allowed_shape(): void
    {
        $content = CronFile::render('p1001', [
            ['id' => 1, 'schedule' => $this->schedule('*', '*', '*', '*', '*')],
            ['id' => 22, 'schedule' => $this->schedule('*/15', '9-17', '*', '*', '1-5')],
            ['id' => 333, 'schedule' => $this->schedule('0,30', '0', '1', '1', '7')],
        ]);

        $jobLines = 0;

        foreach (explode("\n", rtrim($content, "\n")) as $line) {
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, "\t")) {
                continue;
            }

            self::assertSame(1, preg_match(self::LINE, $line), 'Diese Zeile hat eine andere Form: '.$line);
            $jobLines++;
        }

        self::assertSame(3, $jobLines, 'Es sollen genau die drei Jobzeilen geprüft worden sein.');
    }

    /**
     * Der abschliessende Zeilenumbruch — Pflicht, und gemessen.
     *
     * `docs/60 §9`: Fehlt er, verwirft cron **die ganze Datei** mit
     * `Missing newline before EOF`. Das ist derselbe Totalausfall wie eine
     * kaputte Zeile, nur an einer Stelle, an die niemand denkt.
     */
    public function test_the_file_ends_with_a_newline(): void
    {
        $content = CronFile::render('p1001', [
            ['id' => 7, 'schedule' => $this->schedule('0', '4', '*', '*', '*')],
        ]);

        self::assertStringEndsWith("\n", $content);
        self::assertStringNotContainsString("\n\n", $content);
    }

    /**
     * Ein Zeitplan, der eine zweite Zeile erzeugen könnte, kommt nicht durch.
     *
     * Das ist die Schranke aus {@see Schedule}, hier von der Seite der Zeile aus
     * gesehen: Nicht „wird geprüft", sondern „diese Eingabe wird abgewiesen".
     *
     * @dataProvider brokenFields
     */
    public function test_a_field_that_could_break_the_line_is_refused(string $minute): void
    {
        $this->expectException(AgentException::class);

        Schedule::parse([
            'minute' => $minute,
            'hour' => '*',
            'day_of_month' => '*',
            'month' => '*',
            'day_of_week' => '*',
        ]);
    }

    /** @return array<string,array{string}> */
    public static function brokenFields(): array
    {
        return [
            'Zeilenumbruch' => ["15\n* * * * *\troot\ttouch /tmp/x"],
            'Wagenruecklauf' => ["15\r"],
            'Tabulator' => ["15\t"],
            'Leerzeichen' => ['15 3'],
            'Prozentzeichen' => ['%Y'],
            'Rueckstrich' => ['15\\'],
            'ausserhalb der Spanne' => ['70'],
            'Buchstaben' => ['JAN'],
        ];
    }

    /**
     * Ein Dateiname, den cron übergehen würde, wird abgewiesen.
     *
     * **Und das ist der stumme Fall.** Von den vier Arten, auf die cron eine
     * Datei liegen lässt, ist diese die einzige ohne Protokolleintrag (`docs/60
     * §5`) — sie sähe im Panel aus wie „läuft".
     *
     * @dataProvider brokenNames
     */
    public function test_a_name_cron_would_skip_is_refused(string $user): void
    {
        $this->expectException(AgentException::class);

        CronFile::name($user);
    }

    /** @return array<string,array{string}> */
    public static function brokenNames(): array
    {
        return [
            'Punkt' => ['p1001.alt'],
            'Plus' => ['p1001+x'],
            'Schraegstrich' => ['../passwd'],
            'Leerzeichen' => ['p1001 x'],
            'leer' => [''],
        ];
    }

    /** Die Gegenprobe dazu: Der Name, den dieses Panel wirklich vergibt, geht durch. */
    public function test_the_name_this_panel_actually_uses_is_accepted(): void
    {
        self::assertSame('srvpanel-p1001', CronFile::name('p1001'));
        self::assertSame('/etc/cron.d/srvpanel-p1001', CronFile::path('p1001'));
    }

    /**
     * @return array{minute: string, hour: string, day_of_month: string, month: string, day_of_week: string}
     */
    private function schedule(string $minute, string $hour, string $dom, string $month, string $dow): array
    {
        return Schedule::parse([
            'minute' => $minute,
            'hour' => $hour,
            'day_of_month' => $dom,
            'month' => $month,
            'day_of_week' => $dow,
        ]);
    }
}
