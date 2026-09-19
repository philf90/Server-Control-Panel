<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Was eine Rückfrage auf ihren Knopf schreibt, ist ein Verb und kein Satz.
 *
 * ## Warum es diesen Wächter gibt
 *
 * **Befund B des Nachlaufs zu `0.7.4-rc.16`** (`docs/123 §9`), gemessen am
 * 18. September 2026 auf `cloudsrv24`. `useConfirmation::ask()` nimmt als
 * zweites Argument das Verb des zustimmenden Knopfes — sein eigener Kopf sagt
 * *„Was der zustimmende Knopf sagt („Entfernen", „Sperren")"*. Beide
 * Sicherungsseiten übergaben dort den ganzen Satz:
 *
 * ```js
 * ask(
 *     'Sicherung entfernen',
 *     `Die Sicherung ${backup.storage_name} wird vom Datenträger gelöscht. …`,
 *     …
 * )
 * ```
 *
 * Bei 390 px mit offener Rückfrage gemessen: `dokument = 248`, der Knopf selbst
 * **281 px** über seinem Kasten. Dieselbe Seite ohne Rückfrage misst 0.
 *
 * **Und die Wirkung ist nicht nur Überlauf.** Die Rückfrage sagte oben nur
 * „Sicherung entfernen"; was geschieht, stand auf dem Knopf und dort
 * abgeschnitten. Der Kunde las weder, dass die Datei vom Datenträger
 * verschwindet, noch dass es sich nicht zurücknehmen lässt.
 *
 * > **Eine Rückfrage, deren Erklärung auf dem Knopf steht, erklärt nichts.**
 *
 * ## Warum die Argumentliste balanciert gelesen wird
 *
 * Der erste Anlauf zu diesem Befund zählte **sechzehn** Stellen und traf zwei.
 * Er nahm blind die zweite **Zeile** nach `ask(` — und die ist bei jeder
 * mehrzeiligen Frage deren Fortsetzung und nicht das zweite Argument.
 *
 * > **Ein Ausdruck, der die gewohnte Schreibweise kennt, prüft die Gewohnheit
 * > und nicht die Regel.**
 *
 * Gelesen wird deshalb bis zur schliessenden Klammer, mit Zählung der
 * Verschachtelung und Achtung auf Zeichenketten, und geteilt wird an Kommas der
 * **obersten** Ebene.
 *
 * ## Was er nicht kann
 *
 * Er streift Blockkommentare ab, aber keine `//`-Zeilen — in diesem Repo steht
 * die Prosa in Blöcken. Und er beurteilt das Verb an seiner **Form** (keine
 * Einbettung, höchstens {@see self::MAX_VERB} Zeichen) und nicht an seiner
 * Bedeutung: `'Xylophon'` käme durch. Was ein Knopf sagen *soll*, hängt an dem,
 * was ein Leser erwartet, und das hält kein Test.
 */
final class ConfirmationVerbTest extends TestCase
{
    /**
     * Die Obergrenze für ein Verb.
     *
     * **Gemessen und nicht gewählt**: Das längste unter den 22 richtigen
     * Aufrufen ist `an ? 'Einschalten' : 'Abschalten'` mit 33 Zeichen, das
     * längste einfache `'Neustart auslösen'` mit 19. Die beiden Sätze, die den
     * Befund ausgelöst haben, sind über 100 lang.
     */
    private const MAX_VERB = 40;

    /**
     * Die Untergrenze.
     *
     * **Ohne sie sagt ein grüner Lauf nichts.** Griffe der Ausdruck ins Leere —
     * weil `ask(` umbenannt wird oder der Leser an einer Klammer scheitert —,
     * prüfte er null Aufrufe und bliebe still. Gemessen sind es 25.
     */
    private const MINDESTENS = 20;

    public function test_every_confirmation_names_a_verb_on_its_button(): void
    {
        $gefunden = 0;
        $saetze = [];

        foreach ($this->aufrufe() as [$datei, $argumente]) {
            if (count($argumente) < 2) {
                continue;
            }

            $gefunden++;
            $verb = trim(preg_replace('/\s+/', ' ', $argumente[1]) ?? '');

            if (str_contains($verb, '${') || mb_strlen($verb) > self::MAX_VERB) {
                $saetze[] = sprintf('%s: %s', $datei, mb_substr($verb, 0, 70));
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MINDESTENS,
            $gefunden,
            'Der Leser hat kaum Aufrufe von ask() gefunden — er misst nichts mehr.'
        );

        $this->assertSame(
            [],
            $saetze,
            "Diese Rückfragen schreiben einen Satz auf ihren Knopf statt eines Verbs.\n"
            ."Der Satz gehört in die Frage (erstes Argument, mit \\n getrennt):\n  "
            .implode("\n  ", $saetze)
        );
    }

    /**
     * Jeder Aufruf von `ask()` mit seinen Argumenten der obersten Ebene.
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private function aufrufe(): array
    {
        $treffer = [];

        foreach ($this->dateien() as $pfad) {
            $text = (string) file_get_contents($pfad);
            $text = (string) preg_replace('#/\*.*?\*/#s', '', $text);
            $kurz = str_replace(dirname(__DIR__, 2).'/', '', $pfad);

            // `(?<![\w.])` schliesst `maske.ask(` und `frage_ask(` aus.
            if (preg_match_all('/(?<![\w.])ask\(/', $text, $_, PREG_OFFSET_CAPTURE)) {
                foreach ($_[0] as [$__, $stelle]) {
                    $argumente = $this->argumente($text, $stelle + 4);

                    if ($argumente !== null) {
                        $treffer[] = [$kurz, $argumente];
                    }
                }
            }
        }

        return $treffer;
    }

    /**
     * Balanciert bis zur schliessenden Klammer, dann an Kommas der obersten
     * Ebene geteilt.
     *
     * @return list<string>|null `null`, wenn die Klammer nicht schliesst
     */
    private function argumente(string $text, int $start): ?array
    {
        $tiefe = 0;
        $akt = '';
        $args = [];
        $quote = null;
        $laenge = strlen($text);

        for ($i = $start; $i < $laenge; $i++) {
            $c = $text[$i];

            if ($quote !== null) {
                if ($c === '\\') {
                    $akt .= substr($text, $i, 2);
                    $i++;

                    continue;
                }

                if ($c === $quote) {
                    $quote = null;
                }

                $akt .= $c;

                continue;
            }

            if ($c === '\'' || $c === '"' || $c === '`') {
                $quote = $c;
                $akt .= $c;

                continue;
            }

            if ($c === '(' || $c === '[' || $c === '{') {
                $tiefe++;
                $akt .= $c;

                continue;
            }

            if ($c === ')' || $c === ']' || $c === '}') {
                if ($tiefe === 0) {
                    $args[] = $akt;

                    return $args;
                }

                $tiefe--;
                $akt .= $c;

                continue;
            }

            if ($c === ',' && $tiefe === 0) {
                $args[] = $akt;
                $akt = '';

                continue;
            }

            $akt .= $c;
        }

        return null;
    }

    /** @return list<string> */
    private function dateien(): array
    {
        $wurzel = dirname(__DIR__, 2).'/resources/js';
        $gefunden = [];

        /** @var \SplFileInfo $datei */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($wurzel)) as $datei) {
            if ($datei->isFile() && $datei->getExtension() === 'vue') {
                $gefunden[] = $datei->getPathname();
            }
        }

        sort($gefunden);

        return $gefunden;
    }
}
