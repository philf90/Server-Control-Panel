<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Tabellen kommen aus app.css, und ihre Zeilenhöhe aus der Dichtemarke.
 *
 * **Warum es diesen Test gibt — der Befund, den nichts gemeldet hat.**
 * §7.2 verspricht zwei Dichtestufen und nennt als erste Zeile ihrer Tabelle
 * die Zeilenhöhe: 34px auf der Adminfläche, 42px auf der Kundenfläche. Die
 * Marke dafür heisst `--row-height` und wurde von **zwei der 26 Seiten**
 * benutzt. Auf den übrigen 24 entstand die Zeilenhöhe aus `padding: 6px 8px`,
 * je Seite neu geschrieben — die Kundenfläche war dort also nicht ruhiger als
 * die Adminfläche, und niemand hat es gemerkt, weil kein Lauf danach fragt.
 *
 * Dazu kam, was daraus folgt: **zehn Seiten definieren `table`, und es gibt
 * zwei unvereinbare Fassungen.** Auf der Übersicht ein Spaltenkopf in
 * Versalien mit `--text-label` und `--row-height`; auf allen Listen
 * `th { text-align: left; color: var(--text-muted) }` mit `6px 8px` und ohne
 * Zeilenhöhe. Dieselbe Sache, zweimal gebaut, verschieden.
 *
 * Das ist genau das Muster, das `ButtonStyleTest` für Knöpfe schon einmal
 * beendet hat. Dieser Test ist dieselbe Regel für die Tabelle.
 *
 * **Was hier nicht geprüft wird:** wie eine Tabelle aussieht. Geprüft wird,
 * dass keine Seite ihre eigene Form erfindet — und dass die Zeilenhöhe aus
 * der Marke kommt, die die Dichtestufe umschaltet.
 */
final class TableStyleTest extends TestCase
{
    /**
     * Die Eigenschaften, die die Form einer Tabelle ausmachen.
     *
     * `color` steht bewusst nicht dabei: Eine Seite darf eine Zelle nach ihrem
     * Zustand einfärben (`td[data-status='suspended']`). Das ist eine Aussage
     * über den Inhalt und keine über die Form — und dass die Farbe aus einer
     * Marke kommt, prüft bereits die CI.
     */
    private const SHAPE = [
        'height', 'min-height', 'padding', 'padding-top', 'padding-bottom',
        'padding-left', 'padding-right', 'border', 'border-bottom', 'border-top',
        'border-collapse', 'border-spacing', 'font-size', 'font-weight',
        'text-transform', 'letter-spacing', 'width', 'background',
    ];

    /** Ein Selektor, der eine Tabelle meint. */
    private const TABLE_SELECTOR = '/(^|[\s,>+~])(table|thead|tbody|tfoot|tr|th|td)\b/';

