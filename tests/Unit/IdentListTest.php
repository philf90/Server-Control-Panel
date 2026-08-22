<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Eine Liste von Kennungen wird nicht mit `join()` zusammengeklebt.
 *
 * ## Der Anlass — zwei Befunde, eine Ursache
 *
 * Beide aus der Bilderrunde (`docs/76`), beide ohne Zahl gefunden:
 *
 * **Befund 1.** `2a0a:4cc0:c1:ebd1:b82d:51ff:fe72:3083` brach bei 390 px nach
 * `51ff:f` — also **mitten im Hextet**. `.ident` trägt `overflow-wrap:
 * anywhere` (nötig, sonst schiebt die Adresse die Seite aus dem Bild,
 * `docs/67`), und die Regel kennt keine bevorzugte Trennstelle.
 *
 * **Befund 2.** `*.cloudlab24.de cloudlab24.de` — zwei Namen, getrennt nur
 * durch ein Leerzeichen, beide mit Punkten darin. Kein Zeichen sagt, wo der
 * eine aufhört.
 *
 * > **Ein Umbruch ohne bevorzugte Stelle bricht dort, wo es passt, und nicht
 * > dort, wo man liest.**
 *
 * {@see \resources/js/Components/Idents.vue} löst beides an einer Stelle: ein
 * Komma zwischen den Werten, eine Umbruchgelegenheit nach jedem `:` und `.`
 * **innerhalb** eines Wertes.
 *
 * ## Warum die Regel an `.ident` hängt und nicht an `join()`
 *
 * Ein `join()` im Template ist nicht an sich falsch. `props.directives.join(',
 * ')` in einem Fliesstext ist eine Aufzählung von Wörtern, und
 * `lines.join("\n")` in einem `<pre>` ist der Inhalt einer Datei. Beide
 * bleiben.
 *
 * Der Schaden entsteht dort, wo `.ident` steht: Monospace **und**
 * `overflow-wrap: anywhere`. Genau die Kombination macht aus einer Liste eine
 * Zeichenkette, die überall brechen darf.
 *
 * > **Eine Regel, die den Ort nennt, an dem der Schaden entsteht, trifft
 * > weniger und hält länger als eine, die das Werkzeug verbietet.**
 *
 * **Beim Bau standen elf Fundstellen im Bestand**, vier davon hatte der erste
 * Durchgang übersehen — sie standen nicht in `Domains/Show.vue`, wo der Befund
 * entdeckt worden war, sondern in `Settings/Php.vue` und `Settings/Tls.vue`.
 * Zwei davon führten Domainnamen und IP-Adressen.
 *
 * > **Ein Befund, den man an der Fundstelle behebt, ist an den anderen
 * > Fundstellen nicht behoben.**
 *
 * ## Was er nicht prüft
 *
 * Ob `<Idents>` an der richtigen Stelle steht, und ob die Werte überhaupt
 * Kennungen sind. Und er sieht nur `.vue`-Vorlagen — ein `join()` im Skript,
 * dessen Ergebnis später in eine `.ident` wandert, findet er nicht.
 */
final class IdentListTest extends TestCase
{
    /**
     * Jede Stelle, an der in einem `.ident` ein `join()` steht.
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
            foreach ($this->idents($vorlage) as [$stelle, $inhalt]) {
                if (! str_contains($inhalt, '.join(')) {
                    continue;
                }

                $funde[] = sprintf(
                    '%s:%d — %s',
                    $pfad,
                    substr_count(substr($vorlage, 0, $stelle), "\n") + 1,
                    trim((string) preg_replace('/\s+/', ' ', substr($inhalt, 0, 80))),
                );
            }
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
     * Wie oft `<Idents>` im Bestand steht.
     */
    private function uses(): int
    {
        $summe = 0;

        foreach ($this->templates() as $vorlage) {
            $summe += substr_count($vorlage, '<Idents');
        }

        return $summe;
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
     * **Und die Gegenprobe zur anderen Hälfte: Die Komponente wird benutzt.**
     *
     * Der Fall darüber sichert, dass Vorlagen gelesen werden. Er sagt nichts
     * darüber, ob `<Idents>` überhaupt irgendwo steht — und ohne das wäre die
     * Regel eine Verbotstafel vor einer leeren Strasse. Elf Stellen sind es
     * beim Bau dieses Wächters.
     */
    public function test_the_component_is_actually_used(): void
    {
        $this->assertGreaterThanOrEqual(
            8,
            $this->uses(),
            '<Idents> steht fast nirgends — dann ist die Regel darunter eine Verbotstafel ohne Weg.',
        );
    }

    /**
     * In einem `.ident` steht kein `join()`.
     */
    public function test_no_ident_glues_a_list_with_join(): void
    {
        $funde = $this->joined();

        $this->assertSame([], $funde, implode("\n", [
            'Hier klebt ein join() eine Liste in einem .ident zusammen:',
            ...$funde,
            '',
            '.ident ist Monospace UND overflow-wrap: anywhere. Eine so entstandene',
            'Zeichenkette darf ueberall brechen — gemessen: eine IPv6 brach mitten',
            'im Hextet, und zwei Namen mit Punkten standen ohne erkennbare Grenze',
            'nebeneinander.',
            '',
            'Der Weg: <Idents :values="…" />. Ein Komma zwischen den Werten, eine',
            'Umbruchgelegenheit nach jedem : und . innerhalb eines Wertes.',
            '',
            'Ein join() ausserhalb von .ident bleibt erlaubt — eine Aufzaehlung im',
            'Fliesstext ist keine Kennung.',
        ]));
    }
}
