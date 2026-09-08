<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;
use Tests\Support\WithoutPhpComments;

/**
 * Die Naht zwischen einer Meldung des Agenten und dem Satz, der sie einbettet.
 *
 * **Gemessen am 8. September 2026 auf `cloudsrv24`** (`docs/114 §9.2`): Auf
 * `/services` stand bei angehaltenem Agenten
 *
 *     Der Agent antwortet nicht: Der Agent läuft nicht: Socket ist nicht vorhanden..
 *
 * — zwei Punkte. `Client.php` gibt seine acht Meldungen als **ganze Sätze**
 * zurück, jede mit Schlusszeichen; die Vorlage setzte danach noch einen.
 *
 * > **Ein Satz, der einen fremden Satz einbettet und selbst schliesst,
 * > schliesst ihn zweimal.**
 *
 * **Die Zahl zehn hat zwei Berichtigungen gekostet.** Der erste Wurf zählte
 * sieben — sein `\berror\b` liess `props.errors.packages` aus —, der zweite
 * neun, weil seine Klammer kein `}` in der Mitte verträgt und ausgerechnet die
 * Stelle des Befundes übersprang.
 *
 * > **Zwei Ausdrücke, die dasselbe zu suchen scheinen, zählen Verschiedenes —
 * > und die Zahl, die man abschreibt, gehört dem einen und wird dem anderen
 * > zugeschrieben.**
 *
 * **Zwei Richtungen, und beide werden gebraucht.** Die eine allein liesse zu,
 * dass jemand die Schlusszeichen aus `Client` entfernt und alle neun
 * Einbettungen ohne Punkt enden; die andere allein liesse zu, dass eine neue
 * Vorlage wieder einen zweiten setzt.
 *
 * > **Ein Wächter, der eine Richtung prüft, hat über die andere nichts gesagt
 * > — und welche der beiden fehlt, sieht man erst, wenn man sie braucht.**
 *
 * **Was er nicht hält:** ob die Meldung *inhaltlich* an diese Stelle gehört.
 * `docs/59` hat dafür den Satz, dass ein Serverzustand nicht als Feldfehler
 * gemeldet wird; das ist eine andere Frage und hat ihren eigenen Ort.
 */
final class AgentMessageTest extends TestCase
{
    use WithoutMarkupComments;
    use WithoutPhpComments;

    private const CLIENT = __DIR__.'/../../agent/src/Client.php';

    private const SEITEN = __DIR__.'/../../resources/js/Pages';

    /**
     * Jede Meldung des Klienten ist ein ganzer Satz.
     */
    public function test_every_message_brings_its_own_end(): void
    {
        $quelle = file_get_contents(self::CLIENT);

        $this->assertIsString($quelle, 'Client.php ist nicht lesbar.');

        $treffer = [];

        preg_match_all(
            "/'(Der Agent[^']*|Der Vorgang[^']*|Der Inhalt[^']*|Anfrage[^']*|Socket[^']*)'/",
            $this->withoutComments($quelle),
            $treffer,
        );

        $meldungen = $treffer[1];

        // **Die Untergrenze zählt mit.** Läuft der Ausdruck ins Leere, meldete
        // dieser Fall sonst Grün für eine Datei, in der er nichts gefunden hat.
        $this->assertGreaterThanOrEqual(
            8,
            count($meldungen),
            'Weniger Meldungen gefunden als am 8. September 2026 gemessen — der Ausdruck greift nicht mehr.',
        );

        foreach ($meldungen as $meldung) {
            $this->assertMatchesRegularExpression(
                '/[.!?]\z/D',
                $meldung,
                sprintf('Die Meldung „%s" endet ohne Schlusszeichen — der Einbetter setzt keines dazu.', $meldung),
            );
        }
    }

    /**
     * Und keine Vorlage setzt hinter eine eingebettete Meldung noch einen Punkt.
     */
    public function test_no_template_closes_an_embedded_message(): void
    {
        $gefunden = 0;
        $fehler = [];

        foreach ($this->vorlagen() as $pfad) {
            $quelle = file_get_contents($pfad);

            if (! is_string($quelle)) {
                continue;
            }

            $treffer = [];

            preg_match_all(
                /*
                 * **Die Klammer darf ein `}` enthalten**, und daran ist der
                 * erste Wurf gescheitert: `{{ error ? `: ${error}` : '.' }}`
                 * trägt in seiner Mitte eines, und ein `[^}]*` kommt nie bis
                 * zum Ende. Der Wächter zählte neun Einbettungen und sah
                 * ausgerechnet die zehnte nicht — die, an der der Befund
                 * entstanden ist.
                 *
                 * > **Ein Wächter, der einen Ausdruck nicht auflösen kann, hat
                 * > nicht wenig gemessen — er hat an dieser Stelle gar nicht
                 * > gemessen.**
                 *
                 * Gefangen hat es der Bruch und nicht der Lauf: Mit dem Punkt
                 * zurückgesetzt blieb er grün.
                 */
                '/\{\{((?:[^{}]|\$\{[^{}]*\})*)\}\}(.?)/u',
                $this->withoutMarkupComments($quelle),
                $treffer,
                PREG_SET_ORDER,
            );

            foreach ($treffer as $eins) {
                if (! preg_match('/\berrors?\b/', $eins[1])) {
                    continue;
                }

                $gefunden++;

                if ($eins[2] === '.') {
                    $fehler[] = basename($pfad).': '.$eins[0];
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            10,
            $gefunden,
            'Weniger als zehn Einbettungen gefunden — am 8. September 2026 gezählt — der Ausdruck greift nicht mehr.',
        );

        $this->assertSame(
            [],
            $fehler,
            "Hinter einer eingebetteten Meldung steht ein Punkt; sie bringt ihren eigenen mit:\n".implode("\n", $fehler),
        );
    }

    /** @return list<string> */
    private function vorlagen(): array
    {
        $dateien = [];

        $lauf = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SEITEN));

        foreach ($lauf as $eintrag) {
            if ($eintrag instanceof \SplFileInfo && $eintrag->getExtension() === 'vue') {
                $dateien[] = $eintrag->getPathname();
            }
        }

        sort($dateien);

        return $dateien;
    }
}
