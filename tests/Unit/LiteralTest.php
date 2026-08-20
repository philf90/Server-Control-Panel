<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Was in einem `.literal` steht, ist kurz und steht im Quelltext.
 *
 * ## Wofür es diese Zusage gibt
 *
 * `.ident` trägt seit `docs/46 §20.11` `overflow-wrap: anywhere` — ohne diese
 * Zeile schob ein Bereichstitel die Seite bei 390 px um 99 px aus dem Bild.
 * `.ident.literal` nimmt sie zurück (`white-space: nowrap`), und das ist genau
 * der Zustand, gegen den die Regel gebaut wurde.
 *
 * Erlaubt ist die Ausnahme nur, weil dort etwas **Kurzes und Festes** steht:
 * die vier Beispiele in der Erklärung des Zeitplans. Ein Ausdruck, der aus
 * einer Eingabe kommt, gehört nicht dazu — `Ergibt: {{ ausdruck }}` daneben
 * trägt die Klasse ausdrücklich nicht, denn dort stehen fünf Felder mit je 192
 * erlaubten Zeichen.
 *
 * > **Eine Zusage, die das Stylesheet nicht geben kann, gibt der Wächter — oder
 * > niemand.**
 *
 * ## Warum zwölf
 *
 * Das längste Beispiel hat vier Zeichen. Zwölf lassen Luft für ein weiteres,
 * ohne dass daraus ein Ort für Werte wird: Bei 390 px ist eine Zeile rund 40
 * Zeichen breit, und ein Literal von zwölf Zeichen bricht sie nicht.
 */
final class LiteralTest extends TestCase
{
    /** Wie lang ein Literal höchstens sein darf. */
    private const MAX_LENGTH = 12;

    /**
     * In einem `.literal` steht kein `{{ }}` und nichts Langes.
     */
    public function test_a_literal_is_short_and_written_out(): void
    {
        $gesehen = 0;

        foreach ($this->vueFiles(dirname(__DIR__, 2).'/resources/js') as $datei) {
            $quelle = (string) file_get_contents($datei);

            preg_match_all(
                '/<span[^>]*\bclass="[^"]*\bliteral\b[^"]*"[^>]*>(.*?)<\/span>/s',
                $quelle,
                $treffer,
                PREG_SET_ORDER,
            );

            foreach ($treffer as $eintrag) {
                $gesehen++;
                $inhalt = trim($eintrag[1]);
                $wo = basename($datei);

                $this->assertStringNotContainsString(
                    '{{',
                    $inhalt,
                    sprintf(
                        "In %s steht in einem `.literal` eine Einsetzung.\n\n".
                        '`.literal` hält den Inhalt vom Umbruch ab. Was aus einer Eingabe kommt, kann '.
                        'beliebig lang werden und schiebt dann die Seite — genau der Fehler, gegen den '.
                        '`.ident` mit `overflow-wrap: anywhere` gebaut wurde (docs/46 §20.11).',
                        $wo,
                    ),
                );

                $this->assertLessThanOrEqual(
                    self::MAX_LENGTH,
                    mb_strlen($inhalt),
                    sprintf(
                        "In %s ist ein `.literal` %d Zeichen lang, erlaubt sind %d.\n\n".
                        'Ein Literal ist ein Beispiel im Fliesstext und kein Ort für Werte. Was länger '.
                        'ist, gehört in ein gewöhnliches `.ident` — das darf brechen.',
                        $wo,
                        mb_strlen($inhalt),
                        self::MAX_LENGTH,
                    ),
                );
            }
        }

        // Ein Ausdruck, der nichts findet, ist kein bestandener Test.
        $this->assertGreaterThanOrEqual(
            4,
            $gesehen,
            'Es werden kaum `.literal` gefunden — dann prüft dieser Wächter nichts, und die Ausnahme '.
            'in MobileLayoutTest steht ohne ihre Zusage da.',
        );
    }

