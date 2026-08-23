<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Eine Liste von Kennungen wird nicht mit einem blossen Leerzeichen geklebt.
 *
 * ## Der Anlass
 *
 * **Befund 2 der Bilderrunde** (`docs/76`), gefunden in Lage 1/1440/hell: Unter
 * dem Kästchen „Als Platzhalter bestellen" stand `*.cloudlab24.de
 * cloudlab24.de` — zwei Namen, getrennt nur durch ein Leerzeichen, beide mit
 * Punkten darin. Es gibt für den Leser kein Zeichen, an dem der eine aufhört.
 *
 * > **Zwei Schreibweisen für dieselbe Sache in einer Datei sind keine Wahl,
 * > sondern ein Versehen — und die seltenere ist die, die niemand
 * > gegenprüft.**
 *
 * Dieselbe Datei schrieb an sechs Stellen `, ` und an drei ein Leerzeichen.
 *
 * ## Warum die Regel am Leerzeichen hängt und nicht am `join()`
 *
 * **Der erste Wurf verbot jedes `join()` innerhalb einer `.ident`**, und das war
 * einen Tag später falsch. Er entstand zusammen mit {@see \resources\js\Components\Idents.vue},
 * die neben dem Komma auch eine **Umbruchgelegenheit** nach jedem `:` und `.`
 * setzt — und die gehört, wie sich auf dem Server gezeigt hat, nur in eine
 * Zelle und nicht in einen Satz (`docs/76`, Befund 4; die Regel dazu hält
 * {@see IdentPlacementTest}).
 *
 * Damit steht in sechs Sätzen dieses Panels wieder ein `join(', ')` in einer
 * `.ident`, und das ist richtig so. Was Befund 2 wirklich gekostet hat, war
 * nicht das `join()`, sondern sein Trennzeichen.
 *
 * > **Eine Regel, die mehr verbietet als ihr Befund hergibt, steht dem nächsten
 * > Befund im Weg.**
 *
 * ## Was er prüft
 *
 * In einem `.ident` steht kein `join(' ')` — kein Trennzeichen ohne
 * Satzzeichen. `join(', ')` bleibt erlaubt, `join("\n")` in einem `<pre>`
 * ebenfalls: Die Regel gilt nur dort, wo `.ident` steht, also wo Monospace und
 * `overflow-wrap: anywhere` zusammenkommen und jede Stelle eine Bruchstelle
 * sein darf.
 *
 * ## Was er nicht prüft
 *
 * Ob die Werte überhaupt Kennungen sind. Und er sieht nur `.vue`-Vorlagen — ein
 * `join()` im Skript, dessen Ergebnis später in eine `.ident` wandert, findet
 * er nicht.
 */
final class IdentListTest extends TestCase
{
    /**
     * Jede Stelle, an der in einem `.ident` ein `join(' ')` steht.
     *
     * **Elementweise und nicht zeilenweise**, und das ist der Kern.
     *
     * Der erste Wurf suchte `class="…ident…"` und `.join(` in **derselben**
     * Zeile. Der Bruch dazu änderte die Datei nachweislich — und der Wächter
     * blieb grün. Der Grund: Die Fundstelle, um die es überhaupt ging, stand
     * über zwei Zeilen.
     *
     *     <td data-column="Erwartet" class="ident quiet">
     *       {{ satz.expected.join(', ') || '—' }}
     *     </td>
     *
     * > **Ein Wächter, der den Fall nicht fängt, der ihn ausgelöst hat, ist
     * > keiner.**
     *
     * Gesucht wird deshalb der **Inhalt** des Elements: vom öffnenden Tag bis
     * zu seinem Schlusstag, mit gezählter Tiefe, damit ein verschachteltes
     * gleichnamiges Element nicht zu früh beendet.
     *
     * @return list<string>
     */
    private function joined(): array
    {
        $funde = [];

        foreach ($this->templates() as $pfad => $vorlage) {
            foreach ($this->joinedIn($vorlage) as $zeile) {
                $funde[] = $pfad.':'.$zeile;
            }
        }

        return $funde;
    }

    /**
     * Dieselbe Suche über eine einzelne Vorlage — für den Prüfkörper von Hand.
     *
     * **Herausgelöst, weil die Gegenprobe sonst am Bestand hinge.** Eine
     * Zählung über die Vorlagen merkt nicht, dass der Ausdruck seine
     * Unterscheidung verloren hat.
     *
     * @return list<string>
     */
    private function joinedIn(string $vorlage): array
    {
        $funde = [];

        foreach ($this->idents($vorlage) as [$stelle, $inhalt]) {
            /*
             * **Nur das blosse Leerzeichen.** `join(', ')`, `join(' · ')` und
             * `join(\"\\n\")` tragen ein Zeichen, an dem der Leser die Grenze
             * sieht; ein Leerzeichen allein tut das nicht, sobald die Werte
             * selbst Punkte enthalten.
             */
            if (preg_match("/\\.join\\(\\s*['\"] ['\"]\\s*\\)/", $inhalt) !== 1) {
                continue;
            }

            $funde[] = sprintf(
                '%d — %s',
                substr_count(substr($vorlage, 0, $stelle), "\n") + 1,
                trim((string) preg_replace('/\s+/', ' ', substr($inhalt, 0, 80))),
            );
        }

        return $funde;
    }

    /**
     * Jedes Element mit `ident` in seiner Klasse, als `[Anfang, Inhalt]`.
     *
     * @return list<array{int, string}>
     */
    private function idents(string $vorlage): array
    {
        $gefunden = [];
        $stelle = 0;

        while (preg_match('/<(\w[\w-]*)\b[^>]*\bclass="[^"]*\bident\b[^"]*"[^>]*>/', $vorlage, $treffer, PREG_OFFSET_CAPTURE, $stelle) === 1) {
            $anfang = (int) $treffer[0][1];
            $marke = (string) $treffer[1][0];
            $nach = $anfang + strlen((string) $treffer[0][0]);
            $stelle = $nach;

            // Selbstschliessend: kein Inhalt, nichts zu prüfen.
            if (str_ends_with((string) $treffer[0][0], '/>')) {
                continue;
            }

            $tiefe = 1;
            $lauf = $nach;

            while ($tiefe > 0 && preg_match('/<(\/?)'.preg_quote($marke, '/').'\b/', $vorlage, $t, PREG_OFFSET_CAPTURE, $lauf) === 1) {
                $tiefe += $t[1][0] === '/' ? -1 : 1;
                $lauf = (int) $t[0][1] + strlen((string) $t[0][0]);

                if ($tiefe === 0) {
                    $gefunden[] = [$anfang, substr($vorlage, $nach, (int) $t[0][1] - $nach)];
                }
            }
        }

        return $gefunden;
    }

    /**
     * Der `<template>`-Teil jeder `.vue`-Datei, Kommentare durch Leerzeichen
     * ersetzt.
     *
     * **Nur die Vorlage**, denn im Skript ist ein `join()` unauffällig und oft
     * richtig — `Cron.vue` baut damit einen Cron-Ausdruck, `Tile.vue` einen
     * SVG-Pfad.
     *
     * **Und ersetzt statt entfernt**, damit die Zeilennummern stimmen; sonst
     * zeigt der Wächter auf die falsche Stelle.
     *
     * @return array<string, string>
     */
    private function templates(): array
    {
        $wurzel = dirname(__DIR__, 2);
        $vorlagen = [];

        /** @var SplFileInfo $datei */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wurzel.'/resources/js', FilesystemIterator::SKIP_DOTS),
        ) as $datei) {
            if (! $datei->isFile() || $datei->getExtension() !== 'vue') {
                continue;
            }

            $quelle = (string) file_get_contents($datei->getPathname());

            if (preg_match('/^<template>$(.*?)^<\/template>$/ms', $quelle, $treffer) !== 1) {
                continue;
            }

            $vorlage = str_repeat("\n", substr_count(substr($quelle, 0, (int) strpos($quelle, $treffer[1])), "\n"))
                .(string) preg_replace_callback(
                    '/<!--.*?-->/s',
                    static fn (array $t): string => str_repeat(' ', strlen($t[0])),
                    $treffer[1],
                );

            $vorlagen[str_replace($wurzel.'/', '', $datei->getPathname())] = $vorlage;
        }

        ksort($vorlagen);

        return $vorlagen;
    }

    /**
     * **Die Gegenprobe, und sie kommt zuerst.**
     *
     * Findet der Ausdruck über die Vorlagen nichts, prüft der Fall darunter
     * null Zeilen und ist grün, ohne etwas gesehen zu haben.
     *
     * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
     * > Null steht.**
     */
    public function test_there_are_templates_to_check(): void
    {
        $zeilen = 0;

        foreach ($this->templates() as $vorlage) {
            $zeilen += substr_count($vorlage, "\n");
        }

        $this->assertGreaterThanOrEqual(
            2000,
            $zeilen,
            'Der Ausdruck über die <template>-Teile findet fast nichts — er trifft nicht mehr.',
        );
    }

    /**
     * **Und die Gegenprobe zum Ausdruck: Er findet ein Leerzeichen, und nur
     * das.**
     *
     * Der Fall darüber sichert, dass Vorlagen gelesen werden. Er sagt nichts
     * darüber, ob der Ausdruck das blosse Leerzeichen von einem Komma
     * unterscheidet — und ohne diese Unterscheidung wäre die Regel darunter
     * entweder für immer grün oder für sechs richtige Stellen rot.
     *
     * > **Eine Gegenprobe über eine Menge merkt nicht, dass ein Teil der Menge
     * > fehlt.**
     */
    public function test_the_expression_tells_a_comma_from_a_space(): void
    {
        $mitLeerzeichen = '<td class="ident">{{ namen.join(\' \') }}</td>';
        $mitKomma = '<td class="ident">{{ namen.join(\', \') }}</td>';

        $this->assertNotSame(
            [],
            $this->joinedIn($mitLeerzeichen),
            'Der Ausdruck findet das blosse Leerzeichen nicht — genau das war Befund 2.',
        );

        $this->assertSame(
            [],
            $this->joinedIn($mitKomma),
            'Der Ausdruck hält ein Komma für einen Fund. Damit wären sechs richtige Stellen rot.',
        );
    }

    /**
     * In einem `.ident` trennt kein blosses Leerzeichen eine Liste.
     */
    public function test_no_ident_separates_a_list_with_a_bare_space(): void
    {
        $funde = $this->joined();

        $this->assertSame([], $funde, implode("\n", [
            'Hier trennt ein blosses Leerzeichen eine Liste in einem .ident:',
            ...$funde,
            '',
            '.ident ist Monospace UND overflow-wrap: anywhere. Enthalten die Werte',
            'selbst Punkte — Domainnamen, Adressen —, dann gibt es fuer den Leser',
            'kein Zeichen, an dem der eine aufhoert. Gemessen in der Bilderrunde:',
            '`*.cloudlab24.de cloudlab24.de` las sich als ein kaputter Name.',
            '',
            'Der Weg: join(\', \') statt join(\' \').',
            '',
            'Steht der Wert ALLEIN in seiner Zelle, ist <Idents> die bessere Wahl —',
            'es setzt zusaetzlich eine Umbruchgelegenheit an den Trennzeichen des',
            'Formats. In einem Satz gehoert es nicht hin (IdentPlacementTest).',
        ]));
    }
}