    /** @return list<string> */
    private function vueFiles(): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/js', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        $this->assertGreaterThan(8, count($files), 'Es werden kaum Dateien gelesen — dann prüft dieser Test nichts.');

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace(dirname(__DIR__, 2).'/', '', $path);
    }

    public function test_no_component_styles_a_table_itself(): void
    {
        $found = [];
        $checked = 0;

        foreach ($this->vueFiles() as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('#<style[^>]*>(.*)</style>#su', $source, $match) !== 1) {
                continue;
            }

            // Ohne das klebt ein Kommentar vor einer Regel am Selektor, und ein
            // Kommentar, der das Wort „Tabelle" erklärt, liest sich für den
            // Ausdruck unten wie ein Tabellenselektor.
            $style = (string) preg_replace('#/\*.*?\*/#su', '', $match[1]);

            preg_match_all('/([^{}]*)\{([^{}]*)\}/s', $style, $rules, PREG_SET_ORDER);

            foreach ($rules as $rule) {
                $selector = trim($rule[1]);

                if (preg_match(self::TABLE_SELECTOR, $selector) !== 1) {
                    continue;
                }

                $checked++;

                foreach (self::SHAPE as $property) {
                    if (preg_match('/(^|[;\s])'.preg_quote($property, '/').'\s*:/', $rule[2]) === 1) {
                        $found[] = sprintf('%s  „%s" setzt %s', $this->relative($path), $selector, $property);
                    }
                }
            }
        }

        /*
         * Die Untergrenze zählt app.css mit.
         *
         * Sie belegt, dass `TABLE_SELECTOR` überhaupt noch auf eine Regel
         * passt — nicht, dass irgendeine Seite eine Tabelle gestaltet. Als die
         * letzte Seite ihr eigenes Tabellen-CSS abgab, stand `$checked` auf 0
         * und dieser Wächter meldete Rot für genau die Ordnung, die er
         * durchsetzen soll. Der Befund darunter kommt weiter ausschliesslich
         * aus den Komponenten.
         */
        $this->assertGreaterThan(4, $checked + $this->tableRulesInAppCss(),
            'Es werden kaum Tabellenregeln gefunden — dann prüft dieser Test nichts.');

        $this->assertSame([], $found, sprintf(
            "Diese Regeln geben einer Tabelle ihre eigene Form:\n  %s\n\n".
            'Die Form einer Tabelle steht in resources/css/app.css und sonst nirgends — '.
            "Zeilenhöhe, Innenabstand, Rahmen, Spaltenkopf.\nWer sie je Seite schreibt, hat nach dem ".
            'dritten Modul drei Fassungen davon, und die Dichtestufe aus §7.2 wirkt auf keiner.',
            implode("\n  ", $found),
        ));
    }

    /**
     * Eine Zelle hat senkrechte Polsterung, und sie bleibt unter der Obergrenze.
     *
     * ## Der Fund
     *
     * `td` stand auf `padding: 0 14px 0 0`, und der senkrechte Rhythmus kam
     * allein aus `height: var(--row-height)`. Das trägt, solange der Inhalt
     * einzeilig ist: Die Zeile ist höher als ihr Text, und der Rest sieht aus
     * wie Polsterung. Sobald der Inhalt höher wird — ein Ausgabekasten, zwei
     * Textzeilen —, ist die Höhe wirkungslos.
     *
     * > **Eine Höhe ist keine Polsterung. Sie sieht nur so aus, solange der
     * > Inhalt hineinpasst.**
     *
     * Zweimal auf einem Bild gesehen (`docs/64`, Befunde 7 und 8): 0px zwischen
     * Ausgabekasten und Trennlinie, 1px zwischen Marke und Linie darüber.
     *
     * ## Und warum hier eine Obergrenze steht
     *
     * Der erste Vorschlag war 8px — gemessen, aber nur in der Dichtestufe
     * `customer` mit ihren 48px. In `admin` mit 40px wächst eine **einzeilige**
     * Zeile damit auf 43px, und dann bestimmt die Polsterung die Zeilenhöhe
     * statt der Dichtestufe.
     *
     * > **Eine Messung an einer Dichtestufe ist keine über die Achse.**
     *
     * Gemessen im Container gegen das gebaute Stylesheet, beide Stufen, 0 bis
     * 8px: bis 6px bleibt die Zeile bei 40 bzw. 48, ab 7px wächst die
     * admin-Zeile. Die Zahl unten ist deshalb keine Vorliebe, sondern das
     * Ergebnis.
     */
    public function test_a_cell_has_vertical_padding_below_the_measured_ceiling(): void
    {
        $obergrenze = 6;
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
        $css = (string) preg_replace('#/\*.*?\*/#su', '', $css);

        $this->assertSame(
            1,
            preg_match('/(^|\})\s*td\s*\{([^{}]*)\}/s', $css, $regel),
            'In app.css gibt es keine Regel für `td` mehr.',
        );

        $this->assertSame(
            1,
            preg_match('/(^|[;\s])padding\s*:\s*(\d+)px/', $regel[2], $polster),
            implode('
', [
                'Die Regel für `td` setzt kein `padding` mit einem senkrechten Wert.',
                '',
                'Ohne ihn kommt der senkrechte Rhythmus allein aus `height`, und der wirkt nur,',
                'solange der Inhalt einer Zelle in die Zeilenhöhe passt. Ein Ausgabekasten oder',
                'eine zweite Textzeile stossen dann an die Trennlinie (docs/64, Befunde 7 und 8).',
            ]),
        );

        $wert = (int) $polster[2];

        $this->assertGreaterThan(
            0,
            $wert,
            'Die senkrechte Polsterung einer Zelle steht auf 0 — das ist der Zustand vor der '.
            'Behebung von docs/64, Befunde 7 und 8.',
        );

        $this->assertLessThanOrEqual(
            $obergrenze,
            $wert,
            sprintf(
                'Die senkrechte Polsterung einer Zelle steht auf %dpx, gemessen sind höchstens %dpx.

'.
                'Darüber wächst eine **einzeilige** Zeile in der Dichtestufe `admin` über ihre
'.
                '40px hinaus, und dann bestimmt nicht mehr die Dichtestufe die Zeilenhöhe,
'.
                'sondern die Polsterung.

'.
                'Wer die Zahl erhöhen will, misst vorher beide Stufen — eine Messung an einer '.
                'Dichtestufe ist keine über die Achse.',
                $wert,
                $obergrenze,
            ),
        );
    }

    /**
     * Die Zeilenhöhe kommt aus der Marke, die die Dichte umschaltet.
     *
     * Ohne diese Prüfung ist `--row-height` eine Marke, die gesetzt wird und
     * die niemand liest — und die Dichtestufe der Kundenfläche ist ein
     * Versprechen im Dokument statt einer Eigenschaft der Oberfläche.
     */
    public function test_the_row_height_comes_from_the_density_token(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
        $css = (string) preg_replace('#/\*.*?\*/#su', '', $css);

        preg_match_all('/([^{}@]*)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER);

        $setzt = false;

        foreach ($rules as $rule) {
            if (preg_match(self::TABLE_SELECTOR, trim($rule[1])) !== 1) {
                continue;
            }

            if (preg_match('/(^|[;\s])height\s*:\s*var\(--row-height\)/', $rule[2]) === 1) {
                $setzt = true;

                break;
            }
        }

        $this->assertTrue($setzt, implode("\n", [
            'In resources/css/app.css bezieht keine Tabellenregel ihre Zeilenhöhe aus var(--row-height).',
            '',
            '§7.2 staffelt die Zeilenhöhe je Dichtestufe — 34px auf der Adminfläche, 42px auf der',
            'Kundenfläche. Ohne eine Regel, die die Marke liest, ist beides derselbe Wert, und die',
            'Kundenfläche wird nicht ruhiger. Genau so war es auf 24 von 26 Seiten.',
        ]));
    }

    /**
     * Und die Marke gibt es in beiden Dichtestufen.
     *
     * Ein `var(--row-height)` ohne Wert fällt still auf die geerbte Höhe
     * zurück — der Browser meldet nichts, und die Tabelle sieht bloss anders
     * aus als gedacht. Dieselbe Falle wie bei den Schriftmarken.
     *
     * **Dieser Test hat beim Gegenprüfen zuerst nicht zugebissen.** Die
     * Dichtestufe `customer` wurde absichtlich um `--row-height` erleichtert,
     * und er blieb grün: In app.css steht `:root[data-density='customer']` ein
     * zweites Mal, nämlich im `@media (max-width: 720px)`-Block, wo beide
     * Stufen auf 44px zusammenlaufen. Der Ausdruck fand diese Fundstelle und
     * war zufrieden.
     *
     * Damit hätte er eine Gestaltung durchgelassen, in der die Dichtestufe nur
     * auf dem Telefon existiert — also genau dort, wo sie keine Rolle spielt.
     * Die Grundwerte stehen ausserhalb der Haltepunkte; dort wird gesucht.
     */
    public function test_the_density_token_exists_in_both_steps(): void
    {
        $css = $this->withoutMediaBlocks(
            (string) preg_replace('#/\*.*?\*/#su', '', (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css')),
        );

        foreach (['admin', 'customer'] as $dichte) {
            $this->assertSame(
                1,
                preg_match(
                    '/\[data-density=\'?'.$dichte.'\'?\][^{]*\{[^{}]*--row-height\s*:\s*\d/s',
                    $css,
                ),
                sprintf(
                    'In app.css setzt die Dichtestufe „%s" ausserhalb der Haltepunkte kein --row-height. '.
                    'Eine Stufe, die es nur im @media-Block gibt, wirkt genau dort nicht, wo sie gemeint ist.',
                    $dichte,
                ),
            );
        }
    }

    /**
     * In einer Bezeichnungstabelle bricht eine Kennung — auch auf dem Schreibtisch.
     *
     * **Der Befund vom 7. August 2026, auf dem Zielserver.** Auf der
     * Domainseite von `cloudlab24.ipv64.de` lief
     * `/var/www/vhosts/cloudlab24.ipv64.de/logs/…` aus dem Bereich
     * „Stammdaten" heraus und legte sich über den Bereich „Zertifikat".
     * Nachgemessen: 173px bei 1440px Fensterbreite, 134px bei 1024px.
     *
     * **Die Begründung für `nowrap` war richtig gedacht und hier falsch.** Sie
     * lautet: Ein Pfad, der mitten im Verzeichnisnamen umbricht, ist schwerer
     * zu lesen als einer, „für den man die Tabelle schiebt — und schieben kann
     * man dort". In einer Bezeichnungstabelle kann man das nicht: Sie steht in
     * einem Bereich mit `min-width: 0` neben zwei weiteren, und es gibt keinen
     * Rollbehälter. Die Wahl ist nicht „umbrechen oder schieben", sondern
     * **umbrechen oder den Nachbarn überschreiben**.
     *
     * **Warum das kein Fall für `MobileLayoutTest` ist.** Dort steht schon ein
     * Wächter zu `.ident`, und der lässt `nowrap` an einer Zellenauswahl
     * ausdrücklich zu — aus genau dieser Annahme. Er hat deshalb nichts
     * gemeldet, und er soll es auch weiter nicht: Eine Tabelle mit `.scrolls`
     * rollt wirklich. Was hier geprüft wird, gilt der Bezeichnungstabelle.
     *
     * **Und geprüft wird ausserhalb der Haltepunkte.** Die Ausnahme gab es
     * schon einmal — im `@media`-Block für 390px, für den einen Ort, an dem
     * der Überlauf auffiel, statt für die Regel. Eine Regel, die es nur im
     * `@media`-Block gibt, wirkt genau dort nicht, wo dieser Fund entstanden
     * ist.
     */
    public function test_an_identifier_in_a_pairs_table_may_break_on_the_desktop(): void
    {
        $css = $this->withoutMediaBlocks(
            (string) preg_replace('#/\*.*?\*/#su', '', (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css')),
        );

        preg_match_all('/([^{}]*)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER);

        $gelesen = 0;
        $bricht = false;

        foreach ($rules as $rule) {
            $selector = trim($rule[1]);

            if (! str_contains($selector, 'table.pairs') || ! str_contains($selector, '.ident')) {
                continue;
            }

            $gelesen++;

            $this->assertDoesNotMatchRegularExpression(
                '/white-space:\s*nowrap/',
                $rule[2],
                sprintf(
                    '„%s" hält eine Kennung in einer Bezeichnungstabelle vom Umbruch ab. Dort gibt es nichts '.
                    'zu schieben — der Wert läuft in den Nachbarbereich und legt sich über dessen Text.',
                    $selector,
                ),
            );

            if (str_contains($rule[2], 'overflow-wrap: anywhere')) {
                $bricht = true;
            }
        }

        // Die Untergrenze zählt, wo die Regel stehen *darf*: Fällt sie beim
        // Aufräumen mit einer anderen zusammen, meldet dieser Wächter sonst Rot
        // für genau die Ordnung, die er durchsetzen soll.
        $this->assertGreaterThanOrEqual(
            1,
            $gelesen,
            'Es wird keine Regel zu `table.pairs … .ident` ausserhalb der Haltepunkte gelesen — dann prüft '.
            'dieser Test nichts.',
        );

        $this->assertTrue(
            $bricht,
            'In einer Bezeichnungstabelle braucht eine Kennung `overflow-wrap: anywhere`, und zwar ausserhalb '.
            'der Haltepunkte. Ein Pfad ohne Leerzeichen bleibt sonst so breit, wie er ist, und die Tabelle '.
            'wird breiter als ihr Bereich — gemessen 173px bei 1440px.',
        );
    }

    /**
     * Das Stylesheet ohne seine `@media`-Blöcke.
     *
     * Über Klammern gezählt: Ein regulärer Ausdruck endet an der ersten
     * schliessenden Klammer und schneidet den Block mitten in der ersten Regel
     * ab — dieselbe Falle, die `ButtonStyleTest` schon einmal gestellt hat.
     */
    private function withoutMediaBlocks(string $css): string
    {
        while (($start = strpos($css, '@media')) !== false) {
            $open = strpos($css, '{', $start);

            if ($open === false) {
                break;
            }

            $depth = 1;
            $end = strlen($css);

            for ($i = $open + 1; $i < strlen($css); $i++) {
                $depth += match ($css[$i]) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };

                if ($depth === 0) {
                    $end = $i + 1;

                    break;
                }
            }

            $css = substr($css, 0, $start).substr($css, $end);
        }

        return $css;
    }

    /** Wie viele Regeln in app.css eine Tabelle meinen. Nur zum Zählen. */
    private function tableRulesInAppCss(): int
    {
        $css = (string) preg_replace(
            '#/\\*.*?\\*/#su',
            '',
            (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css'),
        );

        preg_match_all('/([^{}]*)\\{([^{}]*)\\}/s', $css, $rules, PREG_SET_ORDER);

        $found = 0;

        foreach ($rules as $rule) {
            if (preg_match(self::TABLE_SELECTOR, trim($rule[1])) === 1) {
                $found++;
            }
        }

        return $found;
    }

    /**
     * Eine Zelle, die stapelt, richtet ihre Nachbarn oben aus — und ihre
     * Knopfreihen bekommen Abstand.
     *
     * **Beides ist am 11. August 2026 auf `cloudsrv24` aufgefallen**, beim
     * zweiten Netz eines Zugangs: Die zwei „Zurücknehmen" klebten ohne Lücke
     * aneinander, und Benutzername, Zustand und Aktionen standen in der Mitte
     * neben dem Stapel. Mit einem Netz sieht beides normal aus, und genau
     * deshalb hat es niemand gesehen — auch keine Aufnahme, denn die
     * Abnahmedaten trugen bis dahin je einen Eintrag.
     *
     * > **Ein Baustein, den man nur mit einem Element bebildert, ist für zwei
     * > nicht geprüft.**
     *
     * `.button-row` legt seinen `gap` waagerecht; zwei gestapelte Reihen sind
     * davon nicht berührt. Und Tabellenzellen erben `vertical-align: middle`,
     * was richtig ist, solange jede Zelle eine Zeile hoch bleibt.
     *
     * **Der Bruch dazu** (`tests/waechter-brechen.sh`): die Regel
     * `tr:has(td.multiline)` aus `app.css` streichen.
     */
    public function test_a_stacked_cell_aligns_its_row_and_spaces_its_rows(): void
    {
        $css = (string) preg_replace(
            '#/\\*.*?\\*/#su',
            '',
            (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css'),
        );

        preg_match_all('/([^{}]*)\\{([^{}]*)\\}/s', $css, $rules, PREG_SET_ORDER);

        $ausrichtung = false;
        $polster = false;
        $abstand = false;

        foreach ($rules as $rule) {
            $selector = trim($rule[1]);

            if (str_contains($selector, ':has(td.multiline)')) {
                if (preg_match('/vertical-align:\\s*top/', $rule[2]) === 1) {
                    $ausrichtung = true;
                }

                /*
                 * **Und sie gibt den Abstand zurück, den sie wegnimmt.** `td`
                 * setzt kein senkrechtes Polster; der Abstand zur Linie darüber
                 * kam allein daraus, dass eine Zeile hohe Zelle in
                 * `--row-height` mittig sass. Wer nur die Ausrichtung umstellt,
                 * lässt die erste Zeile an der Trennlinie kleben — so gemeldet,
                 * eine Fassung nach der Ausrichtung.
                 */
                if (preg_match('/padding-top:\\s*calc/', $rule[2]) === 1) {
                    $polster = true;
                }
            }

            if (str_contains($selector, '.button-row + .button-row')
                && preg_match('/margin-top:/', $rule[2]) === 1) {
                $abstand = true;
            }
        }

        $this->assertTrue($ausrichtung,
            'Eine Zeile mit gestapelter Zelle richtet ihre Zellen nicht oben aus. Ab dem zweiten '
            .'Eintrag steht der Benutzername in der Mitte neben dem Stapel, und je mehr Einträge, '
            .'desto falscher liest sich die Zeile.');

        $this->assertTrue($polster,
            'Die Regel richtet oben aus, ohne den Abstand zu ersetzen, den die mittige Lage gegeben '
            .'hat. `td` hat kein senkrechtes Polster — die erste Zeile klebt dann an der Trennlinie '
            .'darüber. Gerechnet aus --row-height und der Zeilenhöhe, damit es in jeder Dichtestufe '
            .'stimmt und nicht nur in der, in der jemand nachgesehen hat.');

        $this->assertTrue($abstand,
            'Zwei Knopfreihen übereinander bekommen keinen Abstand — sie kleben aneinander. '
            .'`.button-row` setzt seinen gap waagerecht.');
    }
}
