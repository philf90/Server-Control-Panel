<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutPhpComments;

/**
 * Ein abschliessender Wert ist nicht deshalb eine Meldung, weil er ein Satz ist.
 *
 * ## Woher dieser Wächter kommt
 *
 * Am 25. August 2026 hat dieselbe Annahme zweimal zugeschlagen, in derselben
 * Runde und in benachbartem Code:
 *
 * - `assertGuest('Die Sitzung läuft weiter, obwohl …')` — der erste Wert ist
 *   der **Guard**. Laravel suchte einen Guard dieses Namens.
 * - `assertDatabaseHas('sessions', [...], 'Die Sitzung eines fremden Kontos …')`
 *   — der dritte Wert ist die **Verbindung**. Laravel suchte eine Verbindung
 *   dieses Namens.
 *
 * In PHPUnit ist der letzte Wert einer Behauptung fast immer die Meldung; in
 * Laravels Testhelfern ist er es oft nicht. Die Gewohnheit stimmt in neun von
 * zehn Fällen, und der zehnte kostet eine CI-Runde.
 *
 * > **Eine Regel, an die man sich erinnern muss, ist keine Regel, sondern eine
 * > Gewohnheit.**
 *
 * ## Warum das Merkmal das Leerzeichen ist
 *
 * Ein Guard heisst `web` oder `api`, eine Verbindung `sqlite` oder `pgsql` —
 * **ein Wort, kein Satz.** Eine Meldung dieses Projekts ist ein deutscher Satz
 * mit Leerzeichen. Das trennt die beiden Fälle zuverlässiger als eine Liste der
 * gültigen Namen, die bei der nächsten Verbindung nachgezogen werden müsste.
 *
 * Der Fehlschlag ist dabei **laut** und nicht still — Laravel wirft. Dieser
 * Wächter spart die Runde, in der man es merkt, nicht einen stillen Fehler.
 *
 * > **Ein Wächter, der einen lauten Fehler früher meldet, ist trotzdem einer:
 * > Er verschiebt ihn von der CI an die Tastatur.**
 */
final class AssertionArgumentTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Helfer, deren abschliessender Wert **keine** Meldung ist.
     *
     * Der Wert dahinter sagt, wofür er stattdessen steht — er steht in der
     * Fehlermeldung, damit der Leser nicht nachschlagen muss.
     *
     * @var array<string, string>
     */
    private const NOT_A_MESSAGE = [
        'assertGuest' => 'den Guard',
        'assertAuthenticated' => 'den Guard',
        'assertAuthenticatedAs' => 'den Guard',
        'assertDatabaseHas' => 'die Verbindung',
        'assertDatabaseMissing' => 'die Verbindung',
        'assertDatabaseCount' => 'die Verbindung',
        'assertDatabaseEmpty' => 'die Verbindung',
        'assertSoftDeleted' => 'die Verbindung',
        'assertNotSoftDeleted' => 'die Verbindung',
    ];

    public function test_no_laravel_helper_is_handed_a_message(): void
    {
        $found = [];
        $calls = 0;

        foreach ($this->sources() as $relative => $source) {
            foreach (self::NOT_A_MESSAGE as $helper => $meaning) {
                preg_match_all('/'.preg_quote($helper, '/').'\(([^;]*?)\);/s', $source, $treffer, PREG_SET_ORDER);

                foreach ($treffer as $call) {
                    $calls++;

                    /*
                     * **Ein Satz in Anführungszeichen als letzter Wert.** Ein
                     * Guard oder eine Verbindung ist ein Wort; eine Meldung
                     * dieses Projekts ist ein deutscher Satz. Das Leerzeichen
                     * trennt sie zuverlässiger als eine Liste gültiger Namen.
                     */
                    if (preg_match("/,\s*'[^']*\s[^']*'\s*,?\s*\z/s", $call[1]) === 1) {
                        $found[] = sprintf('%s: %s() bekommt einen Satz, wo %s steht',
                            $relative, $helper, $meaning);
                    }
                }
            }
        }

        $this->assertGreaterThan(20, $calls,
            'Es werden kaum solche Aufrufe gefunden — dann prüft dieser Test nichts.');

        $this->assertSame([], $found, sprintf(
            "Hier steht eine Meldung, wo Laravel etwas anderes erwartet:\n\n  %s\n\n"
            .'Der Fehlschlag ist laut — Laravel sucht dann einen Guard oder eine Verbindung dieses '
            .'Namens. Die Meldung gehört an eine Behauptung, die eine entgegennimmt '
            .'(`assertSame`, `assertNotNull`, `assertTrue`).',
            implode("\n  ", $found),
        ));
    }

    /**
     * Der Prüfkörper: Der Ausdruck trennt den Satz vom Namen.
     *
     * Ohne ihn hiesse ein grüner Lauf oben nur, dass der Ausdruck nichts
     * gefunden hat — und das sagt eine kaputte Zeile genauso.
     */
    public function test_the_expression_tells_a_sentence_from_a_name(): void
    {
        /*
         * **Die Prüfkörper sind zusammengesetzt und stehen nicht als Aufruf
         * da.** Sonst fände der Test oben sie in dieser Datei — ein Wächter,
         * der über seinen eigenen Prüfkörper stolpert, ist nicht streng,
         * sondern nur laut.
         */
        $satz = "['id' => 'x'], 'Die Sitzung wurde beendet.'";
        $name = "['id' => 'x'], 'sqlite'";
        $ohne = "['id' => 'x']";

        $muster = "/,\s*'[^']*\s[^']*'\s*,?\s*\z/s";

        foreach ([[$satz, 1, 'ein Satz'], [$name, 0, 'ein Verbindungsname'], [$ohne, 0, 'gar kein dritter Wert']] as [$quelle, $erwartet, $fall]) {
            $this->assertSame($erwartet, preg_match($muster, $quelle), $fall);
        }
    }

    /** @return array<string, string> Pfad => Quelltext */
    private function sources(): array
    {
        $wurzel = dirname(__DIR__, 2);
        $dateien = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($wurzel.'/tests', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $datei) {
            if ($datei->isFile() && $datei->getExtension() === 'php') {
                /*
                 * **Ohne Kommentare.** Ein Wächter, der einen kaputten Aufruf
                 * beschreibt, zitiert ihn — und meldete sich sonst selbst.
                 *
                 * > **Ein Wächter, der Prosa mitliest, findet jede Warnung vor
                 * > sich selbst.**
                 */
                $dateien[substr($datei->getPathname(), strlen($wurzel) + 1)] =
                    $this->withoutComments((string) file_get_contents($datei->getPathname()));
            }
        }

        return $dateien;
    }
}
