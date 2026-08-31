<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\WithoutMarkupComments;

/**
 * Jede Tabelle sagt, welche Form sie auf einer schmalen Fläche annimmt.
 *
 * ## Der Befund, gegen den es diesen Wächter gibt
 *
 * Die Dienste-Seite bekam am 30. August 2026 zwei Tabellen **ohne Klasse**. Auf
 * `cloudsrv24` bei 390 px war die Dienstetabelle damit 1005 px breit bei 358 px
 * sichtbar, und „kein nächster Termin" ragte **zehn Pixel** über den rechten
 * Rand seines Rollbehälters — der eine Satz, an dem das Abnahmekriterium von A2
 * hängt.
 *
 * Das Dokument schob dabei nicht, und die Messung, die dieses Projekt seit
 * `v0.4.0-rc.4` fährt, meldete zu Recht `0`.
 *
 * > **Eine Zahl, die am Dokument misst, sagt nichts über eine Zelle, die selbst
 * > rollen darf.**
 *
 * ## Warum eine Klasse und nicht ein Aussehen
 *
 * Ausgezählt am 31. August 2026 über `resources/js`: **jede** Tabelle dieses
 * Panels trägt eine der drei Formen — `stacks` für Verzeichnisse, `pairs` für
 * Name-und-Wert, `rows` für die eine Zeilenansicht der Konsole, die waagerecht
 * rollen **soll**. Die beiden der Dienste-Seite waren die einzigen ohne, und
 * genau so entsteht dieser Fehler: nicht durch eine falsche Wahl, sondern
 * dadurch, dass niemand gewählt hat.
 *
 * > **Eine Voreinstellung, die niemand getroffen hat, sieht aus wie eine
 * > Entscheidung.**
 *
 * Der Kommentar in `app.css` nannte „Dienste" ausdrücklich als `scrolls`-Fall,
 * während die Übersicht ihre Dienstetabelle seit jeher stapelt. Er ist
 * berichtigt — und dieser Wächter ist der Grund, dass die Regel jetzt geprüft
 * wird statt behauptet.
 *
 * > **Eine Zeile im Kommentar, die eine Konvention behauptet, veraltet ohne
 * > Vorwarnung.**
 *
 * ## Was er nicht hält
 *
 * Ob die gewählte Form die **richtige** ist. Ein Verzeichnis, das `pairs`
 * trägt, fällt hier nicht auf — das entscheidet, wie man die Ansicht liest, und
 * dafür gibt es keine Eigenschaft des Quelltextes.
 *
 * > **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und nicht
 * > als Zusage.**
 */
final class MobileTableTest extends TestCase
{
    use WithoutMarkupComments;

    /**
     * Die drei Formen, die es gibt.
     *
     * Sie kommen **nicht** aus einer Liste im Test, sondern werden gegen
     * `app.css` gehalten: Eine vierte Form, die jemand einführt, ohne sie dort
     * zu gestalten, ist derselbe Fehler wie eine fehlende.
     */
    private const FORMEN = ['stacks', 'pairs', 'rows'];

    private function repo(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> */
    private function templates(): array
    {
        $treffer = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->repo().'/resources/js'),
        );

        foreach ($iterator as $datei) {
            if ($datei->isFile() && $datei->getExtension() === 'vue') {
                $treffer[] = $datei->getPathname();
            }
        }

        sort($treffer);

