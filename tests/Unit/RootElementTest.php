<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Das Wurzelelement der Anwendung gibt es genau einmal, und es steht im Rumpf.
 *
 * ## Der Fund
 *
 * `resources/views/app.blade.php` hatte `@inertia` **zweimal** — einmal im Kopf
 * und einmal im Rumpf (`docs/64`, Befund 17). Die Direktive setzt das
 * Wurzelelement, also ein `<div>`; im `<head>` ist das nicht erlaubt, und der
 * Parser schliesst den Kopf an dieser Stelle. Das ausgelieferte Dokument trug
 * damit zwei Elemente mit `id="app"`: Die Anwendung hing in dem aus dem Kopf,
 * das gemeinte aus dem Rumpf blieb leer stehen.
 *
 * In den Kopf gehört `@inertiaHead`. Die beiden sehen sich ähnlich und tun
 * Entgegengesetztes.
 *
 * ## Warum es nie aufgefallen ist
 *
 * Die falsche Zeile war die **letzte** vor `</head>`. Alles, was nach ihr
 * gekommen wäre, hätte im Rumpf gelegen und wäre wirkungslos gewesen — Favicon,
 * Manifest, Farbschema.
 *
 * > **Ein Fehler, der nur deshalb nichts kaputt macht, weil er an der letzten
 * > Stelle steht, ist kein kleiner Fehler — er ist einer mit Glück.**
 *
 * ## Warum es zwei Wächter sind
 *
 * Dieser hier liest die **Vorlage** und läuft ohne Framework. Sein Zwilling
 * `Tests\Feature\RootElementTest` misst das **Ergebnis** — genau eine Kennung
 * `app` im ausgelieferten Dokument. Der zweite ist der stärkere: Er hätte den
 * Fehler gefunden, ohne zu wissen, wie er entsteht.
 *
 * > **Ein Wächter über die Absicht findet, was jemand falsch geschrieben hat.
 * > Ein Wächter über das Ergebnis findet auch, was niemand geschrieben hat.**
 */
final class RootElementTest extends TestCase
{
    /** Die Vorlage, in der beide Direktiven stehen. */
    private const LAYOUT = 'resources/views/app.blade.php';

    /**
     * `@inertia` steht genau einmal, und zwar im Rumpf.
     */
    public function test_the_root_element_is_set_once_and_in_the_body(): void
    {
        $quelle = $this->layout();

        /*
         * `@inertiaHead` fängt mit `@inertia` an — ohne die Wortgrenze zählte
         * die Kopfzeile mit, und der Wächter wäre genau an dem Fehler blind,
         * gegen den er gebaut ist.
         */
        preg_match_all('/@inertia\b(?!Head)/', $quelle, $treffer, PREG_OFFSET_CAPTURE);

        $this->assertCount(
            1,
            $treffer[0],
            sprintf(
                "`@inertia` steht %dmal in %s, erlaubt ist einmal.\n\n".
                'Jedes Vorkommen setzt ein eigenes `<div id="app">`. Zwei davon heissen: Eine '.
                'Kennung ist im Dokument nicht mehr eindeutig, und welches der beiden Elemente '.
                'die Anwendung trägt, entscheidet die Reihenfolge im Dokument (docs/64, Befund 17).',
                count($treffer[0]),
                self::LAYOUT,
            ),
        );

        $kopfEnde = strpos($quelle, '</head>');

        $this->assertNotFalse($kopfEnde, self::LAYOUT.' hat kein `</head>`.');

        $this->assertGreaterThan(
            $kopfEnde,
            (int) $treffer[0][0][1],
            '`@inertia` steht im Kopf. Dort ist ein `<div>` nicht erlaubt: Der Parser schliesst '.
            'den Kopf an dieser Stelle, und alles danach — Favicon, Manifest, Farbschema — liegt '.
            'im Rumpf und ist wirkungslos. In den Kopf gehört `@inertiaHead`.',
        );
    }

    /**
     * Im Kopf steht `@inertiaHead`.
     *
     * **Nicht, weil es heute etwas ausgäbe.** Ohne serverseitiges Rendern
     * schreibt die Direktive nichts. Sie steht dort, weil zehn Seiten die
     * `<Head>`-Komponente einbinden — sie ist der Ort, an dem das ankäme,
     * sobald es eingeschaltet wird.
     */
    public function test_the_head_carries_its_own_directive(): void
    {
        $quelle = $this->layout();
        $kopfEnde = strpos($quelle, '</head>');

        $this->assertNotFalse($kopfEnde, self::LAYOUT.' hat kein `</head>`.');

        $stelle = strpos($quelle, '@inertiaHead');

        $this->assertNotFalse(
            $stelle,
            '`@inertiaHead` fehlt in '.self::LAYOUT.'. Zehn Seiten binden die `<Head>`-Komponente '.
            'ein; ohne diese Direktive gibt es keinen Ort, an dem ihre Ausgabe landen könnte.',
        );

        $this->assertLessThan(
            $kopfEnde,
            (int) $stelle,
            '`@inertiaHead` steht ausserhalb des Kopfes. Dort setzt sie Kopfzeilen in einen Rumpf.',
        );
    }

    /**
     * Die Vorlage **ohne ihre Kommentare**.
     *
     * Der erste Anlauf hat drei `@inertia` gefunden statt einem — zwei davon in
     * dem Blade-Kommentar, der über der Zeile erklärt, warum sie dort steht.
     * Ein Kommentar, der seinen Gegenstand beim Namen nennt, wird sonst
     * mitgezählt.
     *
     * > **Ein Wächter, der den Quelltext liest, liest auch, was über ihn
     * > geschrieben steht.**
     *
     * Aufgefallen ist es beim ersten Lauf und nicht in der CI — der Wächter
     * war eine Minute alt.
     */
    private function layout(): string
    {
        $pfad = dirname(__DIR__, 2).'/'.self::LAYOUT;

        $this->assertFileExists($pfad);

        $roh = (string) file_get_contents($pfad);

        /*
         * Ersetzt wird durch Leerzeichen gleicher Länge, damit die Stellen
         * stimmen: Dieser Wächter vergleicht Positionen mit der von `</head>`.
         */
        return (string) preg_replace_callback(
            '/\{\{--.*?--\}\}/s',
            static fn (array $t): string => str_repeat(' ', strlen($t[0])),
            $roh,
        );
    }
}
