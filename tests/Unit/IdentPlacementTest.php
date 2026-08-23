<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * `<Idents>` steht dort, wo der Wert allein steht — und nicht in einem Satz.
 *
 * ## Der Anlass: eine Behebung, die woanders Schaden angerichtet hat
 *
 * **Befund 4 der Bilderrunde** (`docs/76`), gefunden auf dem Server gegen
 * `v0.7.0-rc.5`, bei `dokument: 0` und `schiebt: []`. Die Zeile unter dem
 * DNS-Abgleich stand so da:
 *
 *     Zuletzt geprüft: 2026-08-23 06:45:47 · gefragt wurden 167.235.231.182, 159.69.
 *     110.93
 *
 * Die zweite Adresse ist mitten durchgebrochen — an einem Punkt, den
 * {@see \resources\js\Components\Idents.vue} als Umbruchgelegenheit gesetzt
 * hat. Vor der Behebung von Befund 1 und 2 brach diese Zeile am Leerzeichen
 * hinter dem Komma, und beide Adressen blieben ganz.
 *
 * ## Der Mechanismus
 *
 * Eine Umbruchgelegenheit ist keine Empfehlung, sondern eine Stelle wie jedes
 * Leerzeichen. Der Zeilenumbruch nimmt die **letzte, die noch passt** — und das
 * ist die im Inneren des Wertes, sobald sie weiter rechts steht als das
 * Leerzeichen davor.
 *
 * > **Eine Umbruchgelegenheit bricht, sobald es passt. `overflow-wrap:
 * > anywhere` bricht nur, wenn es sein muss.**
 *
 * In einer **Zelle**, in der der Wert allein steht, gibt es kein konkurrierendes
 * Leerzeichen: Dort tut die Gelegenheit genau das, wofür sie gebaut ist, und
 * entscheidet nur, *wo* ein ohnehin fälliger Bruch fällt. In einem **Satz**
 * kann sie nur verlieren.
 *
 * ## Was gemessen ist
 *
 * `tests/umbruch-messen.mjs` mit `tests/umbruch-faelle.json`, 321 Breiten je
 * Fall (320–1600 px in Vierer-Schritten), gemessen am 23. August 2026 gegen das
 * gebaute Stylesheet. Gezählt wird, bei wie vielen Breiten ein Wert über zwei
 * Zeilen geht:
 *
 *     Fundstelle                          ohne `<wbr>`   mit `<wbr>`
 *     660  .section-note „gefragt wurden"          0           291
 *     517  .section-note + .ident „ungedeckt"      0           289
 *     717  .notice warn Adressen                   0            14
 *     695  .notice warn + .ident „nicht gefragt"   0            11
 *     475  .hint + .ident Platzhalter              0             4   (380–392)
 *     634  td.ident — Zelle, Wert allein           6             6
 *
 * Zwei Dinge stehen darin. **In jedem Satz** macht die Gelegenheit es
 * schlechter, und zwar von „nie getrennt" auf bis zu 291 von 321 Breiten.
 * **In der Zelle** ändert sie die Anzahl nicht — sie ändert nur den Ort.
 *
 * Und die vierte Zeile ist die teuerste: Der Bereich 380–392 px enthält die
 * **390 px**, mit denen jede Bilderrunde dieses Projekts misst. Der Satz unter
 * „Als Platzhalter bestellen" — die Fundstelle von Befund 2 — war durch seine
 * eigene Behebung an genau dieser Breite zerschnitten.
 *
 * > **Eine Behebung ist eine Änderung, und jede Änderung ist ein neuer Anlass
 * > zu messen.**
 *
 * ## Warum zwei Breiten das nicht beantworten
 *
 * Bei 1440 px sahen die Fassungen von Fundstelle 475 **gleich** aus, bei 390 px
 * ebenfalls. Der Schaden liegt dazwischen und an anderen Fundstellen — er hängt
 * daran, wo die Zeile gerade endet.
 *
 * > **Eine Frage, deren Antwort an der Breite hängt, ist mit zwei Breiten nicht
 * > beantwortet.**
 *
 * ## Was er prüft
 *
 * Zu jedem `<Idents>` in einer Vorlage: Steht auf dem Weg zum nächsten Block
 * (`td`, `p`, `li`, `small`, `div`) Text daneben? Dann ist es ein Satz, und die
 * Komponente gehört dort nicht hin.
 *
 * ## Was er nicht prüft
 *
 * Ob die Zelle wirklich nur diesen einen Wert zeigt — zwei `<Idents>`
 * nebeneinander in einer Zelle fänden dieselbe Konkurrenz vor. Es gibt keine.
 */
