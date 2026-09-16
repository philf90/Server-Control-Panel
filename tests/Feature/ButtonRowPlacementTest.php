<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Die Knopfreihe steht neben `.sections` und nicht darin.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Zweimal bezahlt, beide Male von einem Betrachter und nie von einer Zahl.
 *
 * Auf der Zertifikatsseite stand die Reihe als Kind eines Bereichs und bekam
 * keinen Abstand nach oben; „Speichern" klebte am Hinweis darüber. Der
 * Kommentar dort hält es seitdem fest — *„so wie in jeder anderen Maske des
 * Panels"* —, und ein Wächter ist daraus nicht geworden.
 *
 * In der Bilderrunde zu P8 Schritt 9 kam dieselbe Frage von der anderen Seite:
 * `.sections` ist eine umbrechende Flexverteilung, und als **direktes Kind**
 * darin lief die Knopfreihe als weiteres Flexkind neben den Bereichen mit.
 * „Speichern" stand bei 1440 px oben rechts neben der Überschrift des zweiten
 * Bereichs statt unter dem Formular; bei 390 px ist kein Platz daneben, und
 * dort sah es richtig aus.
 *
 * **Der Mechanismus steht in einer einzigen Zeile:** `.form > .button-row`
 * trägt `flex-basis: 100%`. Ein Kind von `.form` bekommt damit seine eigene
 * Zeile, ein Kind von `.sections` nicht — und die Regel gilt nach dem
 * **Elternteil** und nicht nach der Klasse. Der Wächter misst sie nach, statt
 * sie zu behaupten.
 *
 * > **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten
 * > Merkmal wieder da, wenn die Behebung nicht die Regel wurde.**
 *
 * ## Was keine Zahl sagen kann
 *
 * `tests/bilder-messen.js` gab in allen vier Lagen `dokument: 0`, nichts
 * schiebt, nichts rollt. Die Seite läuft nicht über — sie stellt den Knopf nur
 * an eine Stelle, an der ihn niemand sucht.
 *
 * > **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
 * > Betrachter.**
 *
 * ## Was er **nicht** hält
 *
 * Eine Knopfreihe **innerhalb** eines `<Section>` ist hier erlaubt: Sie gehört
 * dann zu dessen Inhalt (`Settings/Database.vue` bietet so das Einspielen von
 * PostgreSQL an, `Settings/Profile.vue` hat je Bereich einen eigenen Knopf).
 * Gefragt ist allein das **direkte** Kind von `.sections`.
 *
 * Und ob der Knopf dort steht, wo ein Benutzer ihn sucht, kann er nicht sagen —
 * das hängt an einer Erwartung und nicht am Baum.
 */
final class ButtonRowPlacementTest extends TestCase
{
    /**
     * Was kein Ende hat, gehört nicht auf den Stapel.
     *
     * **Gefragt wird der Name in seiner Schreibweise und nicht kleingeschrieben**
     * — und das ist im ersten Lauf dieses Wächters bezahlt worden. `link` steht
     * hier, weil `<link>` in HTML kein Ende hat; Inertias `<Link>` ist eine
     * Komponente mit Inhalt und Ende. Kleingeschrieben sehen die beiden gleich
     * aus, und der Stapel verschob sich ab der ersten `<Link>` um eins — der
     * Wächter meldete eine Knopfreihe in `.sections`, die in Wahrheit in einem
     * Bereich steht. **Gemessen an der Bilanz**: Von 82 Vorlagen endeten 23 mit
     * einem Stapel ungleich null, mit der Berichtigung keine einzige.
     * `TemplateSpacingTest` trug denselben Fehler, seit es ihn gibt.
     *
     * > **Eine Liste leerer HTML-Elemente trifft eine Komponente, die zufällig
     * > so heisst — und Vue unterscheidet die beiden allein an der
     * > Grossschreibung.**
     */
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    /** Keine Knopfreihe ist ein direktes Kind von `.sections`. */
    public function test_no_button_row_is_a_direct_child_of_the_sections_wrapper(): void
    {
        $befunde = [];
        $gesehen = 0;
        $raster = 0;

        foreach ($this->vueFiles() as $datei) {
            $befunde = [...$befunde, ...$this->findings((string) file_get_contents($datei), $datei, $gesehen, $raster)];
        }

        /*
         * **Zwei Untergrenzen.** Die erste sagt, dass der Leser überhaupt
         * Knopfreihen findet; die zweite, dass er `.sections` findet. Ohne die
         * zweite wäre der Wächter auch dann grün, wenn die Klasse eines Tages
         * anders hiesse — und dann prüfte er nichts.
         */
        $this->assertGreaterThanOrEqual(30, $gesehen, sprintf(
            'Nur %d Knopfreihen gefunden — dann liest dieser Wächter die Vorlagen nicht mehr.',
            $gesehen,
        ));

        $this->assertGreaterThanOrEqual(4, $raster, sprintf(
            'Nur %d `.sections` gefunden — dann prüft dieser Wächter nichts.',
            $raster,
        ));

        $this->assertSame([], $befunde, sprintf(
            "Hier steht eine Knopfreihe zwischen den Bereichen statt unter ihnen:\n\n  %s\n\n".
            '`.sections` ist eine umbrechende Flexverteilung, und nur `.form > .button-row` trägt '.
            '`flex-basis: 100%%`. Als Kind von `.sections` läuft die Reihe als weiteres Flexkind '.
            'neben den Bereichen mit — der Knopf steht dann oben neben einer Bereichsüberschrift '.
            'statt unter dem Formular. Er gehört als Geschwister von `.sections` unter '.
            '`<form class="form">`.',
            implode("\n  ", $befunde),
        ));
    }