    /**
     * Ein Ankreuzfeld steht in einem `.toggle` und nicht in einem `.field`.
     *
     * ## Der Fund
     *
     * `Files/Search.vue` gab dem Kästchen „auch im Inhalt" ein
     * `class="field inline"`. Damit greift `.field input { width: 100% }` —
     * eine Regel für Textfelder —, und bei 390 px wurde daraus ein Kasten von
     * **390 × 44 px** mitten in der Suchleiste (`docs/64`, Befund 1). Gemessen
     * im Container; mit `.toggle` sind es **17 × 17**, und die Leiste
     * schrumpft von 207 auf 171 px.
     *
     * > **Ein Baustein, der die Regel eines anderen erbt, sieht aus wie der
     * > andere.**
     *
     * Der Dokumentüberlauf war dabei **0 px** — es gab nichts zu messen, nur
     * etwas zu sehen. Genau dagegen steht dieser Fall: Er liest die Vorlage
     * und braucht kein Bild.
     *
     * ## Warum das Elternteil und nicht die Klasse am Feld
     *
     * `.field input` trifft über den Vorfahren. Ein Kästchen kann also selbst
     * makellos ausgezeichnet sein und trotzdem die falsche Form bekommen, weil
     * das Label darüber ein `.field` ist. Gefragt wird deshalb nach dem Label.
     */
    public function test_a_checkbox_is_not_dressed_as_a_field(): void
    {
        $gesehen = 0;

        foreach ($this->vueFiles(dirname(__DIR__, 2).'/resources/js') as $datei) {
            $quelle = (string) file_get_contents($datei);
            $wo = basename($datei);

            foreach ($this->checkboxes($quelle) as $stelle) {
                $form = $this->classBefore($quelle, $stelle);

                if ($form === '') {
                    continue;
                }

                $gesehen++;

                $this->assertMatchesRegularExpression(
                    '/\\b(toggle|check)\\b/',
                    $form,
                    sprintf(
                        'In %s steht ein Ankreuzfeld in „%s" statt in einem `.toggle`.

'.
                        '`.field input` gibt jedem Feld volle Breite und `--tap` Höhe — für ein Textfeld '.
                        'richtig, für ein Kästchen nicht. Bei 390 px wird daraus ein Kasten über die ganze '.
                        'Zeile, und der Dokumentüberlauf bleibt dabei 0 (docs/64, Befund 1).

'.
                        'Die Form für ein Kästchen ist `.toggle`; in einer Tabellenkopfzeile `.check`.',
                        $wo,
                        $form,
                    ),
                );
            }
        }

        $this->assertGreaterThanOrEqual(
            10,
            $gesehen,
            'Es werden kaum Ankreuzfelder gefunden — dann prüft dieser Fall nichts.',
        );
    }

    /**
     * Die Stellen, an denen ein Ankreuzfeld steht.
     *
     * @return list<int>
     */
    private function checkboxes(string $quelle): array
    {
        preg_match_all('/type="checkbox"/', $quelle, $treffer, PREG_OFFSET_CAPTURE);

        return array_map(static fn (array $t): int => (int) $t[1], $treffer[0]);
    }

    /**
     * Die nächstgelegene Klassenangabe vor einer Stelle.
     *
     * **Der erste Anlauf suchte das nächste `<label>` und meldete prompt einen
     * Fund, den es nicht gibt:** In der Datenbankkonsole steht das Kästchen in
     * einem `<span class="toggle">`, und dieses wiederum in einem
     * `<label class="field">`, das die ganze Zeile umschliesst. Rückwärts bis
     * zum Label gesucht, findet man das `.field` und übersieht das `.toggle`
     * dazwischen.
     *
     * > **Ein Wächter, der bis zum Vorfahren sucht, überspringt den Nachbarn.**
     *
     * Gefragt wird deshalb nach der **nächstgelegenen** Klasse — das ist die
     * Form, die das Kästchen wirklich trägt.
     */
    private function classBefore(string $quelle, int $stelle): string
    {
        /*
         * **Zuerst das eigene Tag.** In der Dateiliste steht `class="check"`
         * *hinter* `type="checkbox"` — wer nur rückwärts sucht, findet die
         * Tabelle darüber und meldet einen Fund, den es nicht gibt.
         *
         * > **Eine Suche rückwärts findet nicht, was rechts davon steht.**
         */
        $auf = strrpos(substr($quelle, 0, $stelle), '<');

        if ($auf !== false) {
            $zitat = null;
            $laenge = strlen($quelle);

            for ($i = $auf; $i < $laenge; $i++) {
                $zeichen = $quelle[$i];

                if ($zitat !== null) {
                    if ($zeichen === $zitat) {
                        $zitat = null;
                    }

                    continue;
                }

                if ($zeichen === '"' || $zeichen === "'") {
                    $zitat = $zeichen;

                    continue;
                }

                if ($zeichen === '>') {
                    $tag = substr($quelle, $auf, $i - $auf + 1);

                    if (preg_match('/class="([^"]*)"/', $tag, $eigen) === 1) {
                        return $eigen[1];
                    }

                    break;
                }
            }
        }

        if (preg_match_all('/class="([^"]*)"/', substr($quelle, 0, $stelle), $treffer) === 0) {
            return '';
        }

        return (string) end($treffer[1]);
    }

    /**
     * Alle `.vue`-Dateien unterhalb eines Verzeichnisses.
     *
     * @return list<string>
     */
    private function vueFiles(string $wurzel): array
    {
        $dateien = [];

        foreach ((array) scandir($wurzel) as $eintrag) {
            if (! is_string($eintrag) || $eintrag === '.' || $eintrag === '..') {
                continue;
            }

            $pfad = $wurzel.'/'.$eintrag;

            if (is_dir($pfad)) {
                $dateien = array_merge($dateien, $this->vueFiles($pfad));

                continue;
            }

            if (str_ends_with($eintrag, '.vue')) {
                $dateien[] = $pfad;
            }
        }

        return $dateien;
    }
}
