<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\SystemReboot;
use Tests\Support\WithoutPhpComments;

/**
 * Der Neustart wird **abgesetzt** und nicht ausgeführt — und er verlangt den
 * Rechnernamen dort, wo die Antwort zählt.
 *
 * ## Warum es diesen Wächter gibt
 *
 * `docs/81 §7`, Falle 8: Der Neustart ist **die eine Handlung, die das Panel
 * selbst mitnimmt.** Agent, Warteschlange, Webserver und Datenbank gehen
 * gemeinsam; ein `systemctl reboot` innerhalb der Operation wäre ein Wettlauf
 * zwischen ihrer Antwort und dem SIGTERM, das systemd der eigenen
 * Kontrollgruppe schickt.
 *
 * **Und der Wettlauf ist nicht messbar, sondern nur verlierbar.** Wer ihn
 * verliert, hinterlässt einen Vorgang, der für immer auf `running` steht, und
 * ein Protokoll ohne die Zeile, die den Ausfall erklärt — genau die Zeile, die
 * man Wochen später sucht.
 *
 * > **Ein Vorgang, dessen Antwort nie ankommt, ist von einem, der nie gelaufen
 * > ist, nicht zu unterscheiden.**
 *
 * ## Was er nicht prüft
 *
 * Ob der Neustart wirklich stattfindet, und ob die transiente Unit den
 * Neustart von `srvpanel-worker` überlebt. Beides braucht systemd als PID 1;
 * dieser Container hat es nicht (`/run/systemd/system` fehlt, gemessen am
 * 26. August 2026), und `docs/81` führt die zweite Frage als **den** Punkt,
 * der A1 zum Scheitern bringen kann. Sie gehört auf einen echten Server und
 * nicht in einen Wächter, der sie nicht stellen kann.
 *
 * > **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und
 * > nicht als Zusage.**
 */
final class RebootConfirmTest extends TestCase
{
    use WithoutPhpComments;

    private const OP = 'agent/src/Ops/SystemReboot.php';

    private const CONTROLLER = 'app/Http/Controllers/ServerController.php';

    private const BUTTON = 'resources/js/Components/RebootButton.vue';

    /**
     * Die Operation reicht `systemctl` an `systemd-run` weiter und ruft es
     * nicht selbst.
     *
     * **Gefragt wird der Programmname an der Aufrufstelle**, nicht die
     * Zeichenkette „systemctl" irgendwo in der Datei: Sie steht dort auch als
     * Argument und im Rückweg (`systemctl stop …`). Ein Wächter, der eine
     * Zeichenkette sucht statt ihrer Stelle, ist grün, sobald sie irgendwo
     * steht — und das ist derselbe Fehler wie in `docs/62` Punkt 12.
     */
    public function test_the_reboot_is_scheduled_and_not_executed(): void
    {
        $quelle = $this->source(self::OP);

        preg_match_all(
            '/->(?:run|stream)\(\s*\'(?<program>[^\']+)\'/',
            $quelle,
            $aufrufe,
        );

        $programme = $aufrufe['program'];

        $this->assertNotSame([], $programme, implode(' ', [
            'In SystemReboot startet kein Programm mehr.',
            'Entweder ist die Operation weg, oder dieser Ausdruck sucht ins Leere.',
        ]));

        $this->assertSame(
            ['systemd-run'],
            array_values(array_unique($programme)),
            implode("\n", [
                'SystemReboot startet ein anderes Programm als systemd-run:',
                '  '.implode(', ', array_unique($programme)),
                '',
                'Ein `systemctl reboot` in dieser Operation ist ein Wettlauf zwischen ihrer Antwort',
                'und dem SIGTERM an die eigene Kontrollgruppe. Wer ihn verliert, hinterlässt einen',
                'Vorgang auf `running` und kein Protokoll.',
            ]),
        );
    }

    /**
     * Und die Wartezeit ist keine Null.
     *
     * **Sie ist der ganze Zweck des Zeitgebers.** Ein `--on-active=0` bestellte
     * denselben Wettlauf noch einmal, nur über einen Umweg — und sähe im
     * Quelltext aus wie eine Einstellung statt wie ein Fehler.
     */
    public function test_the_delay_is_what_makes_the_answer_arrive(): void
    {
        $this->assertGreaterThan(
            0,
            SystemReboot::DELAY_SECONDS,
            'Ohne Wartezeit stirbt der Agent, bevor seine Antwort geschrieben ist.',
        );

        $this->assertStringContainsString(
            "'--on-active='.self::DELAY_SECONDS",
            $this->source(self::OP),
            'Die Wartezeit der Unit kommt nicht aus DELAY_SECONDS — dann gibt es zwei Zahlen.',
        );
    }

