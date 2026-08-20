<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Ein Griff an einer Zeile öffnet einen Bereich — und holt ihn ins Bild.
 *
 * ## Der Fund
 *
 * Der Betreiber hat es zweimal gemeldet (`docs/64`, Befund 10): Wer bei 390 px
 * in einer Zeile weit unten „Rechte" oder „Umbenennen" drückt, sieht **nichts
 * geschehen** — der Bereich geht am Kopf der Seite auf. Auf der Cronseite
 * dasselbe nach unten: „Ändern" steht in der Liste, das Formular darunter.
 *
 * > **Ein Bedienelement, dessen Wirkung ausserhalb des Bildes erscheint, sieht
 * > aus wie eines ohne Wirkung.**
 *
 * **Die Behebung lag seit dem 15. August im Repo.** `resources/js/scroll.ts`
 * gibt es wegen desselben Vorgangs am Knopf „Entfernen"; `bringIntoView()` löst
 * es, und `useConfirmation` rief es. Sonst niemand.
 *
 * > **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten
 * > Merkmal wieder da, wenn die Behebung nicht die Regel wurde.**
 *
 * ## Woran dieser Wächter den Fall erkennt
 *
 * **Am Argument.** Ein Griff, der einen Gegenstand mitbekommt —
 * `startChmod(entry)`, `bearbeiten(job)` —, steht in einer Schleife und damit an
 * einer Zeile; sein Bereich steht ausserhalb der Schleife. Ein Umschalter der
 * Seite kommt ohne Argument aus (`picking = 'copy'`, `menuOpen = !menuOpen`),
 * und dort wäre ein Sprung falsch.
 *
 * Das ist eine Näherung und kein Beweis — aber eine, die die drei bekannten
 * Fälle trifft und die Umschalter der Seite in Ruhe lässt. Wo sie danebengreift,
 * steht der Eintrag in {@see self::UNEXAMINED} mit seinem Grund.
 */
final class RevealTest extends TestCase
{
    /**
     * Griffe derselben Bauart, die dieser Lauf nicht angesehen hat.
     *
     * **Die Datenbankkonsole hat neunzehn davon**, und keiner ist in der
     * Bilderrunde von P6 geprüft worden — sie gehört zu P5c. Ob dort dasselbe
     * gilt, ist eine offene Frage und kein Befund: Ihre Bereiche stehen in
     * derselben Spalte wie der Baum, und ob sie bei 390 px ausserhalb des
     * Bildes aufgehen, hat niemand gemessen.
     *
     * > **Ein Loch, das man zählt, ist kein Loch mehr — es ist eine Zahl, die
     * > kleiner werden kann.**
     *
     * Wer die Konsole das nächste Mal anfasst, misst es und streicht die Zeilen.
     *
     * @var list<string>
     */
    private const UNEXAMINED = [
        'Pages/Databases/Console.vue openCell openTable',
        'Pages/Databases/Console.vue openCell cell',
        'Pages/Databases/Console.vue openCell loadingCell',
        'Pages/Databases/Console.vue openCell failure',
        'Pages/Databases/Console.vue openColumns openView',
        'Pages/Databases/Console.vue openColumns columns',
        'Pages/Databases/Console.vue openColumns loadingTable',
        'Pages/Databases/Console.vue openIndexes openView',
        'Pages/Databases/Console.vue openIndexes indexes',
        'Pages/Databases/Console.vue openIndexes loadingTable',
        'Pages/Databases/Console.vue openRows openView',
        'Pages/Databases/Console.vue openRows order',
        'Pages/Databases/Console.vue openRows columns',
        'Pages/Databases/Console.vue openRows loadingTable',
        'Pages/Databases/Console.vue sortBy order',
        'Pages/Databases/Console.vue startUpdate editing',
        'Pages/Databases/Console.vue startUpdate cell',
        'Pages/Databases/Console.vue startUpdate failure',
        'Pages/Databases/Console.vue toggle expanded',
    ];

