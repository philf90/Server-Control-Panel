<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\CronRuns;

/**
 * Die Ausgabe eines Cronjobs darf die Antwort des Agenten nicht mitreissen.
 *
 * **Gemessen am 17. August 2026, und der Befund ist schärfer als erwartet.**
 * `Connection::send()` kodiert jede Antwort des Agenten mit
 * `json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)` — **ohne**
 * `JSON_INVALID_UTF8_SUBSTITUTE`. Ein einziges ungültiges Byte in einem einzigen
 * Feld lässt `json_encode` `false` zurückgeben:
 *
 * > **Eine ungültige Folge in einem Feld nimmt die ganze Antwort mit, nicht nur
 * > sich selbst.**
 *
 * Für `cron.runs` ist das kein Randfall, sondern der Normalfall in Wartestellung.
 * Die Ausgabe eines Cronjobs sind beliebige Bytes: ein Programm, das eine
 * Binärdatei ausgibt; eine kaputte Locale; eine halbe UTF-8-Folge, die erst durch
 * den Schnitt bei 64 KB entstanden ist. Der Kunde bekäme einen Fehler, dessen
 * Ursache in der Ausgabe seines eigenen Programms läge, und niemand käme darauf.
 *
 * Das ist derselbe Fund wie `docs/46 §8`, nur in die andere Richtung: Dort machte
 * ein `BLOB` über `json_decode()` eine ganze Datenbankzeile unlesbar, hier macht
 * eine Ausgabe über `json_encode()` eine ganze Agentenantwort unlesbar.
 *
 * **Geprüft wird an der Zeichenkette und nicht an einem Lauf.** Ein Wächter, der
 * dafür einen cron, ein Spool-Verzeichnis und einen Systembenutzer bräuchte,
 * liefe nie — und eine Regel, deren Wächter nicht läuft, ist keine.
 */
final class CronOutputEncodingTest extends TestCase
{
    /**
     * Genau die Flaggen aus `Connection::send()`.
     *
     * **Sie stehen hier als Abschrift, und das ist die Schwäche dieses Wächters:**
     * Ändert `Connection` seine Flaggen, merkt es niemand. Der Grund, es trotzdem
     * so zu machen, ist, dass die Alternative schlechter wäre — die Verbindung
     * aufzubauen, um an ihre Kodierung zu kommen, hiesse einen Socket in einem
     * Wächter. Die Abschrift steht deshalb an *einer* Stelle und wird von
     * {@see self::test_the_flags_match_the_connection()} gegen die Quelle gehalten.
     */
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Der Kern: Was aus {@see CronRuns::encodable()} kommt, geht durch `json_encode`.
     */
    #[DataProvider('outputs')]
    public function test_the_output_survives_the_encoder(string $bytes, bool $expectedLossy): void
    {
        [$text, $lossy] = CronRuns::encodable($bytes);

        self::assertSame($expectedLossy, $lossy, 'Das Ersetzen wird gemeldet oder es fand nicht statt.');

        $json = json_encode(['output' => $text, 'job' => 1], self::FLAGS);

        self::assertNotFalse($json, 'json_encode hat aufgegeben — die ganze Antwort wäre fort.');
    }

    /** @return array<string,array{string,bool}> */
    public static function outputs(): array
    {
        return [
            // Die Gegenprobe: gültiger Text bleibt unangetastet und gilt nicht
            // als ersetzt. Ohne sie wäre ein `encodable()`, das alles durch `?`
            // ersetzt, ebenfalls grün.
            'reines ASCII' => ['hallo welt', false],
            'gueltige Umlaute' => ['grün und größer', false],
            'gueltiges Emoji' => ['fertig ✔', false],

            // Und die Fälle, um die es geht.
            'einzelnes ungueltiges Byte' => ["kaputt \xFC ende", true],
            'abgeschnittene Folge' => ["abgeschnitten \xC3", true],
            'rohe Binaerdaten' => ["\x00\x01\x02\xFF\xFE", true],
            'leere Ausgabe' => ['', false],
        ];
    }

    /**
     * Der gültige Teil bleibt lesbar — es wird ersetzt und nicht weggeworfen.
     *
     * Eine Bereinigung, die bei einem kaputten Byte die ganze Ausgabe verwirft,
     * wäre dieselbe Sorte Auskunftsverlust wie der Fehler, den sie verhindert.
     */
    public function test_the_valid_part_is_kept(): void
    {
        [$text] = CronRuns::encodable("gut ü und kaputt \xFC ende");

        self::assertStringContainsString('gut ü und kaputt', $text);
        self::assertStringContainsString('ende', $text);
    }

    /**
     * Zu lange Ausgabe wird gekappt — und zwar **vor** der Prüfung.
     *
     * Der Schnitt selbst kann eine gültige Folge zerteilen. Wer erst prüft und
     * dann kappt, gibt eine Ausgabe zurück, die durch das Kappen ungültig
     * geworden ist — und meldet sie als unversehrt.
     */
    public function test_the_cut_happens_before_the_check(): void
    {
        /*
         * **Ein Byte ASCII davor, und das ist der ganze Witz dieses Falls.**
         * „ü" ist zwei Bytes lang, und `OUTPUT_MAX` ist gerade — ohne das
         * vorangestellte Zeichen fiele der Schnitt genau auf eine Zeichengrenze,
         * nichts ginge kaputt, und dieser Test wäre grün, ohne etwas zu prüfen.
         * Genau so ist er beim ersten Lauf durchgefallen, und der Fehler lag
         * nicht im Code, sondern hier.
         *
         * > **Ein Prüfkörper, der den Fall nicht herstellt, den er benennt,
         * > prüft den Nachbarfall.**
         */
        [$text, $lossy] = CronRuns::encodable('a'.str_repeat('ü', CronRuns::OUTPUT_MAX));

        self::assertLessThanOrEqual(CronRuns::OUTPUT_MAX, strlen($text));
        self::assertNotFalse(json_encode(['output' => $text], self::FLAGS),
            'Der Schnitt hat eine Folge zerteilt und die Antwort mitgenommen.');
        self::assertTrue($lossy, 'Ein Schnitt mitten im Zeichen ist ein Verlust und wird gemeldet.');
    }

    /**
     * Die Abschrift oben gegen die Quelle halten.
     *
     * **Ein Wächter, der eine Zahl abschreibt, ist eine zweite Fassung** — und
     * die zweite ist die, die veraltet. Hier wird sie wenigstens bemerkt: Nimmt
     * `Connection` eines Tages `JSON_INVALID_UTF8_SUBSTITUTE` dazu, ist die
     * Bereinigung in `cron.runs` nicht mehr die einzige Wand, und dieser Test
     * sagt es.
     */
    public function test_the_flags_match_the_connection(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Connection.php');

        self::assertStringContainsString(
            'json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)',
            $source,
            'Connection kodiert anders als dieser Wächter annimmt — die Abschrift oben gehört nachgezogen.',
        );

        self::assertStringNotContainsString(
            'JSON_INVALID_UTF8_SUBSTITUTE',
            $source,
            'Connection ersetzt jetzt selbst. Dann ist zu entscheiden, ob die Bereinigung in cron.runs bleibt — '.
            'sie meldet den Verlust, und das täte das Ersetzen in Connection nicht.',
        );
    }
}