    /**
     * Der Leser verliert den Faden nicht — jede Vorlage endet ausgeglichen.
     *
     * **Die Prüfung, die den Fehler dieses Wächters sofort gemeldet hätte.**
     * Ein Stapel, der am Ende einer Datei nicht leer ist, heisst: Der Leser hat
     * ein Element übersprungen oder eines zu viel geschlossen — und ab dieser
     * Stelle steht jeder Elternteil daneben. Der Befund darüber sieht dann aus
     * wie ein Befund über die Vorlage.
     *
     * > **Ein Leser, der den Faden verliert, meldet nicht sich selbst — er
     * > meldet die Datei.**
     *
     * Gemessen an dem Fehler, der sie ausgelöst hat: Ohne die Unterscheidung
     * zwischen `<link>` und `<Link>` endeten 23 von 82 Vorlagen ungleich null.
     */
    public function test_the_reader_keeps_track(): void
    {
        $schief = [];
        $gelesen = 0;

        foreach ($this->vueFiles() as $datei) {
            $gelesen++;
            $tiefe = $this->balance((string) file_get_contents($datei));

            if ($tiefe !== 0) {
                $schief[] = sprintf('%s — Stapel endet auf %d', str_replace($this->root().'/', '', $datei), $tiefe);
            }
        }

        $this->assertGreaterThanOrEqual(50, $gelesen, sprintf(
            'Nur %d Vorlagen gelesen — dann prüft dieser Fall nichts.',
            $gelesen,
        ));

        $this->assertSame([], $schief, sprintf(
            "Hier verliert der Leser den Faden:\n\n  %s\n\n".
            'Ab dieser Stelle steht jeder Elternteil daneben, und jeder Befund über die Datei ist '.
            'einer über den Leser. Meist fehlt ein Element in der Liste der leeren — oder eines '.
            'steht darin, das in Wahrheit eine Komponente ist.',
            implode("\n  ", $schief),
        ));
    }

    /** Wie tief der Stapel am Ende einer Vorlage steht — 0 heisst ausgeglichen. */
    private function balance(string $quelle): int
    {
        $vorlage = $this->template($quelle);

        preg_match_all(
            '#<(/?)([a-zA-Z][\w.-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)(/?)>#s',
            $vorlage,
            $tags,
            PREG_SET_ORDER,
        );

        $tiefe = 0;

        foreach ($tags as $tag) {
            if ($tag[1] === '/') {
                $tiefe--;

                continue;
            }

            $name = $tag[2];
            $leer = $name === strtolower($name) && in_array($name, self::VOID, true);

            if ($tag[4] !== '/' && ! $leer) {
                $tiefe++;
            }
        }

        return $tiefe;
    }