    /**
     * Jeder Griff an einer Zeile holt seinen Bereich ins Bild — oder steht in der Liste.
     */
    public function test_every_per_item_handle_reveals_its_block(): void
    {
        $verdrahtet = 0;
        $gesehen = [];

        foreach ($this->handles() as [$name, $ist]) {
            $gesehen[] = $name;

            if ($ist) {
                $verdrahtet++;

                continue;
            }

            $this->assertContains(
                $name,
                self::UNEXAMINED,
                sprintf(
                    "%s öffnet einen Bereich, der über ein `v-if` erscheint, und holt ihn nicht\n".
                    "ins Bild.\n\n".
                    "Der Griff bekommt einen Gegenstand mit, steht also an einer Zeile — sein\n".
                    "Bereich steht ausserhalb der Schleife und ist bei 390 px leicht ausserhalb\n".
                    "des Bildes. Wer ihn drückt, sieht dann nichts geschehen (docs/64, Befund 10).\n\n".
                    'Zu beheben mit `watch(...)` und `bringIntoView()` aus `resources/js/scroll.ts` '.
                    '— oder, wenn der Bereich wirklich neben seinem Griff steht, mit einer Zeile in '.
                    'RevealTest::UNEXAMINED und ihrem Grund.',
                    $name,
                ),
            );
        }

        /*
         * **Die Sperrklinke.** Ein Eintrag, den es nicht mehr gibt, sieht aus
         * wie ein bekanntes Loch und ist keins.
         */
        foreach (self::UNEXAMINED as $bekannt) {
            $this->assertContains(
                $bekannt,
                $gesehen,
                sprintf(
                    "`%s` steht in RevealTest::UNEXAMINED und kommt nicht mehr vor.\n\n".
                    'Entweder ist der Griff verschwunden oder umgebaut — dann gehört die Zeile '.
                    'gelöscht — oder die Suche findet ihn nicht mehr, und dann ist der Wächter kaputt.',
                    $bekannt,
                ),
            );
        }

        $this->assertGreaterThanOrEqual(
            3,
            $verdrahtet,
            'Es sind kaum noch Griffe verdrahtet. Befund 10 aus docs/64 betraf drei — `startChmod` '.
            'und `startRename` im Dateimanager und `bearbeiten` auf der Cronseite.',
        );
    }

    /**
     * Ein Bereich, der ins Bild geholt wird, kann den Fokus annehmen.
     *
     * `bringIntoView()` ruft am Ende `element.focus()`. Ohne `tabindex="-1"`
     * tut das an einem `div`, `form` oder `section` **nichts** — der Sprung
     * geschieht, der Tastaturweg dorthin nicht. Ein Fehler, den man nicht
     * sieht, solange man eine Maus benutzt.
     *
     * > **Ein Aufruf, der stillschweigend nichts tut, sieht aus wie einer, der
     * > gewirkt hat.**
     */
    public function test_a_revealed_block_can_take_the_focus(): void
    {
        $geprueft = 0;

        foreach ($this->vueFiles(dirname(__DIR__, 2).'/resources/js') as $datei) {
            $quelle = (string) file_get_contents($datei);

            if (! str_contains($quelle, 'bringIntoView')) {
                continue;
            }

            preg_match_all('/bringIntoView\((\w+)\.value\)/', $quelle, $treffer);

            foreach (array_unique($treffer[1]) as $name) {
                $geprueft++;

                $this->assertStringContainsString(
                    'tabindex="-1"',
                    $this->tagAround($quelle, 'ref="'.$name.'"'),
                    sprintf(
                        "In %s wird `%s` ins Bild geholt, aber sein Element trägt kein `tabindex=\"-1\"`.\n\n".
                        '`bringIntoView()` setzt am Ende den Fokus. Ohne die Angabe nimmt ein `div`, '.
                        '`form` oder `section` ihn nicht an — die Seite springt, und der Tastaturweg '.
                        'in den Bereich bleibt zu.',
                        basename($datei),
                        $name,
                    ),
                );
            }
        }

        $this->assertGreaterThanOrEqual(
            4,
            $geprueft,
            'Es werden kaum Bereiche gefunden, die ins Bild geholt werden — dann prüft dieser Fall nichts.',
        );
    }

