<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\WithoutPhpComments;

/**
 * Ein Nachladen, das nur einen Teil holt, holt auch nur einen Teil.
 *
 * ## Der Anlass
 *
 * Die Übersicht lädt ihre Kacheln seit dem 22. August 2026 alle dreissig
 * Sekunden nach: `router.reload({ only: ['tiles'] })`. Inertia siebt die
 * Angaben **vor** dem Auflösen — was der Browser nicht verlangt, wird nicht
 * gerechnet.
 *
 * **Aber nur, wenn es überhaupt noch zu rechnen ist.** Steht im Steuerungscode
 * `'services' => $this->services($agent)`, dann ist der Wert fertig, bevor
 * `Inertia::render` ihn zu sehen bekommt. Das Sieb wirft ihn danach weg, und
 * die Arbeit ist trotzdem getan: fünf Aufrufe an den Agenten, alle dreissig
 * Sekunden, für Zahlen, die niemand anfordert.
 *
 * > **Ein Nachladen, das nur einen Teil holt, spart nur dann etwas, wenn der
 * > Rest nicht schon gerechnet ist.**
 *
 * Und es meldet sich nicht: Die Seite ist richtig, die Kacheln stimmen, nur
 * der Server arbeitet das Zehnfache. Das ist genau die Sorte Fehler, die
 * dieses Projekt sechsmal bezahlt hat — **eine Zeichenkette, die auf etwas
 * verweist, ohne dass ein Typ, ein Test oder ein Werkzeug den Bezug prüft.**
 * `'tiles'` ist so eine.
 *
 * ## Was er prüft
 *
 * Für jedes `only:` in einer Seitenvorlage:
 *
 *   - Die Seite wird von einem `Inertia::render` mit diesem Namen erzeugt.
 *   - Jeder genannte Name ist dort eine Angabe, die es gibt.
 *   - Und diese Angabe wird als **Verschluss** übergeben.
 *
 * ## Was er nicht prüft
 *
 * Ob der Verschluss innerlich teuer ist — ein `fn () => $this->alles()` ist
 * ein Verschluss und rechnet trotzdem alles. Und er sieht nur `only:`; ein
 * `except:` gäbe es hier bisher nicht.
 */
final class PartialReloadTest extends TestCase
{
    use WithoutPhpComments;

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Jede Seitenvorlage, Pfad zu Inhalt.
     *
     * @return array<string, string>
     */
    private function pages(): array
    {
        return $this->files($this->root().'/resources/js', ['vue', 'ts']);
    }

    /**
     * Jeder Steuerungscode, Pfad zu Inhalt — **ohne seine Kommentare.**
     *
     * ## Was ohne diese Zeile passiert ist
     *
     * Am 26. August 2026 stand dieser Wächter rot, und zwar mit der Meldung
     * seiner **Untergrenze**: „Es wurde kein einziger Name geprüft — der
     * Ausdruck trifft nicht mehr." Geändert hatte sich an der Regel nichts;
     * geändert hatte sich ein Kommentar in `OverviewController`.
     *
     * Deutsche Anführungszeichen stehen in diesem Repo als `„…"` — die
     * öffnende ist U+201E, die schliessende ein gewöhnliches `"` (1214 mal
     * gegen ein einziges U+201C, ausgezählt). {@see self::closing()} liest
     * aber **Bytes** und hält jedes `"` für den Anfang einer Zeichenkette. Es
     * überspringt dann bis zum nächsten — und was dazwischen liegt, sieht es
     * nicht mehr.
     *
     * Solange die Zahl dieser Zeichen im gelesenen Bereich **gerade** ist,
     * geht das gut. Ein Kommentar mit einem einzigen Zitat mehr verschiebt
     * alles danach, die schliessende eckige Klammer wird nie gefunden, und
     * `Inertia::render('Overview', …)` fällt aus der Liste.
     *
     * > **Ein Wächter, der Anführungszeichen zählt, zählt die des Fliesstextes
     * > mit — und ob er zubeisst, entscheidet die Parität.**
     *
     * {@see WithoutPhpComments} beantwortet das über `token_get_all()`, also
     * über den Parser und nicht über ein Muster. Zehn Wächter dieses Repos
     * benutzen ihn schon; dieser hat ihn gebraucht und nicht gehabt.
     *
     * @return array<string, string>
     */
    private function controllers(): array
    {
        return array_map(
            fn (string $quelle): string => $this->withoutComments($quelle),
            $this->files($this->root().'/app/Http/Controllers', ['php']),
        );
    }