    /**
     * Der Rechnername wird auf dem Server geprüft.
     *
     * **Der abgeschaltete Knopf im Browser ist die Anzeige und nicht die
     * Schranke.** Er sagt dem Betreiber, dass er noch nicht fertig ist; wer
     * die Anfrage selbst schickt, sieht ihn nie.
     *
     * > **Was der Geprüfte selbst zurücknehmen kann, ist keine Schranke,
     * > sondern eine Voreinstellung.**
     */
    public function test_the_hostname_is_checked_on_the_server(): void
    {
        $quelle = $this->source(self::CONTROLLER);

        $this->assertStringContainsString(
            '$request->validate(',
            $quelle,
            'Der Neustart nimmt den eingegebenen Namen ungeprüft entgegen.',
        );

        $this->assertMatchesRegularExpression(
            '/Rule::in\(\[\$host\]\)/',
            $quelle,
            'Der eingegebene Name wird nicht gegen den Namen dieses Servers gehalten.',
        );
    }

    /**
     * Und er wird gegen dieselbe Quelle gehalten, die die Seite anzeigt.
     *
     * **Das ist der Fehler, der niemandem auffiele, weil er wie ein Tippfehler
     * aussieht.** `Names::host()` liefert den vollständigen Namen,
     * `php_uname('n')` den kurzen — auf `cloudsrv24.de` also
     * „cloudsrv24.de" gegen „cloudsrv24". Stünde auf der Seite der eine und in
     * der Prüfung der andere, liesse sich der Neustart **nie** bestätigen, und
     * der Betreiber hielte sich für vertippt.
     *
     * > **Ein Wert, der an zwei Stellen anders entsteht, ist an einer davon
     * > falsch — und welche, sieht man erst, wenn jemand ihn abtippt.**
     */
    public function test_the_shown_name_and_the_checked_name_have_one_source(): void
    {
        $quelle = $this->source(self::CONTROLLER);

        $treffer = preg_match_all('/Names::(\w+)\(/', $quelle, $namen);

        $this->assertGreaterThanOrEqual(
            2,
            $treffer,
            'Der Rechnername wird nicht zweimal über Names erfragt — dann prüft dieser Wächter nichts.',
        );

        $this->assertSame(
            ['host'],
            array_values(array_unique($namen[1])),
            implode("\n", [
                'Angezeigter und geprüfter Rechnername kommen aus verschiedenen Quellen:',
                '  '.implode(', ', array_unique($namen[1])),
                '',
                'Names::host() antwortet nie leer und liefert den vollständigen Namen; fqdn() darf',
                'null sein, php_uname() gibt den kurzen. Wer den einen zeigt und den anderen prüft,',
                'baut eine Bestätigung, die niemand bestehen kann.',
            ]),
        );
    }

    /**
     * Die Wartezeit steht in der Rückfrage nicht als Wort.
     *
     * **Sie ist eine Zusage des Agenten.** Eine „Minute" im deutschen Satz wäre
     * ihre zweite Fassung — und die bleibt stehen, wenn jemand
     * {@see SystemReboot::DELAY_SECONDS} ändert. Der Betreiber läse dann
     * „eine Minute" und hätte zehn Sekunden.
     *
     * > **Zwei Listen, die dasselbe meinen, laufen auseinander — und keine von
     * > beiden ist der Ort, an dem man nachsieht.**
     */
    public function test_the_waiting_time_is_not_written_out_in_the_question(): void
    {
        $quelle = (string) file_get_contents($this->root().'/'.self::BUTTON);

        $frage = $this->question($quelle);

        $this->assertNotSame('', $frage, implode(' ', [
            'In RebootButton.vue steht kein Aufruf von ask() mehr —',
            'entweder ist die Rückfrage weg, oder dieser Ausdruck sucht ins Leere.',
        ]));

        $this->assertStringContainsString(
            'props.delay',
            $frage,
            'Die Rückfrage nennt die Wartezeit nicht aus dem Wert des Servers.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/(?:einer?|zwei|drei|\d+)\s+(?:Minute|Sekunde)/u',
            $frage,
            implode("\n", [
                'Die Rückfrage schreibt die Wartezeit aus. Sie ist eine Zusage des Agenten',
                '(SystemReboot::DELAY_SECONDS); ein Wort dafür ist ihre zweite Fassung.',
            ]),
        );
    }

    /** Der Text zwischen `ask(` und seiner schliessenden Klammer. */
    private function question(string $quelle): string
    {
        $von = strpos($quelle, 'ask(');

        if ($von === false) {
            return '';
        }

        $ende = strpos($quelle, "\n  )", $von);

        return $ende === false ? '' : substr($quelle, $von, $ende - $von);
    }

    /** PHP-Quelltext ohne Kommentare — geprüft wird der Code und nicht die Prosa. */
    private function source(string $relativ): string
    {
        return $this->withoutComments((string) file_get_contents($this->root().'/'.$relativ));
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