        return $treffer;
    }

    /**
     * Kein `<table>` steht ohne eine der drei Formen da.
     */
    public function test_every_table_names_its_shape(): void
    {
        $ohne = [];
        $gezaehlt = 0;

        foreach ($this->templates() as $pfad) {
            $quelle = $this->withoutMarkupComments((string) file_get_contents($pfad));

            preg_match_all('/<table\b([^>]*)>/s', $quelle, $treffer, PREG_SET_ORDER);

            foreach ($treffer as $t) {
                $gezaehlt++;
                $attribute = $t[1];

                $hat = false;

                foreach (self::FORMEN as $form) {
                    // Gesucht wird das Wort in einem `class`-Wert und nicht
                    // irgendwo im Attributtext: `:key="rows"` ist keine Form.
                    if (preg_match('/class="[^"]*\b'.$form.'\b[^"]*"/', $attribute) === 1) {
                        $hat = true;

                        break;
                    }
                }

                if (! $hat) {
                    $ohne[] = sprintf(
                        '%s: <table%s>',
                        basename(dirname($pfad)).'/'.basename($pfad),
                        rtrim($attribute),
                    );
                }
            }
        }

        // **Die Untergrenze zählt jede Tabelle und nicht die ohne Form.** Zöge
        // die Regel um, stünde ein Zähler über den Fundstellen auf null und
        // meldete Rot für genau die Ordnung, die er durchsetzen soll.
        $this->assertGreaterThan(20, $gezaehlt,
            'Der Ausdruck findet kaum noch Tabellen — er trifft nicht mehr.');

        $this->assertSame([], $ohne, sprintf(
            "Diese Tabellen sagen nicht, welche Form sie auf einer schmalen Fläche annehmen:\n\n  %s\n\n"
            .'Ohne eine der drei rollt sie waagerecht, und was rechts steht, liest bei 390 px niemand.',
            implode("\n  ", $ohne),
        ));
    }

    /**
     * Jede Form, die eine Vorlage benutzt, ist in `app.css` gestaltet.
     *
     * Die Gegenrichtung zum Fall darüber, und die, an der ein toter Eintrag
     * wirklich entsteht: Wer eine Form umbenennt, trägt den neuen Namen in die
     * Vorlagen nach — und die Regel in `app.css` bleibt unter dem alten liegen.
     */
    public function test_every_shape_is_styled(): void
    {
        $css = (string) file_get_contents($this->repo().'/resources/css/app.css');

        foreach (self::FORMEN as $form) {
            // **Gesucht wird ein Selektor, der einen Block öffnet, und nicht
            // eine Zeile, die mit einem Punkt beginnt.** Der erste Wurf verlangte
            // `^\s*\.pairs` und war rot: Die Regel heisst `table.pairs`.
            //
            // > Ein Ausdruck, der die gewohnte Schreibweise kennt, prüft die
            // > Gewohnheit und nicht die Regel.
            $this->assertMatchesRegularExpression(
                '/^[^{}\n]*\.'.$form.'\b[^{}]*\{/m',
                $css,
                'Die Form '.$form.' hat in app.css keine Regel — sie tut auf einer Seite nichts.',
            );
        }
    }

    /**
     * Eine gestapelte Zelle trägt die Beschriftung ihrer Spalte.
     *
     * `.stacks td::before` rendert `attr(data-column)`; ohne das Attribut steht
     * bei 390 px ein Wert ohne Wort davor. Der Spaltenkopf ist dort ausgeblendet
     * — es gibt dann nichts, was die Zelle benennt.
     *
     * **Zwei Zellen dürfen ohne:** die über die ganze Breite (`colspan`, die
     * Leerzeile einer Liste) und **die mit dem Knopf am Zeilenende**. Die
     * zweite steht wörtlich so in `app.css` — „eine Zelle ohne Beschriftung —
     * der Knopf am Zeilenende — nimmt die ganze Breite" —, und der erste Wurf
     * dieses Wächters kannte sie nicht: Er meldete sechs Zellen aus sechs
     * Seiten, alle sechs zu Recht so geschrieben.
     *
     * > **Ein Wächter, der zu viel meldet, wird abgeschaltet — und zwar von
     * > dem, der ihn gebaut hat.**
     *
     * Geprüft wird deshalb nicht „hat kein `data-column`", sondern „hat kein
     * `data-column` **und trägt trotzdem einen Wert**": Der Knopf ist ein
     * Bedienelement und kein Wert, den man benennen müsste.
     */
    public function test_a_stacked_cell_carries_its_column_name(): void
    {
        $ohne = [];
        $gezaehlt = 0;

        foreach ($this->templates() as $pfad) {
            $quelle = $this->withoutMarkupComments((string) file_get_contents($pfad));

            foreach ($this->stackedBodies($quelle) as $rumpf) {
                preg_match_all('/<td\b([^>]*)>/s', $rumpf, $treffer, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

                foreach ($treffer as $t) {
                    $gezaehlt++;

                    // Eine Zelle über die ganze Breite — die Leerzeile einer
                    // Liste — hat keine Spalte, die sie benennen könnte.
                    $attribute = $t[1][0];

                    if (str_contains($attribute, 'colspan')) {
                        continue;
                    }

                    if (str_contains($attribute, 'data-column')) {
                        continue;
                    }

                    if ($this->carriesOnlyAControl($rumpf, (int) $t[0][1])) {
                        continue;
                    }

                    $ohne[] = basename(dirname($pfad)).'/'.basename($pfad).': <td'.rtrim($attribute).'>';
                }
            }
        }

        $this->assertGreaterThan(20, $gezaehlt,
            'Der Ausdruck findet kaum noch Zellen in gestapelten Tabellen — er trifft nicht mehr.');

        $this->assertSame([], $ohne, sprintf(
            "Diese Zellen stehen bei 390 px ohne Beschriftung da:\n\n  %s",
            implode("\n  ", array_slice($ohne, 0, 12)),
        ));
    }

    /**
     * Steht in dieser Zelle ein Bedienelement und sonst kein Wert?
     *
     * Das ist der eine Fall, den `app.css` ausdrücklich ohne Beschriftung
     * führt. Gemessen wird der **Text** der Zelle ohne ihre Elemente: Bleibt
     * nichts übrig, gibt es auch nichts zu benennen.
     */
    private function carriesOnlyAControl(string $rumpf, int $offset): bool
    {
        $ende = strpos($rumpf, '</td>', $offset);

        if ($ende === false) {
            return false;
        }

        $inhalt = substr($rumpf, $offset, $ende - $offset);

        if (preg_match('/<(Link|button|a)\b/i', $inhalt) !== 1) {
            return false;
        }

        // **Das Bedienelement wird samt seiner Beschriftung entfernt und nicht
        // nur seine Marken.** Der erste Wurf nahm `strip_tags()` und liess
        // „Bearbeiten" stehen — der Text eines Knopfes ist aber seine
        // Beschriftung und kein Wert, den eine Spalte benennen müsste.
        //
        // > Ein Wächter, der Marken abstreift, hält den Text darin für Inhalt.
        $ohneGriff = preg_replace('#<(Link|button|a)\b.*?</\1>#is', '', $inhalt) ?? $inhalt;

        return trim(strip_tags($ohneGriff)) === '';
    }

    /**
     * Der `tbody` jeder gestapelten Tabelle.
     *
     * Gelesen wird vorwärts vom `<table class="… stacks …">` bis zu seinem
     * `</table>`; verschachtelte Tabellen gibt es hier nicht, und gäbe es sie,
     * meldete die Untergrenze den Ausfall.
     *
     * @return list<string>
     */
    private function stackedBodies(string $quelle): array
    {
        $rumpfe = [];
        $stelle = 0;

        while (preg_match('/<table\b[^>]*class="[^"]*\bstacks\b[^"]*"[^>]*>/s', $quelle, $t, PREG_OFFSET_CAPTURE, $stelle) === 1) {
            $anfang = (int) $t[0][1] + strlen($t[0][0]);
            $ende = strpos($quelle, '</table>', $anfang);

            if ($ende === false) {
                break;
            }

            $rumpfe[] = substr($quelle, $anfang, $ende - $anfang);
            $stelle = $ende;
        }

        return $rumpfe;
    }

    /**
     * In einer Bezeichnungstabelle trägt die **Zelle** ihre Kennung.
     *
     * ## Der Befund, gegen den es diese Regel gibt
     *
     * Gemessen am 31. August 2026 bei 390 px an der Vorgangsseite, die mit
     * ihrem Gegenstand eine Zeile bekommen hat: `<td class="right">` mit einem
     * `<a class="link ident">` darin schob das Dokument um **59 px** aus dem
     * Bild. Die Zelle daneben — `<td class="right ident name">` — brach im
     * selben Tabellenkörper richtig um.
     *
     * Der Grund steht in `app.css` zweimal: `table.pairs td.right.ident` löst
     * die Zelle aus ihrem `flex: none` und erlaubt den Umbruch. Eine Kennung,
     * die nur *in* der Zelle steht, erreicht diese Ausnahme nicht — und
     * `td .ident { white-space: nowrap }` gewinnt.
     *
     * > **Eine Ausnahme, die für die Zelle geschrieben ist, gilt nicht für das,
     * > was in ihr steht — und beide sehen im Markup gleich aus.**
     *
     * Das ist die **vierte** Wiederholung desselben Fehlers an derselben
     * Tabelle; über beiden Regeln in `app.css` steht die Lehre schon
     * ausgeschrieben. Deshalb steht sie jetzt als Wächter da und nicht als
     * dritter Kommentar.
     *
     * ## Was er nicht hält
     *
     * Ob die Zelle danach wirklich umbricht. Das ist eine Messung im Browser
     * und keine Eigenschaft des Markups — sie steht in `docs/91 §18`.
     */
    public function test_an_identifier_in_a_pairs_cell_belongs_to_the_cell(): void
    {
        $falsch = [];
        $gepruefte = 0;

        foreach ($this->templates() as $pfad) {
            $quelle = $this->withoutMarkupComments((string) file_get_contents($pfad));

            foreach ($this->pairedBodies($quelle) as $rumpf) {
                foreach ($this->cells($rumpf) as [$auf, $inhalt]) {
                    $gepruefte++;

                    // Die Zelle selbst trägt `ident` — dann greift die
                    // Ausnahme, und was darin steht, ist gleichgültig.
                    //
                    // **Auch als Objektschlüssel.** Eine Zelle, die einmal
                    // einen gesprochenen Satz und einmal eine Kennung zeigt,
                    // trägt die Klasse an einer Bedingung: `:class="{ ident:
                    // … }"`. Ein Ausdruck wäre hier falsch — `ClassReachTest`
                    // kann eine Klasse aus einer Variablen nicht auflösen —,
                    // und ein festes `ident` machte aus dem Satz Monoschrift.
                    if (preg_match('/\bclass="[^"]*\bident\b/', $auf) === 1
                        || preg_match('/:class="\{[^"]*\bident\s*:/', $auf) === 1) {
                        continue;
                    }

                    if (preg_match('/\bclass="[^"]*\bident\b/', $inhalt) === 1) {
                        $falsch[] = basename($pfad).': '.trim($auf);
                    }
                }
            }
        }

        $this->assertGreaterThan(
            40,
            $gepruefte,
            'Es wurden kaum Zellen gelesen — dann prüft dieser Test fast nichts.',
        );

        $this->assertSame(
            [],
            $falsch,
            "Diese Zellen einer `pairs`-Tabelle tragen eine Kennung, ohne selbst eine zu sein:\n"
            .implode("\n", $falsch)
            ."\n\nBei 390 px hält `table.pairs td.right.ident` die Zelle davon ab, die Seite zu "
            .'schieben — eine Kennung *in* der Zelle erreicht diese Ausnahme nicht. Die Klasse '
            .'gehört an das `<td>`; der Verweis darin erbt die Schrift über `.link { font: inherit }`.',
        );
    }

    /**
     * Die Rümpfe aller `pairs`-Tabellen einer Vorlage.
     *
     * Dieselbe Machart wie {@see self::stackedBodies()} und aus demselben
     * Grund kein Parser: Was er nicht findet, prüft er nicht — und die
     * Untergrenze daneben meldet, wenn das zu oft passiert.
     *
     * @return list<string>
     */
    private function pairedBodies(string $quelle): array
    {
        $rumpfe = [];
        $stelle = 0;

        while (preg_match('/<table\b[^>]*class="[^"]*\bpairs\b[^"]*"[^>]*>/s', $quelle, $t, PREG_OFFSET_CAPTURE, $stelle) === 1) {
            $anfang = (int) $t[0][1] + strlen($t[0][0]);
            $ende = strpos($quelle, '</table>', $anfang);

            if ($ende === false) {
                break;
            }

            $rumpfe[] = substr($quelle, $anfang, $ende - $anfang);
            $stelle = $ende;
        }

        return $rumpfe;
    }

    /**
     * Die Zellen eines Tabellenkörpers — als Paar aus Anfangsmarke und Inhalt.
     *
     * @return list<array{string, string}>
     */
    private function cells(string $rumpf): array
    {
        $zellen = [];
        $stelle = 0;

        while (preg_match('/<td\b[^>]*>/s', $rumpf, $t, PREG_OFFSET_CAPTURE, $stelle) === 1) {
            $auf = (string) $t[0][0];
            $anfang = (int) $t[0][1] + strlen($auf);
            $ende = strpos($rumpf, '</td>', $anfang);

            if ($ende === false) {
                break;
            }

            $zellen[] = [$auf, substr($rumpf, $anfang, $ende - $anfang)];
            $stelle = $ende;
        }

        return $zellen;
    }
}