    /**
     * @param  list<string>  $endungen
     * @return array<string, string>
     */
    private function files(string $verzeichnis, array $endungen): array
    {
        $wurzel = $this->root();
        $dateien = [];

        /** @var SplFileInfo $datei */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($verzeichnis, FilesystemIterator::SKIP_DOTS),
        ) as $datei) {
            if (! $datei->isFile() || ! in_array($datei->getExtension(), $endungen, true)) {
                continue;
            }

            $dateien[str_replace($wurzel.'/', '', $datei->getPathname())] = (string) file_get_contents($datei->getPathname());
        }

        ksort($dateien);

        return $dateien;
    }

    /**
     * Die Namen aus einem `only: [...]`.
     *
     * @return list<string>
     */
    private function requested(string $quelle): array
    {
        preg_match_all('/\bonly:\s*\[([^\]]*)\]/', $quelle, $bloecke);

        $namen = [];

        foreach ($bloecke[1] as $block) {
            preg_match_all('/[\'"]([\w.]+)[\'"]/', $block, $treffer);

            $namen = array_merge($namen, $treffer[1]);
        }

        return array_values(array_unique($namen));
    }

    /**
     * Welche Inertia-Seite eine Vorlage ist.
     *
     * `resources/js/Pages/Files/Index.vue` heisst bei Inertia `Files/Index` —
     * derselbe Weg, den `resolvePageComponent` in `app.ts` geht.
     */
    private function component(string $pfad): ?string
    {
        if (! str_starts_with($pfad, 'resources/js/Pages/') || ! str_ends_with($pfad, '.vue')) {
            return null;
        }

        return substr($pfad, strlen('resources/js/Pages/'), -strlen('.vue'));
    }

    /**
     * Die Angaben jedes `Inertia::render` — Seitenname zu Name-und-Ausdruck.
     *
     * **Der Klammerzähler und nicht ein Ausdruck über die Zeile.** Die Angaben
     * eines `render` sind verschachtelt: Felder, Verschlüsse, Aufrufe. Ein
     * `'key' =>`, das in einem Unterfeld steht, ist keine Angabe der Seite.
     *
     * @return array<string, array<string, string>>
     */
    private function rendered(): array
    {
        $seiten = [];

        foreach ($this->controllers() as $quelle) {
            $stelle = 0;

            while (preg_match("/Inertia::render\\(\\s*'([^']+)'\\s*,\\s*\\[/", $quelle, $treffer, PREG_OFFSET_CAPTURE, $stelle) === 1) {
                $name = (string) $treffer[1][0];
                $nach = (int) $treffer[0][1] + strlen((string) $treffer[0][0]);
                $stelle = $nach;

                $ende = $this->closing($quelle, $nach);

                if ($ende === null) {
                    continue;
                }

                $seiten[$name] = array_merge(
                    $seiten[$name] ?? [],
                    $this->entries(substr($quelle, $nach, $ende - $nach)),
                );
            }
        }

        return $seiten;
    }

    /**
     * Wo die eckige Klammer zugeht, die bei `$nach` offen ist.
     *
     * Zeichenketten werden dabei übersprungen — eine `]` in einem Text ist
     * keine Klammer.
     */
    private function closing(string $quelle, int $nach): ?int
    {
        $tiefe = 1;
        $laenge = strlen($quelle);

        for ($i = $nach; $i < $laenge; $i++) {
            $zeichen = $quelle[$i];

            if ($zeichen === "'" || $zeichen === '"') {
                $i = $this->afterString($quelle, $i);

                continue;
            }

            if ($zeichen === '[') {
                $tiefe++;
            } elseif ($zeichen === ']') {
                $tiefe--;

                if ($tiefe === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** Der Index des schliessenden Anführungszeichens ab `$anfang`. */
    private function afterString(string $quelle, int $anfang): int
    {
        $zeichen = $quelle[$anfang];
        $laenge = strlen($quelle);

        for ($i = $anfang + 1; $i < $laenge; $i++) {
            if ($quelle[$i] === '\\') {
                $i++;

                continue;
            }

            if ($quelle[$i] === $zeichen) {
                return $i;
            }
        }

        return $laenge;
    }

    /**
     * Die Angaben eines `render`-Feldes — Name zu Ausdruck, nur oberste Ebene.
     *
     * @return array<string, string>
     */
    private function entries(string $feld): array
    {
        $angaben = [];
        $tiefe = 0;
        $laenge = strlen($feld);
        $name = null;
        $wertAb = 0;

        for ($i = 0; $i < $laenge; $i++) {
            $zeichen = $feld[$i];

            if ($zeichen === "'" || $zeichen === '"') {
                if ($tiefe === 0 && $name === null) {
                    $ende = $this->afterString($feld, $i);
                    $rest = ltrim(substr($feld, $ende + 1));

                    if (str_starts_with($rest, '=>')) {
                        $name = substr($feld, $i + 1, $ende - $i - 1);
                        $wertAb = strpos($feld, '=>', $ende) + 2;
                        $i = $wertAb - 1;

                        continue;
                    }
                }

                $i = $this->afterString($feld, $i);

                continue;
            }

            if (str_contains('([{', $zeichen)) {
                $tiefe++;
            } elseif (str_contains(')]}', $zeichen)) {
                $tiefe--;
            } elseif ($zeichen === ',' && $tiefe === 0 && $name !== null) {
                $angaben[$name] = trim(substr($feld, $wertAb, $i - $wertAb));
                $name = null;
            }
        }

        if ($name !== null) {
            $angaben[$name] = trim(substr($feld, $wertAb));
        }

        return $angaben;
    }

    /** Ist dieser Ausdruck ein Verschluss? */
    private function isClosure(string $ausdruck): bool
    {
        return preg_match('/^(?:static\s+)?(?:fn|function)\s*\(/', $ausdruck) === 1;
    }

    /**
     * **Die Gegenprobe, und sie kommt zuerst.**
     *
     * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
     * > Null steht.**
     */
    public function test_there_is_a_partial_reload_to_check(): void
    {
        $namen = [];

        foreach ($this->pages() as $quelle) {
            $namen = array_merge($namen, $this->requested($quelle));
        }

        $this->assertGreaterThanOrEqual(
            1,
            count($namen),
            'Es gibt kein `only:` mehr — dann prüft die Regel darunter nichts.',
        );

        $this->assertGreaterThanOrEqual(
            20,
            count($this->rendered()),
            'Der Ausdruck findet kaum noch Inertia::render — er trifft nicht mehr.',
        );
    }

    /**
     * **Und die Gegenprobe zum Leser der Angaben.**
     *
     * Der Fall darüber zählt am Bestand. Er merkt nicht, dass der Leser die
     * Ebenen verwechselt oder jeden Ausdruck für einen Verschluss hält — dann
     * wäre die Regel darunter für immer grün.
     *
     * Deshalb ein Prüfkörper von Hand:
     *
     * > **Eine Gegenprobe über eine Menge merkt nicht, dass ein Teil der Menge
     * > fehlt.**
     */
    public function test_the_reader_tells_a_closure_from_a_value(): void
    {
        $feld = implode("\n", [
            "    'server' => fn (): array => \$this->server(),",
            "    'hosting' => \$this->hosting(),",
            "    'tiles' => static function (): array { return ['key' => 'tief']; },",
            "    'name' => 'nicht, wirklich',",
        ]);

        /*
         * **Verglichen werden die Werte und nicht die Schlüssel.**
         *
         * Der erste Wurf verglich `array_keys()` — und blieb grün, als der
         * Bruch dem Leser das Überspringen von Zeichenketten nahm. Das Komma
         * in `'nicht, wirklich'` beendete die Angabe dann zu früh; als
         * Schlüssel stand weiter `name` da, als Wert `'nicht`. Beides sah von
         * aussen gleich aus.
         *
         * > **Ein Prüfkörper, der nur die Namen vergleicht, merkt nicht, dass
         * > die Werte falsch abgeschnitten sind.**
         *
         * Derselbe Satz wie bei `FileSearchTest` (`docs/66`): Er verglich die
         * Schlüssel, die beide Seiten schicken, und beide schickten denselben
         * kaputten Wert.
         */
        $this->assertSame(
            [
                'server' => 'fn (): array => $this->server()',
                'hosting' => '$this->hosting()',
                'tiles' => "static function (): array { return ['key' => 'tief']; }",
                'name' => "'nicht, wirklich'",
            ],
            $this->entries($feld),
            'Der Leser findet nicht genau die Angaben der obersten Ebene — ein Schlüssel aus einem '.
            'Unterfeld oder ein Komma in einer Zeichenkette hat ihn verwirrt.',
        );

        $angaben = $this->entries($feld);

        $this->assertTrue($this->isClosure($angaben['server']), 'Ein `fn () =>` gilt dem Leser nicht als Verschluss.');
        $this->assertTrue($this->isClosure($angaben['tiles']), 'Ein `static function ()` gilt dem Leser nicht als Verschluss.');
        $this->assertFalse($this->isClosure($angaben['hosting']), 'Ein fertiger Aufruf gilt dem Leser als Verschluss.');
        $this->assertFalse($this->isClosure($angaben['name']), 'Eine Zeichenkette gilt dem Leser als Verschluss.');
    }

    /** Jeder Name in einem `only:` zeigt auf einen Verschluss, den es gibt. */
    public function test_every_partially_reloaded_prop_is_a_closure(): void
    {
        $seiten = $this->rendered();
        $funde = [];
        $geprueft = 0;

        foreach ($this->pages() as $pfad => $quelle) {
            $verlangt = $this->requested($quelle);

            if ($verlangt === []) {
                continue;
            }

            $seite = $this->component($pfad);

            if ($seite === null || ! isset($seiten[$seite])) {
                /*
                 * **Rot und nicht still.** Ein Wächter, der einen Bezug nicht
                 * auflösen kann, hat an dieser Stelle nicht wenig gemessen —
                 * er hat gar nicht gemessen.
                 */
                $funde[] = sprintf('%s — zu dieser Vorlage findet sich kein Inertia::render', $pfad);

                continue;
            }

            foreach ($verlangt as $name) {
                $geprueft++;

                if (! isset($seiten[$seite][$name])) {
                    $funde[] = sprintf('%s — `%s` ist keine Angabe von %s', $pfad, $name, $seite);

                    continue;
                }

                if (! $this->isClosure($seiten[$seite][$name])) {
                    $funde[] = sprintf('%s — `%s` wird in %s fertig übergeben und nicht als Verschluss', $pfad, $name, $seite);
                }
            }
        }

        $this->assertGreaterThanOrEqual(1, $geprueft, 'Es wurde kein einziger Name geprüft — der Ausdruck trifft nicht mehr.');

        $this->assertSame([], $funde, implode("\n", [
            'Hier stimmt ein teilweises Nachladen nicht mit dem Steuerungscode ueberein:',
            ...$funde,
            '',
            'Inertia siebt die Angaben VOR dem Aufloesen. Steht im Steuerungscode ein',
            'fertiger Wert, ist er schon gerechnet, bevor das Sieb ihn sieht — die',
            'Anfrage kostet dann die ganze Seite und liefert einen Teil.',
            '',
            'Der Weg: jede Angabe als Verschluss uebergeben,',
            "  'services' => fn (): array => \$this->services(\$agent),",
        ]));
    }
}