    /**
     * Die Voraussetzung, und sie ist zweiteilig.
     *
     * **Gemessen und nicht behauptet.** Der erste Wurf dieses Wächters trug
     * hier „`.sections` ist ein Raster" — gemessen ist es `display: flex` mit
     * `flex-wrap: wrap`. Die Prüfung war rot, bevor eine Zeile Regel gefahren
     * war.
     *
     * > **Ein Satz, der eine Begründung nennt, die niemand gemessen hat, ist
     * > auch dann falsch, wenn der Handgriff daneben richtig ist.**
     *
     * Ziehen die beiden Zeilen eines Tages um, wird dieser Wächter rot und
     * nicht still.
     */
    public function test_the_premise_of_this_guard_holds(): void
    {
        $css = (string) file_get_contents($this->root().'/resources/css/app.css');

        $this->assertMatchesRegularExpression(
            '/\.sections\s*\{[^{}]*display:\s*flex[^{}]*flex-wrap:\s*wrap/s',
            $css,
            '`.sections` ist keine umbrechende Flexverteilung mehr — dann hat die Regel dieses '.
            'Wächters einen anderen Gegenstand als gedacht.',
        );

        /*
         * **Das Tragende.** Diese eine Zeile ist der Grund, dass die Stelle im
         * Baum überhaupt etwas entscheidet: Sie gilt nach dem Elternteil und
         * nicht nach der Klasse.
         */
        $this->assertMatchesRegularExpression(
            '/\.form\s*>\s*\.button-row\s*\{[^{}]*flex-basis:\s*100%/s',
            $css,
            'Nur als Kind von `.form` bekommt die Knopfreihe ihre eigene Zeile. Fällt diese Regel '.
            'weg, sagt die Stelle im Baum nichts mehr über die Anzeige.',
        );
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> */
    private function vueFiles(): array
    {
        $dateien = [];

        /** @var SplFileInfo $datei */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/resources/js', FilesystemIterator::SKIP_DOTS),
        ) as $datei) {
            if ($datei->isFile() && $datei->getExtension() === 'vue') {
                $dateien[] = $datei->getPathname();
            }
        }

        sort($dateien);

        return $dateien;
    }

    /**
     * Skript, Stil und Kommentare weg — die Zeilennummern bleiben.
     *
     * Der Kommentar muss fort, **bevor** gesucht wird: Der Absatz, der diese
     * Behebung erklärt, schreibt die alte Verschachtelung wörtlich hin.
     *
     * > **Ein Kommentar, der die entfernte Zeile zitiert, stellt sie für einen
     * > Wächter wieder her.**
     */
    private function template(string $quelle): string
    {
        $leer = static fn (array $treffer): string => str_repeat("\n", substr_count($treffer[0], "\n"));

        $quelle = (string) preg_replace_callback('#<(script|style)\b[^>]*>.*?</\1>#su', $leer, $quelle);

        return (string) preg_replace_callback('#<!--.*?-->#su', $leer, $quelle);
    }

    /** @return list<string> */
    private function classesOf(string $attribute): array
    {
        if (preg_match('/(?<![:\w-])class="([^"]*)"/', $attribute, $treffer) !== 1) {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/', trim($treffer[1])) ?: []));
    }

    /**
     * Jede Knopfreihe, die unmittelbar in `.sections` steht.
     *
     * @return list<string>
     */
    private function findings(string $quelle, string $datei, int &$gesehen, int &$raster): array
    {
        $vorlage = $this->template($quelle);
        $befunde = [];

        /** @var list<bool> $stapel `true`, wo das Element `.sections` trägt */
        $stapel = [];

        preg_match_all(
            '#<(/?)([a-zA-Z][\w.-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)(/?)>#s',
            $vorlage,
            $tags,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        foreach ($tags as $tag) {
            $schliessend = $tag[1][0] === '/';
            $name = $tag[2][0];
            $attribute = $tag[3][0];
            $selbstschliessend = $tag[4][0] === '/';
            $stelle = $tag[0][1];

            if ($schliessend) {
                array_pop($stapel);

                continue;
            }

            $klassen = $this->classesOf($attribute);
            $istRaster = in_array('sections', $klassen, true);
            $istReihe = in_array('button-row', $klassen, true);

            if ($istRaster) {
                $raster++;
            }

            if ($istReihe) {
                $gesehen++;

                if ($stapel !== [] && $stapel[count($stapel) - 1]) {
                    $befunde[] = sprintf(
                        '%s:%d  <%s class="%s"> steht unmittelbar in `.sections`',
                        str_replace($this->root().'/', '', $datei),
                        substr_count(substr($vorlage, 0, $stelle), "\n") + 1,
                        $name,
                        implode(' ', $klassen),
                    );
                }
            }

            /*
             * Ein leeres Element hat keine Kinder — es gehört nicht auf den
             * Stapel. Eine **Komponente** schon, auch wenn sie wie eines
             * heisst: Vue trennt die beiden an der Grossschreibung, und
             * `$name` steht deshalb hier ungewandelt.
             */
            $leer = $name === strtolower($name) && in_array($name, self::VOID, true);

            if (! $selbstschliessend && ! $leer) {
                $stapel[] = $istRaster;
            }
        }

        return $befunde;
    }
}