final class IdentPlacementTest extends TestCase
{
    /**
     * Elemente, an denen die Suche nach oben endet.
     *
     * **Ein Block ist die Grenze, weil eine Zeile ihn nicht verlässt.** Text in
     * einem anderen Block kann mit dem Wert nie um dieselbe Zeile streiten.
     *
     * @var list<string>
     */
    private const BLOCKS = ['td', 'th', 'p', 'li', 'small', 'div', 'h1', 'h2', 'h3'];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Der `<template>`-Teil jeder `.vue`-Datei, Kommentare durch Leerzeichen
     * ersetzt.
     *
     * @return array<string, string>
     */
    private function templates(): array
    {
        $wurzel = $this->root();
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

            $vorlagen[str_replace($wurzel.'/', '', $datei->getPathname())] = (string) preg_replace_callback(
                '/<!--.*?-->/s',
                static fn (array $t): string => str_repeat(' ', strlen($t[0])),
                $treffer[1],
            );
        }

        ksort($vorlagen);

        return $vorlagen;
    }

    /**
     * Steht dieses `<Idents>` in einem Satz?
     *
     * Gesucht wird nach links und nach rechts, bis ein Block kommt: Findet sich
     * dazwischen ein Zeichen, das kein Markup und kein Leerraum ist, teilt der
     * Wert seine Zeile mit Wörtern.
     *
     * **Text *in* einem Geschwisterelement zählt nicht.** Der Zonenzelle in
     * `DnsCredentials.vue` steht ein `<template v-else-if>` mit dem Wort „vom
     * Anbieter" zur Seite — von beiden erscheint nur eines, und nie beide
     * zugleich.
     */
    private function inProse(string $vorlage, int $stelle, int $nach): bool
    {
        return $this->hasText(strrev(substr($vorlage, 0, $stelle)), true)
            || $this->hasText(substr($vorlage, $nach), false);
    }

    /**
     * Läuft in dieser Richtung Text, bevor ein Block kommt?
     *
     * @param  string  $text  bei `$rueckwaerts` umgedreht, damit eine Suche genügt
     */
    private function hasText(string $text, bool $rueckwaerts): bool
    {
        $tiefe = 0;
        $laenge = strlen($text);
        $auf = $rueckwaerts ? '>' : '<';
        $zu = $rueckwaerts ? '<' : '>';

        for ($i = 0; $i < $laenge; $i++) {
            $zeichen = $text[$i];

            if ($zeichen === $auf) {
                // Die Marke einlesen und ansehen, ob sie ein Block ist.
                $ende = strpos($text, $zu, $i);
                $marke = substr($text, $i + 1, ($ende === false ? $laenge : $ende) - $i - 1);

                if ($rueckwaerts) {
                    $marke = strrev($marke);
                }

                if (preg_match('/^\s*<?\s*(\/?)\s*([\w-]+)/', '<'.ltrim($marke, '<'), $t) === 1
                    && in_array(strtolower($t[2]), self::BLOCKS, true)) {
                    return false;
                }

                $tiefe += $rueckwaerts ? -1 : 1;
                $i = $ende === false ? $laenge : $ende;

                continue;
            }

            if ($zeichen === $zu) {
                $tiefe += $rueckwaerts ? 1 : -1;

                continue;
            }

            // Ausserhalb einer Marke und kein Leerraum: ein Wort steht daneben.
            if ($tiefe <= 0 && trim($zeichen) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Jede Fundstelle von `<Idents>`, mit der Auskunft, ob sie im Satz steht.
     *
     * @return list<array{string, int, bool}>
     */
    private function placements(): array
    {
        $gefunden = [];

        foreach ($this->templates() as $pfad => $vorlage) {
            $stelle = 0;

            while (preg_match('/<Idents\b[^>]*\/>/', $vorlage, $treffer, PREG_OFFSET_CAPTURE, $stelle) === 1) {
                $anfang = (int) $treffer[0][1];
                $nach = $anfang + strlen((string) $treffer[0][0]);
                $stelle = $nach;

                $gefunden[] = [
                    sprintf('%s:%d', $pfad, substr_count(substr($vorlage, 0, $anfang), "\n") + 1),
                    $anfang,
                    $this->inProse($vorlage, $anfang, $nach),
                ];
            }
        }

        return $gefunden;
    }

    /**
     * **Die Gegenprobe, und sie kommt zuerst.**
     *
     * Steht `<Idents>` nirgends mehr, prüft der Fall darunter nichts und ist
     * grün, ohne etwas gesehen zu haben.
     *
     * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
     * > Null steht.**
     */
    public function test_the_component_is_actually_used(): void
    {
        $this->assertGreaterThanOrEqual(
            6,
            count($this->placements()),
            '<Idents> steht fast nirgends — dann ist die Regel darunter eine Verbotstafel ohne Weg.',
        );
    }

    /**
     * **Und die Gegenprobe zum Leser: Er unterscheidet Zelle von Satz.**
     *
     * Ohne diese Unterscheidung wäre die Regel darunter entweder für immer grün
     * oder für jede der acht richtigen Fundstellen rot. Eine Zählung am Bestand
     * merkt das nicht.
     *
     * > **Eine Gegenprobe über eine Menge merkt nicht, dass ein Teil der Menge
     * > fehlt.**
     */
    public function test_the_reader_tells_a_cell_from_a_sentence(): void
    {
        $faelle = [
            'Zelle, Wert allein' => ['<td class="right ident"><Idents :values="a" /></td>', false],
            'Zelle mit zwei Zweigen' => [
                '<td class="right"><template v-if="n"><Idents :values="a" /></template>'
                .'<template v-else>vom Anbieter</template></td>',
                false,
            ],
            'Text davor' => ['<p>gefragt wurden <Idents :values="a" /></p>', true],
            'Text dahinter' => ['<p><Idents :values="a" /> sind erreichbar</p>', true],
            'Text um eine Hülle herum' => [
                '<small class="hint">Ein Zertifikat für <span class="ident"><Idents :values="a" /></span> — es gilt …</small>',
                true,
            ],
        ];

        foreach ($faelle as $name => [$markup, $erwartet]) {
            $stelle = (int) strpos($markup, '<Idents');
            $nach = $stelle + strlen((string) preg_replace('/^.*?(<Idents\b[^>]*\/>).*$/s', '$1', $markup));

            $this->assertSame(
                $erwartet,
                $this->inProse($markup, $stelle, $nach),
                sprintf('Der Leser beurteilt „%s" falsch.', $name),
            );
        }
    }

    /** Kein `<Idents>` steht in einem Satz. */
    public function test_no_idents_stands_in_running_text(): void
    {
        $funde = [];

        foreach ($this->placements() as [$ort, , $imSatz]) {
            if ($imSatz) {
                $funde[] = $ort;
            }
        }

        $this->assertSame([], $funde, implode("\n", [
            'Hier steht <Idents> in einem Satz:',
            ...$funde,
            '',
            'Die Umbruchgelegenheit im Inneren eines Wertes gewinnt gegen das',
            'Leerzeichen daneben — die Zeile bricht dann mitten durch eine Adresse.',
            'Gemessen ueber 321 Breiten (tests/umbruch-messen.mjs): ohne sie bricht',
            'in einem Satz KEIN Wert, mit ihr bis zu 291 von 321.',
            '',
            'Der Weg: im Satz ein join(\', \'). <Idents> gehoert dorthin, wo der Wert',
            'allein in seiner Zelle steht — dort gibt es kein Leerzeichen, gegen das',
            'die Gelegenheit gewinnen koennte.',
        ]));
    }
}