    /**
     * Jeder Griff mit einem Gegenstand, der einen `v-if`-Bereich öffnet.
     *
     * @return list<array{0:string,1:bool}>
     */
    private function handles(): array
    {
        $gefunden = [];

        foreach ($this->vueFiles(dirname(__DIR__, 2).'/resources/js') as $datei) {
            $quelle = (string) file_get_contents($datei);

            if (preg_match('/<script setup[^>]*>(.*?)<\/script>/s', $quelle, $s) !== 1) {
                continue;
            }

            if (preg_match('#<template>(.*)</template>#s', $quelle, $t) !== 1) {
                continue;
            }

            $skript = $s[1];
            $markup = (string) preg_replace('/<!--.*?-->/s', ' ', $t[1]);
            $kurz = $this->relative($datei);

            preg_match_all('/@click="(\w+)\([^)]/', $markup, $rufe);

            foreach (array_unique($rufe[1]) as $funktion) {
                if (preg_match('/function\s+'.preg_quote($funktion, '/').'\s*\([^)]*\)[^{]*\{(.*?)\n\}/s', $skript, $koerper) !== 1) {
                    continue;
                }

                preg_match_all('/(\w+)\.value\s*=/', $koerper[1], $refs);

                foreach (array_unique($refs[1]) as $ref) {
                    if (preg_match('/v-if="[^"]*\b'.preg_quote($ref, '/').'\b/', $markup) !== 1) {
                        continue;
                    }

                    $verdrahtet = preg_match('/watch\(\s*'.preg_quote($ref, '/').'\b/', $skript) === 1
                        && str_contains($skript, 'bringIntoView');

                    $gefunden[] = [$kurz.' '.$funktion.' '.$ref, $verdrahtet];
                }
            }
        }

        return $gefunden;
    }

    /**
     * Das öffnende Tag, in dem eine Angabe steht.
     *
     * **Ein Ausdruck mit `[^>]*` hört mitten in einem Attribut auf.** Der erste
     * Anlauf dieses Wächters suchte `<[^>]*ref="block"[^>]*tabindex="-1"` und
     * meldete `FormErrors.vue` als Fund — dabei steht die Angabe dort. Das Tag
     * beginnt mit `<p v-if="messages.length > 0"`, und das `>` im Ausdruck
     * beendete die Zeichenklasse.
     *
     * > **Ein Ausdruck, der bei `>` aufhört, hört mitten in einem Attribut
     * > auf.**
     *
     * Gesucht wird deshalb ab der Angabe rückwärts bis zum `<` und vorwärts bis
     * zum ersten `>`, das **nicht** in Anführungszeichen steht.
     */
    private function tagAround(string $quelle, string $angabe): string
    {
        $stelle = strpos($quelle, $angabe);

        if ($stelle === false) {
            return '';
        }

        $anfang = strrpos(substr($quelle, 0, $stelle), '<');

        if ($anfang === false) {
            return '';
        }

        $laenge = strlen($quelle);
        $zitat = null;

        for ($i = $anfang; $i < $laenge; $i++) {
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
                return substr($quelle, $anfang, $i - $anfang + 1);
            }
        }

        return substr($quelle, $anfang);
    }

    /** Der Pfad, wie ihn die Liste oben schreibt. */
    private function relative(string $datei): string
    {
        $wurzel = dirname(__DIR__, 2).'/resources/js/';

        return str_replace($wurzel, '', $datei);
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

        sort($dateien);

        return $dateien;
    }
}
