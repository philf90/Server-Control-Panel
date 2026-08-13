<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Die Vorgaben aus docs/24 — mechanisch geprüft.
 *
 * **Warum das ein Test ist.** Eine schmale Fläche fällt beim Entwickeln nicht
 * auf: Man baut am Schreibtisch, dort ist alles breit genug, und die Seite
 * sieht richtig aus. Der Bruch zeigt sich erst auf einem Telefon, und dann bei
 * demjenigen, der das Panel gerade benutzen wollte. Genau dieselbe Lage wie
 * bei den Schriftgrößen: keine Regel, kein Werkzeug, das sie prüft — und ein
 * Jahr später zehn Werte für fünf Rollen.
 *
 * Geprüft wird nicht, wie eine Seite aussieht. Geprüft wird, dass sie sich an
 * die vier Vorgaben hält, deren Verletzung man am Schreibtisch nicht sieht.
 */
final class MobileLayoutTest extends TestCase
{
    /** Die Haltepunkte aus docs/24 §1. */
    private const BREAKPOINTS = ['720px', '480px'];

    /** @return list<string> */
    private function files(string $directory, string $extension): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/'.$directory, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === $extension) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace(dirname(__DIR__, 2).'/', '', $path);
    }

    /** Der `<template>`-Block einer Vue-Datei, ohne HTML-Kommentare. */
    private function template(string $source): string
    {
        if (preg_match('#<template>(.*)</template>#su', $source, $match) !== 1) {
            return '';
        }

        return (string) preg_replace('/<!--.*?-->/su', '', $match[1]);
    }

    public function test_only_the_two_breakpoints_are_used(): void
    {
        $sources = array_merge($this->files('resources/js', 'vue'), $this->files('resources/css', 'css'));

        $this->assertGreaterThan(10, count($sources), 'Es werden kaum Dateien gelesen — dann prüft dieser Test nichts.');

        $found = 0;

        foreach ($sources as $path) {
            preg_match_all('/@media[^{]*\(\s*(?:max|min)-width:\s*([0-9.]+px)\s*\)/', (string) file_get_contents($path), $matches);

            foreach ($matches[1] as $width) {
                $found++;

                $this->assertContains(
                    $width,
                    self::BREAKPOINTS,
                    sprintf(
                        '%s benutzt den Haltepunkt %s. docs/24 kennt nur %s — ein dritter Wert lässt Seiten '.
                        'bei 640px umbrechen und bei 700px nicht.',
                        $this->relative($path),
                        $width,
                        implode(' und ', self::BREAKPOINTS),
                    ),
                );
            }
        }

        $this->assertGreaterThan(3, $found, 'Der Ausdruck findet keine Haltepunkte mehr.');
    }

    /**
     * Keine Seite misst in `vh`.
     *
     * `vh` zählt die Adressleiste des Telefons mit, die beim Rollen
     * verschwindet — die Seite steht dann im Ausgangszustand zu hoch. Gemeint
     * ist `dvh` (`docs/24 §6`).
     *
     * **Das Stylesheet stand bis Schritt 5 von P5c nicht in dieser Liste**, und
     * das war eine Lücke: Die Regel gilt für jede Höhenangabe, und die
     * allermeisten stehen in `app.css` und nicht in einer Komponente. Aufgefallen
     * ist es, weil `max-height: 60vh` an der Zelleinzelsicht dort hineingeriet —
     * an einer Stelle, die dieser Wächter nie angesehen hätte.
     *
     * > **Ein Wächter, der nur die Hälfte der Orte kennt, an denen eine Regel
     * > gelten muss, ist an der anderen Hälfte keiner.**
     */
    public function test_no_page_measures_in_vh(): void
    {
        $quellen = $this->files('resources/js', 'vue');
        $quellen[] = dirname(__DIR__, 2).'/resources/css/app.css';

        $this->assertGreaterThan(
            10,
            count($quellen),
            'Es werden kaum Dateien gelesen — dann prüft dieser Test nichts.',
        );

        foreach ($quellen as $path) {
            $source = (string) file_get_contents($path);

            $this->assertSame(
                0,
                preg_match('/:\s*[0-9.]+vh\b/', $source),
                $this->relative($path).' misst in vh. Auf einem Telefon ist dvh gemeint (docs/24 §6).',
            );
        }
    }

    /**
     * Kein Satz der Oberfläche verspricht eine Seite.
     *
     * **Der Anlass ist ein Bild aus dem Durchgang zu P5c Schritt 5b.** Die
     * Konsole schrieb „Wählen Sie **links** eine Tabelle …". Der Baum steht aber
     * nur ab 720px daneben; darunter steht er *oben* — und genau auf dem Telefon
     * schickte der Satz in die falsche Richtung.
     *
     * **Kein Wächter konnte das sehen**, denn der Satz war grammatisch, deutsch
     * und freundlich. Was ihn falsch machte, war nichts an ihm selbst, sondern
     * der Grundriss daneben.
     *
     * > **Ein Text, der eine Anordnung behauptet, ist nur so lange richtig wie
     * > die Anordnung — und die hängt hier an der Breite des Fensters.**
     *
     * Geprüft wird nur der **sichtbare** Text: Kommentare erklären diese Regel
     * an drei Stellen und nennen dabei ihre Wörter. Ein Wächter, der sie mitläse,
     * bestrafte das Dokumentieren genau des Fehlers, vor dem er schützt.
     *
     * „Oben" und „unten" stehen bewusst nicht in der Liste. Sie bleiben auch
     * beim Umbruch richtig: Was untereinander steht, steht in jeder Breite
     * untereinander — es wandert nur, wie weit.
     *
     * **Und die Gross-/Kleinschreibung ist hier keine Kosmetik.** Der erste
     * Anlauf suchte ohne Rücksicht darauf und meldete `Settings/Mail.vue`:
     * „Einmal-**Links** und Warnungen entstehen, erreichen aber niemanden."
     * Das sind Verweise und keine Richtung. Die deutsche Rechtschreibung trennt
     * die beiden zuverlässig — die Richtung ist ein Adverb und klein, das
     * Substantiv gross —, und nur am Satzanfang fallen sie zusammen. Genau
     * dieser Fall steht als zweite Möglichkeit im Ausdruck.
     *
     * > **Ein Wächter, der ein Wort sucht statt einer Bedeutung, findet die
     * > Wörter, die zufällig gleich aussehen.**
     */
    public function test_no_text_promises_a_side(): void
    {
        $sources = $this->files('resources/js', 'vue');

        $this->assertGreaterThan(
            10,
            count($sources),
            'Es werden kaum Dateien gelesen — dann prüft dieser Test nichts.',
        );

        $seen = 0;

        foreach ($sources as $path) {
            $template = $this->template((string) file_get_contents($path));

            if ($template === '') {
                continue;
            }

            $seen++;

            $this->assertSame(
                0,
                preg_match(
                    '/(?<![\w-])(?:links|rechts)(?![\w-])|(?:^|[.!?]\s|>\s*)(?:Links|Rechts)(?![\w-])/u',
                    $template,
                    $treffer,
                ),
                sprintf(
                    '%s verspricht im sichtbaren Text eine Seite („%s"). Der Grundriss dieses Panels '.
                    'hängt an der Breite: Was bei 1440px daneben steht, steht bei 390px darüber — und '.
                    'der Satz schickt dann in die falsche Richtung (docs/46 §20.25).',
                    $this->relative($path),
                    $treffer[0] ?? '',
                ),
            );
        }

        $this->assertGreaterThan(10, $seen, 'Es werden kaum Vorlagen gefunden — dann prüft dieser Test nichts.');
    }

    public function test_every_table_carries_one_of_the_patterns(): void
    {
        /*
         * Eine Tabelle ohne Muster ist auf 390px entweder abgeschnitten oder
         * sie schiebt die ganze Seite seitwärts.
         *
         * **Seit dem Rework sind es drei und nicht mehr zwei.** `.paare` ist
         * die Tabelle aus Bezeichnung und Wert — Kontingente, Freigaben,
         * Dienste. Sie passt auf 390px und muss weder rollen noch zu Kärtchen
         * zerfallen; ihr fehlte nur eine Regel, was mit dem Wert geschieht,
         * wenn eine Zustandsmarke in der dritten Spalte ihn zusammendrückt.
         * Ohne die Regel stand „3 von 10" auf drei Zeilen — gesehen in der
         * Aufnahme, nicht im Entwurf.
         *
         * Das dritte Muster ist keine Aufweichung der Regel, sondern eine
         * Lücke, die vorher jede Seite selbst gefüllt hat. docs/24 §5 nennt
         * alle drei und sagt, wann welches gilt.
         */
        $tables = 0;

        foreach ($this->files('resources/js', 'vue') as $path) {
            $template = $this->template((string) file_get_contents($path));

            if (! str_contains($template, '<table')) {
                continue;
            }

            preg_match_all('/<table([^>]*)>/', $template, $matches, PREG_OFFSET_CAPTURE);

            // Die Position kommt aus dem *ganzen* Treffer und nicht aus der
            // Klammer: Der Versatz der Attributklammer zeigt hinter `<table`,
            // und alles davor endet damit auf genau dieser Zeichenfolge statt
            // auf dem Behälter, nach dem hier gefragt wird.
            foreach ($matches[0] as $index => $match) {
                $attributes = $matches[1][$index][0];
                $offset = $match[1];
                $tables++;

                $stacks = str_contains($attributes, 'stacks');
                $pairs = str_contains($attributes, 'pairs');

                /*
                 * Rollt sie? Dann steht der Behälter unmittelbar davor.
                 *
                 * **Zweiter Anlauf an dieser Stelle, und beide Male aus
                 * demselben Grund:** Der Ausdruck sah sich den ganzen Text vor
                 * der Tabelle an, statt die eine Zeile davor. Erst stand hier
                 * `explode(...)[$index]` — das liefert ab der zweiten Tabelle
                 * nur das Stück *zwischen* zwei Tabellen. Dann wurde gezählt,
                 * offene `rollt` gegen geschlossene Tabellen, und das hielt
                 * genau so lange, wie jede Tabelle einer Seite gerollt hat:
                 * Eine gestackse Tabelle davor verschiebt die Bilanz, und die
                 * gerollten dahinter fielen durch — obwohl an ihnen nichts
                 * geändert wurde.
                 *
                 * Gefragt ist ohnehin etwas Einfacheres: Steht der Behälter
                 * direkt um diese Tabelle? Alles andere war eine Bilanz über
                 * eine Seite, die niemand behauptet hat.
                 */
                $before = rtrim(substr($template, 0, $offset));
                $scrolls = str_ends_with($before, '<div class="scrolls">');

                $this->assertTrue(
                    $stacks || $scrolls || $pairs,
                    sprintf(
                        'In %s steht eine Tabelle ohne Muster aus docs/24 §5. Messwerte gehören in '.
                        '<div class="scrolls">, Verzeichnisse bekommen class="stacks", und '.
                        'Bezeichnung-und-Wert bekommt class="pairs". Was gar keine Tabelle ist — '.
                        'ein Katalog von Aufgaben etwa —, wird auch keine.',
                        $this->relative($path),
                    ),
                );
            }
        }

        $this->assertGreaterThan(4, $tables, 'Es werden kaum Tabellen gefunden — dann prüft dieser Test nichts.');
    }

    /**
     * Eine Paar-Tabelle beschriftet ihre Zeilen mit `td.quiet` und nicht mit `th`.
     *
     * **Der Anlass ist eine Aufnahme vom 8. August 2026.** Die Datenbankseite
     * schrieb als einzige `<th>` für die Beschriftung, und auf 390px sah man es:
     * Die schmale Fläche macht aus jeder Zeile eine Flexzeile und setzt dafür
     * `table.pairs td` zurück — Anzeige, Polster, Höhe, Rand. Ein `th` fällt
     * unter keine dieser Regeln, behielt also seinen Rand aus der
     * Tabellengestaltung, und der ist so breit wie die Beschriftung. Unter jeder
     * Zeile standen zwei Striche verschiedener Länge, versetzt gegeneinander.
     *
     * **Die Regel ist nicht „`th` ist falsch".** Für eine Zeilenbeschriftung
     * wäre `th` das genauere Markup. Aber zehn Paar-Tabellen schreiben
     * `td.quiet` und eine schrieb `th` — und zwei Formen für dieselbe Sache
     * heissen: Eine wird gepflegt und die andere nicht. Wer das umdreht, ändert
     * die CSS-Regeln mit und stellt hier auf `th` um.
     *
     * Der Gegenbeweis dazu ist die Aufnahme selbst: dieselbe Zeile einmal mit
     * `th` und einmal mit `td.quiet`, gerendert mit dem gebauten Stylesheet.
     */
    public function test_a_pairs_table_labels_its_rows_the_same_way_everywhere(): void
    {
        $offenders = [];
        $tables = 0;

        foreach ($this->files('resources/js', 'vue') as $path) {
            $template = $this->template((string) file_get_contents($path));

            foreach ($this->pairsTables($template) as $body) {
                $tables++;

                if (str_contains($body, '<th')) {
                    $offenders[] = $this->relative($path);
                }
            }
        }

        $this->assertGreaterThan(
            4,
            $tables,
            'Es werden kaum Paar-Tabellen gefunden — dann prüft dieser Test nichts.',
        );

        $this->assertSame(
            [],
            $offenders,
            'Eine Paar-Tabelle beschriftet ihre Zeilen mit <td class="quiet">. Ein <th> behält auf '.
            '390px seinen Rand aus der Tabellengestaltung, weil die schmale Fläche nur `td` '.
            'zurücksetzt — und der Rand ist dann so breit wie die Beschriftung.',
        );
    }

    /**
     * Die Rümpfe aller `table.pairs` einer Seite.
     *
     * @return list<string>
     */
    private function pairsTables(string $template): array
    {
        preg_match_all('#<table class="pairs">(.*?)</table>#s', $template, $matches);

        return $matches[1];
    }

    public function test_every_cell_of_a_stacked_table_is_labelled(): void
    {
        // Im Stapel verschwindet der Spaltenkopf. Eine Zelle ohne
        // `data-column` steht danach ohne Beschriftung da — es sei denn, sie
        // enthält ein Bedienelement, das für sich selbst spricht.
        $cells = 0;

        foreach ($this->files('resources/js', 'vue') as $path) {
            $template = $this->template((string) file_get_contents($path));

            foreach ($this->stackedTables($template) as $table) {
                preg_match_all('#<td([^>]*)>(.*?)</td>#su', $table, $matches, PREG_SET_ORDER);

                foreach ($matches as $cell) {
                    $cells++;

                    $labelled = str_contains($cell[1], 'data-column');
                    $spans = str_contains($cell[1], 'colspan');
                    $acts = (bool) preg_match('/<(button|a|Link)\b/i', $cell[2]);

                    $this->assertTrue(
                        $labelled || $spans || $acts,
                        sprintf(
                            'In %s hat eine Zelle einer gestacksen Tabelle kein data-column und keine Aktion '.
                            'darin. Auf dem Telefon steht ihr Wert ohne Beschriftung (docs/24 §5).',
                            $this->relative($path),
                        ),
                    );
                }
            }
        }

        $this->assertGreaterThan(10, $cells, 'Es werden kaum Zellen gefunden — dann prüft dieser Test nichts.');
    }

    /**
     * Was unter 720px zu Kärtchen zerfällt, hat dort keine eigene Breite mehr.
     *
     * **Dieser Wächter liest die Regel nicht, er rechnet die Kaskade nach** —
     * und das ist der ganze Punkt. Die Regel stand nämlich schon da und galt
     * trotzdem nicht: `.stacks { width: 100% }` wiegt 0,1,0,
     * `.scrolls > table { width: max-content }` wiegt 0,1,1 und gewinnt. Eine
     * gestapelte Tabelle in einem Rollbehälter war damit so breit wie ihr
     * breitestes Kärtchen. Beide Zeilen einzeln gelesen sehen richtig aus;
     * falsch ist erst ihr Zusammentreffen, und genau das sieht kein Blick auf
     * eine Datei.
     *
     * **Gemessen, bevor es diesen Test gab:** 553px Tabelle in 358px Behälter
     * bei 390px Fenster — 195px waagerecht, im vorinstallierten Chromium. Es
     * hing an der Länge einer Kennung und blieb deshalb jahrelang unsichtbar:
     * Kürzere Kärtchen passten zufällig. Der Ablagename einer Sicherung
     * (52 Zeichen) war der erste, der nicht mehr passte.
     *
     * **Warum nicht `test_every_table_carries_one_of_the_patterns` erweitert
     * wurde.** Der prüft `stacks || scrolls || pairs` — *eines von dreien*.
     * Nach docs/24 §5 klingt das nach Alternativen, und man käme auf die Idee,
     * hier auf „genau eines" zu verschärfen. Das wäre falsch: `.stacks` wirkt
     * erst unter 720px, darüber will dieselbe Tabelle rollen dürfen. Die
     * beiden Muster sind zwei Antworten auf zwei Breiten und schliessen sich
     * nicht aus. Was sich ausschliesst, ist `max-content` und ein Kärtchen —
     * und das ist eine Frage an die Kaskade, nicht an das Markup.
     */
    public function test_a_stacked_table_has_no_width_of_its_own(): void
    {
        [$selector, $value, $seen] = $this->winner('width', $this->selectsStackedTable(...));

        $this->assertGreaterThanOrEqual(
            2,
            $seen,
            'Es wird kaum eine Breitenregel gefunden, die eine gestapelte Tabelle erreicht — '.
            'dann rechnet dieser Test an nichts mehr nach.',
        );

        $this->assertSame(
            '100%',
            $value,
            sprintf(
                'Unter 720px gewinnt „%s" mit `width: %s` an einer gestapelten Tabelle. Eine Tabelle, '.
                'die zu Kärtchen zerfällt, muss die Breite ihres Behälters annehmen — sonst stehen die '.
                'Kärtchen seitlich aus dem Bildschirm, und der Rollbehälter macht daraus keinen Fehler, '.
                'sondern eine Rollbewegung (docs/24 §5).',
                $selector,
                $value,
            ),
        );
    }

    /**
     * Und die Kennung im Kärtchen bricht.
     *
     * **Die Breite allein reichte nicht.** Von 195px waagerecht blieben nach
     * `width: 100%` noch 180px stehen — die Kennung trägt `nowrap`, und ein
     * Kärtchen hat keinen Rand, an dem etwas hängenbliebe. Genau dieser
     * Zweischritt steht in docs/24 §5 schon für die Paartabelle: „Zwei
     * Messungen, ein Fund — der erste Fix sah aus wie einer und war keiner."
     *
     * Deshalb steht die zweite Hälfte hier und nicht als zweite Behauptung im
     * Test darüber: Wer nur die Breite zurücknimmt, soll einen Wächter sehen,
     * der die *zweite* Frage stellt, und nicht einen, der zweimal dieselbe
     * meldet.
     */
    public function test_an_identifier_in_a_stacked_card_may_break(): void
    {
        [$selector, $value, $seen] = $this->winner('white-space', $this->selectsStackedIdentifier(...));

        $this->assertGreaterThanOrEqual(
            2,
            $seen,
            'Es wird kaum eine Umbruchregel gefunden, die eine Kennung im Kärtchen erreicht — '.
            'dann rechnet dieser Test an nichts mehr nach.',
        );

        $this->assertNotSame(
            'nowrap',
            $value,
            sprintf(
                'Unter 720px gewinnt „%s" mit `white-space: nowrap` an einer Kennung in einem Kärtchen. '.
                'Auf dem Schreibtisch ist das richtig — dort kann man die Tabelle schieben. In einem '.
                'Kärtchen gibt es nichts zu schieben: Die Kennung läuft aus dem Bildschirm (docs/24 §5).',
                (string) $selector,
            ),
        );
    }

    /**
     * Eine Bereichsüberschrift bricht, bevor sie die Seite schiebt.
     *
     * **Die dritte Fassung derselben Ausnahme.** Sie steht an `.ident` und an
     * `.stacks td .ident`; an der Überschrift fehlte sie. Gefunden hat es der
     * Bildschirmfoto-Durchgang zu P5c Schritt 4 auf `cloudsrv24`: Der Titel
     * „Struktur — bestellpositionen_archiv_2026_langer_name_zum_messen" schob
     * die Seite bei 390px um **99px** aus dem Bild. Ohne offene
     * Strukturansicht stand dieselbe Seite auf `0px`.
     *
     * **Bild und Zahl waren beide nötig.** Auf dem Bildschirmfoto sah der
     * abgeschnittene Name nach einem Zuschnitt aus; erst
     * `scrollWidth - clientWidth` sagte, dass die Seite schiebt.
     *
     * > **Ein Bild zeigt, dass etwas fehlt. Die Zahl sagt, ob die Seite
     * > schiebt.**
     *
     * **Beide Hälften in einem Test**, anders als bei der gestapelten Tabelle
     * darüber: Dort sind es zwei Elemente mit je einer Frage, hier ist es ein
     * Element mit einem Paar. `overflow-wrap` allein nützt nichts, solange das
     * Flexkind seine Inhaltsbreite behalten darf — wer nur eine Hälfte
     * zurücknimmt, hat den Fehler wieder.
     */
    public function test_a_section_heading_can_break(): void
    {
        [$selector, $wrap, $seen] = $this->winner('overflow-wrap', $this->selectsSectionHeading(...));

        $this->assertGreaterThanOrEqual(
            1,
            $seen,
            'Es wird keine Umbruchregel gefunden, die eine Bereichsüberschrift erreicht — dann rechnet '.
            'dieser Test an nichts mehr nach.',
        );

        $this->assertSame(
            'anywhere',
            $wrap,
            sprintf(
                'An der Bereichsüberschrift gewinnt „%s" mit `overflow-wrap: %s`. Eine Überschrift trägt '.
                'hier Kundendaten — einen Tabellennamen, einen Abonnementnamen —, und eine Kennung hat '.
                'keine Leerzeichen, an denen sie von selbst bräche.',
                (string) $selector,
                (string) $wrap,
            ),
        );

        [$engster, $breite, $gesehen] = $this->winner('min-width', $this->selectsSectionHeading(...));

        $this->assertGreaterThanOrEqual(
            1,
            $gesehen,
            'Es wird keine Breitenregel gefunden, die eine Bereichsüberschrift erreicht — dann prüft die '.
            'zweite Hälfte dieses Tests nichts.',
        );

        $this->assertSame(
            '0',
            $breite,
            sprintf(
                'An der Bereichsüberschrift gewinnt „%s" mit `min-width: %s`. `.section-head` ist ein '.
                'Flexbehälter, und ein Flexkind darf ohne `min-width: 0` nicht unter seine Inhaltsbreite '.
                '— die Erlaubnis zu brechen bleibt dann wirkungslos.',
                (string) $engster,
                (string) $breite,
            ),
        );
    }

    /**
     * Eine Zelle der Zeilenansicht trägt einen **Wert** und keine Kennung.
     *
     * **Der Fund, und warum keine Zahl ihn gemeldet hat.** Der Überlauf am
     * Dokument war 0, der Rollbehälter rollte wie vorgesehen — und die Ansicht
     * war trotzdem kaputt: Eine bei 512 Zeichen gekürzte Textzelle machte den
     * Inhalt der Tabelle 5710px breit statt 1907. Bei 390px sind das zehn
     * Bildschirme Rollen durch eine einzige Zelle. Die Messung sagt davon
     * nichts; sie sagt nur, *dass* gerollt wird, und das war ja gewollt.
     *
     * > **Eine Zelle, die rollen darf, hat keine Obergrenze — sie hat nur keine
     * > Zahl, die sich beschwert.**
     *
     * Die Ursache war `td .ident { white-space: nowrap }` — eine Regel mit einer
     * Begründung, die für Kennungen stimmt und für Werte nicht. Es ist derselbe
     * Schnitt wie bei `psql -A -t` in `docs/46 §2`:
     *
     * > **Ein Format, das für Bezeichner reicht, reicht nicht für Werte.**
     *
     * **Drei Fragen, und keine ersetzt die andere.** Die Erlaubnis zu brechen
     * nützt nichts, wenn sie jemand widerruft; und beide zusammen nützen nichts,
     * wenn die Vorlage der Zelle wieder ein `.ident` gibt — dann gewinnt eine
     * Regel, die dieser Selektor gar nicht ansieht.
     */
    public function test_a_value_cell_of_the_rows_view_may_break(): void
    {
        [$selector, $wrap, $seen] = $this->winner('overflow-wrap', $this->selectsRowValueCell(...));

        $this->assertGreaterThanOrEqual(
            1,
            $seen,
            'Es wird keine Umbruchregel gefunden, die eine Wertzelle der Zeilenansicht erreicht — '.
            'dann rechnet dieser Test an nichts mehr nach.',
        );

        $this->assertSame(
            'anywhere',
            $wrap,
            sprintf(
                'An der Wertzelle der Zeilenansicht gewinnt „%s" mit `overflow-wrap: %s`. Ein Wert von '.
                '512 Zeichen ohne Leerzeichen macht seine Spalte sonst breiter als zehn Bildschirme.',
                (string) $selector,
                (string) $wrap,
            ),
        );

        [$engster, $space] = $this->winner('white-space', $this->selectsRowValueCell(...));

        $this->assertNotSame(
            'nowrap',
            $space,
            sprintf(
                'An der Wertzelle der Zeilenansicht gewinnt „%s" mit `white-space: nowrap`. Für eine '.
                'Kennung ist das richtig — für einen Kundenwert nicht (docs/46 §11).',
                (string) $engster,
            ),
        );

        $this->assertRowValueCellsCarryNoIdentifier();
    }

    /**
     * Und die dritte Frage: Die Vorlage gibt der Zelle kein `.ident`.
     *
     * Ohne sie bliebe der Test grün, während der Fehler zurück ist: `.ident`
     * bringt sein `nowrap` aus einer Regel mit, die auf `.ident` endet — und
     * `selectsRowValueCell()` sieht nur Regeln an, die auf der Zelle enden.
     */
    private function assertRowValueCellsCarryNoIdentifier(): void
    {
        $found = 0;

        foreach ($this->files('resources/js', 'vue') as $path) {
            $template = $this->template((string) file_get_contents($path));

            if (preg_match('#<table class="rows">(.*?)</table>#su', $template, $match) !== 1) {
                continue;
            }

            $found++;

            preg_match_all('/<td\b[^>]*>/', $match[1], $cells);

            foreach ($cells[0] as $cell) {
                $this->assertStringNotContainsString(
                    'ident',
                    $cell,
                    sprintf(
                        '%s gibt einer Zelle der Zeilenansicht `%s` mit. `td .ident` verbietet den Umbruch, '.
                        'und eine Wertzelle darf ihn nicht verlieren (docs/46 §11).',
                        $this->relative($path),
                        trim($cell, '<>'),
                    ),
                );
            }
        }

        $this->assertSame(
            1,
            $found,
            'Es wird keine `<table class="rows">` in einer Vorlage gefunden — dann prüft diese Hälfte nichts.',
        );
    }

    /**
     * Die Angabe zwischen „Zurück" und „Weiter" bricht um.
     *
     * **Hier stand `white-space: nowrap`, und es war eine Wette auf den
     * Bestand.** Bis P5c hiess diese Zeile „Seite 2 von 5" — kurz, und in der
     * Länge unabhängig davon, wie gross die Liste ist. Die Zeilenansicht der
     * Konsole schreibt „1.001–1.050 von mehr als 1.050", und diese Zahl wächst
     * mit der Tabelle. Gemessen bei 390px: **8px** Überlauf am Dokument, durch
     * eine Angabe, die keine Bedienung ist.
     *
     * > **Ein `nowrap` über einer Zahl, die wächst, ist keine Zusage über die
     * > Zeile — es ist eine über den Bestand.**
     *
     * Die Untergrenze zählt Regeln und nicht Umbruchregeln: Die richtige Antwort
     * ist hier gerade, dass **keine** Umbruchregel die Angabe erreicht. Ohne den
     * Nachweis, dass der Selektor überhaupt noch etwas trifft, wäre dieser Test
     * nach einer Umbenennung still grün.
     */
    public function test_the_pager_state_may_break(): void
    {
        $this->assertGreaterThanOrEqual(
            1,
            $this->rulesReaching($this->selectsPagerState(...)),
            'Es wird keine Regel gefunden, die die Angabe der Blätterleiste erreicht — dann prüft '.
            'dieser Test nichts mehr.',
        );

        [$selector, $value] = $this->winner('white-space', $this->selectsPagerState(...));

        $this->assertNotSame(
            'nowrap',
            $value,
            sprintf(
                'An der Angabe der Blätterleiste gewinnt „%s" mit `white-space: nowrap`. Sie trägt in der '.
                'Konsole eine Zeilennummer, und die wächst mit dem Bestand (docs/46 §11).',
                (string) $selector,
            ),
        );
    }

    /**
     * Eine Zustandsmarke bleibt eine Marke, auch in einer gestapelten Zelle.
     *
     * **Der dritte Fund derselben Aufnahme, und der leiseste.** `.multiline`
     * stellt Beschriftung und Inhalt untereinander und dehnt seine Kinder dabei
     * (`align-items: stretch`) — für Text ist das genau richtig. Eine Marke
     * wurde damit 328px breit statt 116px: eine farbige Fläche über die ganze
     * Zeile, die aussieht wie eine Meldung und eine Marke sein sollte. Nichts
     * lief über, nichts wurde abgeschnitten; es sah nur falsch aus. Gemessen
     * bei 390px, zu sehen auf der Planseite, seit es `.multiline` gibt.
     *
     * Geprüft wird der Zusammenhang und nicht die Zeile: Erst wenn die Zelle
     * ihre Kinder dehnt, braucht die Marke ihre Gegenwehr. Wer `.multiline`
     * eines Tages auf `align-items: start` umstellt, bekommt hier kein Rot für
     * eine Regel, die dann überflüssig ist.
     */
    public function test_a_badge_in_a_stacked_cell_keeps_its_width(): void
    {
        [, $stretches, $cells] = $this->winner('align-items', $this->selectsMultilineCell(...));

        $this->assertGreaterThanOrEqual(
            1,
            $cells,
            'Es wird keine Regel zu `.multiline` gefunden — dann rechnet dieser Test an nichts nach.',
        );

        if ($stretches !== 'stretch') {
            return;
        }

        [$selector, $value] = $this->winner('align-self', $this->selectsBadgeInMultilineCell(...));

        $this->assertNotNull(
            $value,
            'Unter 720px dehnt `.stacks td.multiline` seine Kinder, und für eine Marke darin sagt das '.
            'niemand ab. Sie wird dann so breit wie die Zelle — eine Fläche über die ganze Zeile, wo '.
            'ein Wort mit einem Punkt stehen sollte (docs/24 §5).',
        );

        $this->assertNotSame(
            'stretch',
            $value,
            sprintf('„%s" setzt `align-self: stretch` — das ist die Dehnung, gegen die es steht.', (string) $selector),
        );
    }

    /**
     * Die stärkste Regel für eine Eigenschaft auf schmaler Fläche.
     *
     * Gewicht vor Reihenfolge — dieselbe Rechnung, die der Browser anstellt.
     * `$reaches` entscheidet, ob ein einzelner Selektor das gesuchte Element
     * trifft; was er nicht versteht, lässt er scheitern statt es zu übergehen.
     *
     * @param  callable(string): bool  $reaches
     * @return array{?string, ?string, int} Selektor, Wert, Zahl der Fundstellen
     */
    private function winner(string $property, callable $reaches): array
    {
        $selector = null;
        $value = null;
        $best = [-1, -1, -1, -1];
        $order = 0;
        $seen = 0;

        foreach ($this->narrowRules() as [$rule, $declarations]) {
            $order++;

            if (preg_match('/(?:^|[;{\s])'.preg_quote($property, '/').':\s*([^;]+)/', $declarations, $match) !== 1) {
                continue;
            }

            foreach (explode(',', $rule) as $single) {
                $single = trim($single);

                if (! $reaches($single)) {
                    continue;
                }

                $seen++;
                $weight = [...$this->specificity($single), $order];

                if ($weight > $best) {
                    $best = $weight;
                    $selector = $single;
                    $value = trim($match[1]);
                }
            }
        }

        return [$selector, $value, $seen];
    }

    /**
     * Wie viele Regeln auf schmaler Fläche dieses Element überhaupt erreichen.
     *
     * **Die Untergrenze für eine Regel, die aus einer Abwesenheit besteht.**
     * `winner()` zählt nur Regeln, die die gesuchte Eigenschaft *setzen* — für
     * „hier steht kein `nowrap`" ist die richtige Antwort null, und dann zählt
     * `$seen` nichts mehr. Diese Zahl hält fest, dass der Selektor noch trifft.
     *
     * @param  callable(string): bool  $reaches
     */
    private function rulesReaching(callable $reaches): int
    {
        $count = 0;

        foreach ($this->narrowRules() as [$rule]) {
            foreach (explode(',', $rule) as $single) {
                if ($reaches(trim($single))) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Jede Regel, die auf einer schmalen Fläche gilt — in Quelltextreihenfolge.
     *
     * Das sind die Regeln ausserhalb jeder Mediaabfrage und die aus den
     * `max-width`-Blöcken. `min-width` und `prefers-*` bleiben draussen: Sie
     * gelten dort gerade nicht.
     *
     * @return list<array{string, string}>
     */
    private function narrowRules(): array
    {
        $css = $this->withoutComments((string) file_get_contents(
            dirname(__DIR__, 2).'/resources/css/app.css',
        ));

        $rules = [];

        /*
         * Erst die Blöcke herauslösen, dann den Rest — sonst läse der Ausdruck
         * für gewöhnliche Regeln `@media (max-width: 720px) {` als Selektor
         * und die erste Regel darin als seine Deklarationen.
         */
        preg_match_all('/@media([^{]*)\{((?:[^{}]|\{[^{}]*\})*)\}/s', $css, $blocks, PREG_SET_ORDER);

        $outside = $css;

        foreach ($blocks as $block) {
            $outside = str_replace($block[0], '', $outside);
        }

        foreach ($this->declarations($outside) as $rule) {
            $rules[] = $rule;
        }

        foreach ($blocks as $block) {
            if (! str_contains($block[1], 'max-width')) {
                continue;
            }

            foreach ($this->declarations($block[2]) as $rule) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * Selektor und Deklarationen eines Stücks CSS.
     *
     * @return list<array{string, string}>
     */
    private function declarations(string $css): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $matches, PREG_SET_ORDER);

        $rules = [];

        foreach ($matches as $match) {
            $rules[] = [trim($match[1]), $match[2]];
        }

        return $rules;
    }

    /**
     * Trifft dieser Selektor die `<table class="stacks">` in `.scrolls`?
     *
     * **Der Wächter versteht absichtlich nur wenig CSS**, und was er nicht
     * versteht, lässt er durchfallen statt es zu übergehen: Ein Selektor, der
     * rechts auf einer Tabelle endet, aber links etwas Unbekanntes stehen hat,
     * bringt den Test zum Scheitern. Ein Wächter, der bei einer unbekannten
     * Form still weitergeht, meldet Grün für genau den Fall, den er nicht
     * geprüft hat.
     */
    private function selectsStackedTable(string $selector): bool
    {
        // Rechts muss die Tabelle selbst stehen — nicht ihre Zellen, nicht ihr
        // Behälter. Eine Breite an `.stacks td` ist eine andere Frage.
        return $this->reaches(
            $selector,
            ['table', '.stacks', 'table.stacks'],
            ['div', '.scrolls', '.panel', 'body'],
            ['.pairs', 'table.pairs'],
        );
    }

    /** Trifft er die Überschrift eines Bereichs? */
    private function selectsSectionHeading(string $selector): bool
    {
        return $this->reaches(
            $selector,
            // `reaches()` zerlegt den Selektor an Leerzeichen und `>` und
            // vergleicht das *letzte* Stück — hier also immer nur `h2`.
            ['h2'],
            ['div', '.section', '.sections', '.section-head', '.full', '.wide'],
            ['.page-head', '.tile', '.notice', '.empty'],
        );
    }

    /** Trifft er die Zelle, in der ein Wert der Zeilenansicht steht? */
    private function selectsRowValueCell(string $selector): bool
    {
        return $this->reaches(
            $selector,
            ['.cell', 'div.cell'],
            ['div', 'table', 'tbody', 'tr', 'td', '.scrolls', '.rows', 'table.rows'],
            ['.stacks', 'table.stacks', '.pairs', 'table.pairs', '.sheet', '.tile'],
        );
    }

    /** Und die Angabe zwischen „Zurück" und „Weiter"? */
    private function selectsPagerState(string $selector): bool
    {
        return $this->reaches(
            $selector,
            ['.pager-state', 'p.pager-state'],
            ['div', 'nav', '.pager', '.section'],
            ['.sheet', '.tile'],
        );
    }

    /** Trifft er eine Kennung in einer Zelle dieser Tabelle? */
    private function selectsStackedIdentifier(string $selector): bool
    {
        return $this->reaches(
            $selector,
            ['.ident', 'td.ident', 'th.ident', 'span.ident'],
            ['div', 'table', 'tbody', 'tr', 'td', 'th', '.scrolls', '.stacks', '.multiline', 'td.multiline'],
            ['.pairs', 'table.pairs', 'td.right', 'th.right', '.tile', '.notice', '.field', '.hint'],
        );
    }

    /** Trifft er die gestapelte Zelle, in der mehr als ein Wert steht? */
    private function selectsMultilineCell(string $selector): bool
    {
        return $this->reaches(
            $selector,
            ['.multiline', 'td.multiline'],
            ['div', 'table', 'tbody', 'tr', '.scrolls', '.stacks'],
            ['.pairs', 'table.pairs'],
        );
    }

    /** Und die Marke darin? */
    private function selectsBadgeInMultilineCell(string $selector): bool
    {
        $parts = preg_split('/\s*>\s*|\s+/', trim($selector)) ?: [];

        // Ohne die Zelle im Selektor ist es eine Regel für jede Marke — und
        // eine allgemeine `align-self` an `.badge` wäre eine Antwort auf eine
        // Frage, die nur diese Zelle stellt.
        if (! array_intersect($parts, ['.multiline', 'td.multiline'])) {
            return false;
        }

        return $this->reaches(
            $selector,
            ['.badge', 'span.badge'],
            ['div', 'table', 'tbody', 'tr', 'td', '.scrolls', '.stacks', '.multiline', 'td.multiline'],
            ['.pairs', 'table.pairs'],
        );
    }

    /**
     * Der gemeinsame Nenner der beiden darüber.
     *
     * **Drei Ausgänge und nicht zwei, und das war der Fehler des ersten
     * Anlaufs.** Dort gab es nur „trifft" und „unbekannt, also Abbruch"; alles
     * Bekannte galt als Treffer. `table.pairs td.ident` stand damit in der
     * Kaskade für ein Kärtchen — eine Regel für eine ganz andere Tabelle, mit
     * dem Gewicht 0,2,2. Sie gewann, sagte `white-space: normal`, und der
     * Wächter war zufrieden: **Der Bruch, der die Regel aus app.css nahm, blieb
     * grün.** Aufgefallen ist es nur, weil der Bruch dazugehört.
     *
     * Deshalb `$foreign`: Formen, die dieser Test kennt und die das gesuchte
     * Element gerade *ausschliessen*. Was weder das eine noch das andere ist,
     * lässt den Test scheitern — ein Wächter, der bei einer unbekannten Form
     * still weitergeht, meldet Grün für genau den Fall, den er nicht geprüft
     * hat.
     *
     * @param  list<string>  $leaf  erlaubte Formen ganz rechts
     * @param  list<string>  $ancestors  Formen davor, die passen
     * @param  list<string>  $foreign  Formen davor, die etwas anderes meinen
     */
    private function reaches(string $selector, array $leaf, array $ancestors, array $foreign): bool
    {
        $parts = preg_split('/\s*>\s*|\s+/', trim($selector)) ?: [];
        $last = (string) array_pop($parts);

        if (! in_array($last, $leaf, true)) {
            return false;
        }

        foreach ($parts as $part) {
            if (in_array($part, $foreign, true)) {
                return false;
            }

            $this->assertContains(
                $part,
                $ancestors,
                sprintf(
                    'Der Selektor „%s" trifft „%s", und dieser Test versteht „%s" davor nicht. '.
                    'Entweder gehört die Form in reaches() aufgenommen — als passende oder als '.
                    'fremde — oder die Regel gehört nicht dorthin.',
                    $selector,
                    $last,
                    $part,
                ),
            );
        }

        return true;
    }

    /**
     * Das Gewicht eines Selektors — Kennungen, Klassen, Elemente.
     *
     * @return list<int>
     */
    private function specificity(string $selector): array
    {
        preg_match_all('/#[\w-]+/', $selector, $ids);
        preg_match_all('/\.[\w-]+|\[[^\]]+\]|:[\w-]+\([^)]*\)|:(?!:)[\w-]+/', $selector, $classes);
        preg_match_all('/(?:^|[\s>+~])([a-z][\w-]*)/i', $selector, $elements);

        return [count($ids[0]), count($classes[0]), count($elements[1])];
    }

    /**
     * Das Gerüst der schmalen Fläche ist eine Spalte und kein Raster.
     *
     * **Der Fehler, den das festhält, hing von einem Kind ab.** Das Gerüst war
     * unter 720px weiterhin ein Raster mit `grid-template-rows: auto 1fr` —
     * Kopfzeile oben, Inhalt darunter. Das ging, solange es zwei Kinder im
     * Fluss gab. Beim Wechsel in die Sicht eines Kunden kommt das Band dazu,
     * und damit rutscht die **Kopfzeile** in die `1fr`-Zeile und nimmt sich
     * allen übrigen Platz: Auf einem Telefon mit 844px Höhe war sie 591px
     * hoch, und zwischen Band und Seitentitel stand eine leere schwarze
     * Fläche. Der Inhalt landete in einer Zeile, die es im Raster gar nicht
     * gab.
     *
     * Am Schreibtisch sieht man das nie — dort gilt die Regel nicht, und ohne
     * „Anmelden als" gibt es das dritte Kind nicht.
     *
     * Eine dritte Zeile wäre die falsche Antwort gewesen: Dann zählt man
     * Kinder, und beim nächsten Band zählt jemand falsch. Auf einer Spalte
     * gibt es nichts zu zählen.
     */
    public function test_the_narrow_frame_is_a_column_and_not_a_grid(): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Layouts/PanelLayout.vue');
        $layout = (string) preg_replace('#/\*.*?\*/#su', '', $layout);

        if (preg_match('/@media\s*\(\s*max-width:\s*720px\s*\)\s*\{(.*)\n\}/su', $layout, $match) !== 1) {
            $this->fail('In PanelLayout.vue steht kein @media (max-width: 720px) mehr.');
        }

        if (preg_match('/(^|\})\s*\.frame\s*\{([^}]*)\}/s', $match[1], $frame) !== 1) {
            $this->fail('Unter 720px gibt es keine Regel für .frame mehr.');
        }

        $this->assertMatchesRegularExpression(
            '/display\s*:\s*flex/',
            $frame[2],
            'Das Gerüst der schmalen Fläche muss eine Spalte sein (display: flex). Ein Raster mit '.
            'festen Zeilen hängt davon ab, wie viele Kinder gerade da sind — und das Band von '.
            '„Anmelden als" ist eines davon.',
        );

        $this->assertSame(
            0,
            preg_match('/grid-template-rows/', $frame[2]),
            'Unter 720px setzt .frame wieder Rasterzeilen. Damit hängt die Höhe der Kopfzeile daran, '.
            'ob gerade jemand in der Sicht eines Kunden arbeitet.',
        );
    }

    /**
     * Was untereinander liegt, trennt keine senkrechte Linie.
     *
     * **Der Befund kam vom Telefon des Betreibers.** Unter 720px legt
     * `--kachel-min: 100%` die Kacheln untereinander — der Trenner aus
     * `.tile + .tile` blieb aber der **linke** Rand. Auf 390px stand damit ein
     * senkrechter Strich neben allen Kacheln ausser der ersten, und ihr Inhalt
     * war um 24px eingerückt: Die erste begann am Seitenrand, die vier darunter
     * nicht.
     *
     * **Auf meiner eigenen 390px-Aufnahme war es zu sehen.** Eine Aufnahme zu
     * machen genügt nicht, wenn man sie nur auf das ansieht, was man gerade
     * geändert hat. Deshalb hier eine Regel, die der Blick nicht ersetzt: Wer
     * eine Trennlinie hat und stapelt, dreht sie.
     */
    public function test_stacked_tiles_are_separated_from_above(): void
    {
        $css = $this->withoutComments((string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css'));

        $regeln = $this->insideMediaQuery($css, 720);

        $this->assertNotSame('', $regeln, 'Es gibt keine 720px-Abfrage mehr — dann prüft dieser Test nichts.');

        if (preg_match('/(^|\})\s*\.tile\s*\+\s*\.tile\s*\{([^}]*)\}/s', $regeln, $tile) !== 1) {
            $this->fail(
                "Unter 720px gibt es keine Regel für `.tile + .tile` mehr.\n\n".
                'Ohne sie gilt der Trenner der breiten Fläche weiter — ein linker Rand neben Kacheln, '.
                'die untereinander liegen, und 24px Einrückung ab der zweiten.',
            );
        }

        $this->assertMatchesRegularExpression(
            '/border-top\s*:\s*1px/',
            $tile[2],
            'Gestapelte Kacheln brauchen ihre Trennlinie oben.',
        );

        $this->assertMatchesRegularExpression(
            '/border-left\s*:\s*0/',
            $tile[2],
            'Die senkrechte Trennlinie muss unter 720px weg — untereinander trennt sie nichts, '.
            'sondern rückt ein.',
        );

        $this->assertMatchesRegularExpression(
            '/padding-left\s*:\s*0/',
            $tile[2],
            'Mit der linken Linie geht auch der linke Abstand: Sonst beginnt die erste Kachel am '.
            'Seitenrand und jede weitere 24px daneben.',
        );
    }

    public function test_input_fields_use_the_zoom_safe_size(): void
    {
        /*
         * Ein Feld mit --text-body ist ein Feld, das Safari beim Fokus
         * hineinzoomt. Gesucht wird jede Regel, deren Selektor ein Feld nennt
         * und die eine Schriftgröße setzt.
         *
         * **Gesucht wird in app.css mit.** Vorher las dieser Test nur
         * `resources/js` — zu der Zeit brachte jede Seite ihr eigenes Feld mit,
         * und dort standen die Regeln. Genau das hört auf: Das Aussehen eines
         * Feldes gehört in app.css, wie das eines Knopfes
         * (`ButtonStyleTest::test_no_page_styles_a_field_itself`).
         *
         * Ohne diese Erweiterung hätte der Test in dem Augenblick nichts mehr
         * gefunden, in dem die Felder umziehen — und wäre an seiner eigenen
         * Untergrenze durchgefallen statt an der Sache. Ein Wächter, der beim
         * Aufräumen zubeisst, wird beim Aufräumen abgeschaltet.
         */
        $checked = 0;

        foreach ($this->stylesheets() as $name => $css) {
            preg_match_all('/([^{}]*)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER);

            foreach ($rules as $rule) {
                $selector = trim($rule[1]);

                if (! preg_match('/(^|[\s,>])(input|select|textarea)\b/', $selector)) {
                    continue;
                }

                if (! preg_match('/font-size:\s*var\(([^)]+)\)/', $rule[2], $size)) {
                    continue;
                }

                $checked++;
                $token = trim($size[1]);

                $this->assertTrue(
                    $this->zoomSafe($token),
                    sprintf(
                        '%s setzt an „%s" die Größe %s (%dpx). Eingabefelder brauchen --text-input oder eine '.
                        'Marke ab 16px, sonst zoomt Safari beim Fokus in die Seite (docs/24 §3).',
                        $name,
                        $selector,
                        $token,
                        $this->scale()[$token] ?? 0,
                    ),
                );
            }
        }

        $this->assertGreaterThan(0, $checked, 'Es wird keine Feldregel mehr gefunden — dann prüft dieser Test nichts.');
    }

    /**
     * Eine Kennung bricht — ausser in einer Tabelle.
     *
     * **Der Fall, für den diese Regel eingeführt wurde.** Auf der
     * Zertifikatsseite steht in einer Warnung die Liste der Namen, unter denen
     * dieser Rechner sonst noch erreichbar ist. Sie stand in einer `.ident`,
     * `.ident` stand auf `white-space: nowrap`, und damit war die ganze Liste
     * eine einzige unteilbare Zeile: Der Text lief unter dem Rand der Meldung
     * heraus und die Seite über den Bildschirm. Auf dem Zielserver gesehen,
     * bei 390px — im Test war alles grün.
     *
     * **Warum das keine Ausnahme für diese eine Meldung ist.** Genau so wurde
     * es beim ersten Mal behoben: `table.pairs td.right.ident` löst denselben
     * Überlauf für den einen Ort, an dem er auffiel. Der zweite Ort kam
     * trotzdem. `nowrap` gehört der Tabelle — dort kann man schieben —, und
     * die Klasse selbst darf brechen.
     *
     * Geprüft wird in allen Stylesheets, auch in den `<style>`-Blöcken der
     * Seiten: Eine Seite, die sich ihr `nowrap` selbst zurückholt, ist
     * derselbe Fehler an einer Stelle, die niemand liest.
     */
    public function test_an_identifier_may_break_outside_a_table(): void
    {
        $rules = 0;
        $breaks = false;

        foreach ($this->stylesheets() as $name => $css) {
            preg_match_all('/([^{}]*)\{([^{}]*)\}/s', $css, $matches, PREG_SET_ORDER);

            foreach ($matches as $rule) {
                $selector = trim($rule[1]);

                if (! str_contains($selector, '.ident')) {
                    continue;
                }

                $rules++;

                if (preg_match('/white-space:\s*nowrap/', $rule[2]) === 1) {
                    $this->assertMatchesRegularExpression(
                        '/(^|[\s,>.])(td|th)\b/',
                        $selector,
                        sprintf(
                            '%s hält an „%s" eine Kennung vom Umbruch ab. Ausserhalb einer Tabelle gibt es '.
                            'nichts zu schieben — eine lange Kennung schiebt dann die Seite (docs/24 §8).',
                            $name,
                            $selector,
                        ),
                    );
                }

                // Nur die Klasse selbst: Ob eine Tabellenzelle brechen darf,
                // entscheidet die Zelle.
                if ($selector === '.ident') {
                    $breaks = str_contains($rule[2], 'overflow-wrap: anywhere');
                }
            }
        }

        // Die Untergrenze zählt, wo die Regel stehen *darf* — sonst meldet
        // dieser Wächter Rot, sobald jemand die beiden Regeln zusammenlegt.
        $this->assertGreaterThanOrEqual(2, $rules, 'Es wird kaum eine Regel zu .ident gelesen — dann prüft dieser Test nichts.');

        $this->assertTrue(
            $breaks,
            '.ident braucht `overflow-wrap: anywhere`. Ohne diese Zeile bleibt eine Kennung ohne Leerzeichen '.
            'so breit, wie sie ist — und in einer Meldung (einer Flexbox) hält sie das Kind auf seiner '.
            'Inhaltsbreite fest, statt umzubrechen.',
        );
    }

    /**
     * Jedes Stylesheet des Panels: app.css und die `<style>`-Blöcke.
     *
     * @return array<string, string>
     */
    private function stylesheets(): array
    {
        $sheets = [];

        foreach ($this->files('resources/css', 'css') as $path) {
            $sheets[$this->relative($path)] = $this->withoutComments((string) file_get_contents($path));
        }

        foreach ($this->files('resources/js', 'vue') as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('#<style[^>]*>(.*)</style>#su', $source, $match) === 1) {
                $sheets[$this->relative($path)] = $this->withoutComments($match[1]);
            }
        }

        return $sheets;
    }

    /**
     * Kommentare weg, bevor eine Regel gelesen wird.
     *
     * **Das hat gefehlt, und es hat falschen Alarm geschlagen.** Der Ausdruck
     * `([^{}]*)\{([^{}]*)\}` nimmt als Selektor alles, was vor der Klammer
     * steht — also auch den Kommentar darüber. In app.css steht über `.schalter`
     * die Begründung, warum ein `input[type='checkbox']` dort seine eigene
     * Größe bekommt; das Wort „input" darin genügte, damit die Regel als
     * Feldregel galt und an `--text-table` durchfiel. Ein Ankreuzfeld zoomt
     * Safari nie hinein — das ist das Gegenteil einer echten Meldung.
     *
     * Jeder andere Wächter mit demselben Ausdruck macht das längst
     * (`ButtonStyleTest`, `TableStyleTest`, `ClassReachTest`); hier war es
     * vergessen worden. Ein Wächter, der bei einem gut kommentierten
     * Stylesheet Fehlalarm gibt, wird beim dritten Mal abgeschaltet.
     */
    private function withoutComments(string $css): string
    {
        return (string) preg_replace('#/\*.*?\*/#su', '', $css);
    }

    /**
     * Zoomt Safari bei dieser Marke nicht hinein?
     *
     * `--text-input` immer: Sie ist genau dafür da und steht auf der schmalen
     * Fläche auf 16px. Sonst entscheidet der Wert — alles ab 16px ist
     * unbedenklich, und das Codefeld mit `--text-metric` (22px) ist es damit
     * auch. Die Grenze wird nicht behauptet, sondern aus app.css gelesen:
     * Ändert jemand eine Marke, ändert sich diese Prüfung mit.
     */
    private function zoomSafe(string $token): bool
    {
        return $token === '--text-input' || ($this->scale()[$token] ?? 0) >= 16;
    }

    /**
     * Die Schriftskala aus app.css.
     *
     * @return array<string, float>
     */
    private function scale(): array
    {
        static $scale = null;

        if ($scale !== null) {
            return $scale;
        }

        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
        preg_match_all('/(--text-[a-z]+):\s*([0-9.]+)px/', $css, $matches, PREG_SET_ORDER);

        $scale = [];

        foreach ($matches as $match) {
            // Der erste Treffer gewinnt: Die Grundskala steht oben,
            // Abweichungen für die schmale Fläche stehen darunter und sind
            // grösser. Für diese Prüfung zählt der ungünstigere Fall.
            $scale[$match[1]] ??= (float) $match[2];
        }

        return $scale;
    }

    /**
     * Die Inhalte aller `.stacks`-Tabellen eines Templates.
     *
     * @return list<string>
     */
    private function stackedTables(string $template): array
    {
        preg_match_all('#<table[^>]*stacks[^>]*>(.*?)</table>#su', $template, $matches);

        return $matches[1];
    }

    /**
     * Der Inhalt aller `@media (max-width: Npx)`-Blöcke, zusammengesetzt.
     *
     * **Klammern zählen und nicht Zeichen abschneiden.** Der erste Anlauf nahm
     * 4000 Zeichen ab dem `@media` — und lief damit über das Ende der Abfrage
     * hinaus in die Regeln der breiten Fläche. Der Test fand dort `.tile +
     * .tile` mit `border-left` und meldete Rot für eine Regel, die an ihrer
     * Stelle richtig ist.
     */
    private function insideMediaQuery(string $css, int $breakpoint): string
    {
        $inhalt = '';
        $muster = sprintf('/@media\s*\(\s*max-width:\s*%dpx\s*\)\s*\{/', $breakpoint);

        preg_match_all($muster, $css, $treffer, PREG_OFFSET_CAPTURE);

        foreach ($treffer[0] as $start) {
            $offen = 1;
            $i = (int) $start[1] + strlen((string) $start[0]);
            $von = $i;

            while ($offen > 0 && $i < strlen($css)) {
                $offen += match ($css[$i]) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };

                $i++;
            }

            $inhalt .= substr($css, $von, $i - $von - 1);
        }

        return $inhalt;
    }
}
