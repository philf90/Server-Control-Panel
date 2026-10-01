<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Design\Contrast;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Tests\Support\CssRules;
use Tests\Support\TemplateDom;
use Throwable;

/**
 * Eine Fläche, die ihren eigenen Grund mitbringt, rechnet ihre Schrift dagegen
 * nach.
 *
 * **Warum es diesen Wächter gibt.** `ButtonStyleTest` rechnet gegen die Marken
 * aus `:root` — er kennt zwei Gründe, `--bg` und `--surface` je Theme. Seit
 * dem 9. September 2026 gibt es zwei Flächen, die eigene Marken setzen: den
 * Navigationsstreifen samt Kopfleiste (`.rail, .topbar`) und die Anmeldeseite
 * (`.signin`). Auf ihnen gilt keine der beiden Rechnungen.
 *
 * Der naive Wurf setzt dort **nur** `--nav-bg` und lässt die Textmarken stehen:
 * `--text` erreicht auf Inkberry **1,76:1**, der Streifen ist da und die
 * Navigation fort. Das ist kein hypothetischer Fall, sondern der erste, den
 * `docs/900 §3` als Falle 2 beschreibt.
 *
 * > **Ein Streifen mit eigenem Grund braucht acht Marken und nicht eine.**
 *
 * **Gefunden werden die Blöcke und nicht aufgezählt.** Wer morgen eine dritte
 * Markenfläche baut, wird mitgeprüft, ohne dass hier jemand nachträgt — das
 * Merkmal ist, dass ein Selektor einen **Grund** setzt (`--bg`, `--surface`
 * oder `--nav-bg`) und dazu Schrift.
 *
 * **Und am 1. Oktober 2026 hat sich gezeigt, dass auch acht nicht reichen**
 * (`docs/139 §8`). Gerechnet war, was die Fläche setzt; was auf ihr steht, las
 * mehr. Das Zeichen in `MarkIcon.vue` liest `--mark-accent`, die Versionsmarke
 * `--surface` und `--line`, die Meldungen der Anmeldeseite `--warn` und
 * `--critical`. Keine davon setzte die Fläche, und im hellen Thema kamen sie
 * vom hellen Grund der Seite — der obere Balken des Zeichens mit 1,87:1 auf
 * Inkberry. Der Name daneben hatte gar keine Regel mehr und erbte `color` vom
 * `body`, 1,76:1.
 *
 * > **Eine Fläche mit eigenem Grund setzt jede Marke, die etwas auf ihr liest
 * > — nicht nur die, an die man beim Entwurf gedacht hat.**
 *
 * Gefragt wird deshalb in beide Richtungen: ob, was die Fläche setzt, auf ihr
 * lesbar ist (die Rechnungen), und ob, was auf ihr steht, nur Marken liest, die
 * sie setzt oder die in beiden Themen gleich sind
 * ({@see self::test_what_stands_on_a_brand_surface_reads_its_own_marks()}).
 */
final class SurfaceTokenTest extends TestCase
{
    /**
     * Welche Marke auf so einer Fläche der Grund ist, und welche Schrift
     * darauf steht. Der Grund steht zuerst; die erste vorhandene gewinnt.
     */
    private const GRUENDE = ['nav-bg', 'surface', 'bg'];

    /** Was 4,5:1 erreichen muss (WCAG 1.4.3) — Text in jeder Rolle. */
    private const SCHRIFT = ['text', 'text-strong', 'text-muted', 'text-faint', 'accent'];

    /**
     * Die Zustandsfarben. Sie tragen Text — ein Abzeichen, das Wort einer
     * Kachel —, also gilt für sie dasselbe wie für Schrift, sobald eine Fläche
     * sie setzt. Auf der Anmeldeseite tut sie das seit dem 1. Oktober 2026.
     */
    private const ZUSTAND = ['ok', 'warn', 'critical', 'info'];

    /** Was ein Zeichen färbt und keinen Text: 3:1 nach WCAG 1.4.11. */
    private const GRAFIK = ['mark-accent'];

    /**
     * Komponenten, die nicht aus einer `.vue` dieses Repos kommen. `Link`
     * rendert ein `<a>`; die übrigen setzen kein eigenes Element und lassen
     * stehen, was in ihnen steht. Jede andere unbekannte Komponente auf einer
     * Markenfläche ist ein Befund — dort hätte dieser Wächter nicht gemessen.
     *
     * @var array<string, string|null>
     */
    private const FREMD = [
        'x-link' => 'a',
        'x-transition' => null,
        'x-transitiongroup' => null,
        'x-keepalive' => null,
        'x-teleport' => null,
        'x-suspense' => null,
    ];

    /**
     * Wo der Befund vom 1. Oktober 2026 sass. Liest dieser Wächter eine dieser
     * Dateien nicht mit, hat er den Fall nicht gesehen, für den es ihn gibt.
     *
     * @var array<string, list<string>>
     */
    private const GESEHEN = [
        '.rail' => ['resources/js/Components/BrandMark.vue', 'resources/js/Components/MarkIcon.vue'],
        '.signin' => ['resources/js/Components/FormErrors.vue'],
    ];

    public function test_a_brand_surface_carries_its_own_type(): void
    {
        $bloecke = $this->markenflaechen();

        $this->assertGreaterThan(
            1,
            count($bloecke),
            'Es werden kaum Markenflächen gefunden — dann prüft dieser Test nichts. '.
            'Erwartet werden mindestens `.rail`, `.topbar` und `.signin`.',
        );

        foreach ($bloecke as $selektor => $marken) {
            $grund = $this->ground($marken);

            $this->assertIsString(
                $grund,
                sprintf('„%s" setzt Schriftmarken und keinen Grund — dann steht die Schrift auf dem Grund der Seite.', $selektor),
            );

            foreach (self::SCHRIFT as $rolle) {
                if (! isset($marken[$rolle])) {
                    continue;
                }

                $verhaeltnis = $this->contrast($marken[$rolle], $marken[$grund]);

                $this->assertGreaterThanOrEqual(
                    4.5,
                    $verhaeltnis,
                    sprintf(
                        '„%s": --%s (%s) erreicht auf --%s (%s) nur %.2f:1.'."\n".
                        'WCAG 1.4.3 verlangt 4,5:1 für Text. Wer den Grund einer Fläche ändert und ihre '.
                        'Schriftmarken stehen lässt, bekommt eine Fläche ohne Inhalt.',
                        $selektor,
                        $rolle,
                        $marken[$rolle],
                        $grund,
                        $marken[$grund],
                        $verhaeltnis,
                    ),
                );
            }
        }
    }

    /**
     * Die Grenze eines Bedienelements auf so einer Fläche — 3:1 nach WCAG
     * 1.4.11, dieselbe Zahl wie in `ButtonStyleTest`, nur gegen den eigenen
     * Grund gerechnet statt gegen den der Seite.
     */
    public function test_a_control_on_a_brand_surface_keeps_its_border(): void
    {
        $geprueft = 0;

        foreach ($this->markenflaechen() as $selektor => $marken) {
            if (! isset($marken['control-line'], $marken['control-bg'])) {
                continue;
            }

            $geprueft++;

            $verhaeltnis = $this->contrast($marken['control-line'], $marken['control-bg']);

            $this->assertGreaterThanOrEqual(
                3.0,
                $verhaeltnis,
                sprintf(
                    '„%s": --control-line (%s) erreicht auf --control-bg (%s) nur %.2f:1 (WCAG 1.4.11 verlangt 3:1).',
                    $selektor,
                    $marken['control-line'],
                    $marken['control-bg'],
                    $verhaeltnis,
                ),
            );
        }

        $this->assertGreaterThan(
            0,
            $geprueft,
            'Keine Markenfläche führt ein Bedienelement — dann prüft dieser Test nichts.',
        );
    }

    /**
     * Eine Zustandsfarbe, die eine Fläche setzt, ist gegen deren Grund
     * gerechnet und nicht gegen den der Seite.
     *
     * Die Anmeldeseite trägt seit dem 1. Oktober 2026 die Werte des dunklen
     * Themas für `--warn` und `--critical` — in beiden Themen, weil sie in
     * beiden dunkel ist (`docs/139 §8`). Wer dort einmal den Wert des hellen
     * Themas einträgt, steht wieder bei 2,73:1.
     */
    public function test_a_state_colour_on_a_brand_surface_is_reckoned_against_it(): void
    {
        $geprueft = $this->reckon(self::ZUSTAND, 4.5, 'WCAG 1.4.3 verlangt 4,5:1, und eine Zustandsfarbe trägt Text.');

        $this->assertGreaterThan(
            0,
            $geprueft,
            'Keine Markenfläche setzt eine Zustandsfarbe — dann prüft dieser Test nichts. '.
            'Erwartet wird mindestens `.signin` mit --warn und --critical.',
        );
    }

    /**
     * Das Zeichen ist ein grafisches Objekt: 3:1 gegen den Grund, auf dem es
     * steht. Ohne eigenen Wert trug es im hellen Thema den Indigo der Seite,
     * 1,87:1 auf Inkberry.
     */
    public function test_the_mark_on_a_brand_surface_stays_visible(): void
    {
        $geprueft = $this->reckon(self::GRAFIK, 3.0, 'WCAG 1.4.11 verlangt 3:1 für ein grafisches Objekt.');

        $this->assertGreaterThan(
            1,
            $geprueft,
            'Kaum eine Markenfläche setzt --mark-accent — dann prüft dieser Test nichts. '.
            'Erwartet werden `.rail`, `.topbar` und `.signin`.',
        );
    }

    /**
     * Eine Markenfläche setzt `color` und nicht nur `--text`.
     *
     * **Der Grund ist die Vererbung.** `color` erbt ein Element als **fertigen
     * Wert**: Was keine eigene Regel hat, nimmt die Farbe, die der nächste
     * Vorfahr mit einer Regel ausgerechnet hat — ohne diese Zeile ist das der
     * `body`, mit seinem `--text` aus `:root`. Die Marke der Fläche kommt dabei
     * nie zum Zug. So stand der Name neben dem Zeichen seit B6 im hellen Thema
     * mit 1,76:1 auf der Leiste, und auf der Anmeldeseite die Fehlermeldung mit
     * 1,83:1 (`docs/139 §8`).
     *
     * Gelesen werden muss eine Schriftmarke, die die Fläche selbst setzt —
     * sonst erbt alles darunter wieder eine fremde.
     */
    public function test_a_brand_surface_sets_the_colour_its_text_inherits(): void
    {
        $flaechen = $this->surfaces($this->stylesheet());

        $this->assertGreaterThan(1, count($flaechen), 'Es werden kaum Markenflächen gefunden — dann prüft dieser Test nichts.');

        foreach ($flaechen as $selektor => $flaeche) {
            $gelesen = preg_match('/(?<![\w-])color\s*:\s*var\(\s*--([\w-]+)\s*\)/', $flaeche['body'], $treffer) === 1
                ? $treffer[1]
                : null;

            $this->assertIsString($gelesen, sprintf(
                "„%s\" setzt seine Schriftmarken und nicht `color`.\n\n".
                'Text ohne eigene Regel erbt dann die Farbe, die der `body` aus dem `--text` der Seite '.
                'gerechnet hat — im hellen Thema dunkle Schrift auf dem dunklen Grund der Fläche. '.
                'Die Fläche braucht `color: var(--text);` (docs/139 §8).',
                $selektor,
            ));

            $this->assertTrue(
                in_array($gelesen, self::SCHRIFT, true) && isset($flaeche['marks'][$gelesen]),
                sprintf(
                    '„%s" setzt `color: var(--%s)`, und das ist keine Schriftmarke, die die Fläche selbst setzt. '.
                    'Was darunter erbt, liest dann den Wert der Seite.',
                    $selektor,
                    $gelesen,
                ),
            );
        }
    }

    /**
     * Was auf einer Markenfläche steht, liest nur Marken, die sie setzt — oder
     * solche, die in beiden Themen gleich sind.
     *
     * **Wie gefragt wird.** Die Seite wird ein Baum (`TemplateDom`), und unter
     * jeder Markenfläche werden die Vorlagen der eingesetzten Komponenten an
     * ihre Stelle gesetzt, die ihrer Kinder auch. Dann fragt jede Regel aus
     * `app.css` und aus den gescopten Blöcken der beteiligten Dateien
     * (`CssRules::question()`, übersetzt mit `symfony/css-selector`), welche
     * Elemente sie treffen **kann**; eine gescopte nur die ihrer eigenen
     * Vorlage. Liest eine Regel, die ein Element auf der Fläche trifft, eine
     * Marke, die das dunkle Thema anders setzt als das helle, und setzt die
     * Fläche sie nicht, ist das ein Befund: Im hellen Thema käme der Wert des
     * hellen Grundes auf den dunklen.
     *
     * **Was er nicht kann.** Eine Marke, die eine Regel unterhalb der Fläche
     * setzt, kennt er nicht; heute setzt nur `.branch` eine, und die wechselt
     * nicht mit dem Thema — käme eine dazu, meldete er zu viel und nicht zu
     * wenig. Eine Klasse, die aus einer Variablen kommt, steht nicht im Baum.
     * Und was er sagt, ist, welche Marke gelesen wird — nicht, ob der Wert
     * darauf lesbar ist; das rechnen die Fälle darüber.
     */
    public function test_what_stands_on_a_brand_surface_reads_its_own_marks(): void
    {
        $ergebnis = $this->readsOnSurfaces($this->stylesheet(), $this->vueSources());

        $this->assertGreaterThan(
            50,
            $ergebnis['pairs'],
            'Kaum eine Regel trifft ein Element auf einer Markenfläche — dann prüft dieser Test nichts.',
        );

        foreach (self::GESEHEN as $flaeche => $dateien) {
            foreach ($dateien as $datei) {
                $this->assertContains($datei, $ergebnis['visited'][$flaeche] ?? [], sprintf(
                    'Unter „%s" ist %s nicht mitgelesen worden. Dort stand der Befund vom 1. Oktober 2026 '.
                    '(docs/139 §8) — sieht dieser Wächter die Datei nicht, misst er nicht.',
                    $flaeche,
                    $datei,
                ));
            }
        }

        $this->assertSame([], $ergebnis['findings'], sprintf(
            "Auf einer Markenfläche liest etwas eine Marke, die je Thema wechselt und die die Fläche nicht setzt:\n  %s\n\n".
            'Im hellen Thema kommt dann der Wert für den hellen Grund auf den dunklen. Die Fläche setzt die Marke '.
            'selbst, mit einem Wert, der auf ihrem Grund lesbar ist — so wie `.rail` und `.signin` es für '.
            '--mark-accent tun (docs/139 §8).',
            implode("\n  ", $ergebnis['findings']),
        ));
    }

    /**
     * Der Befund vom 1. Oktober 2026, nachgestellt: Das Zeichen steht zwei
     * Komponenten tief unter der Leiste und liest eine Marke, die die Leiste
     * nicht setzt. Mit der Marke ist der Befund fort; eine Marke, die beide
     * Themen gleich setzen, ist nie einer.
     */
    public function test_a_mark_read_two_components_deep_is_seen(): void
    {
        $quellen = [
            'resources/js/Layouts/PanelLayout.vue' => "<script setup lang=\"ts\">\nimport BrandMark from '../Components/BrandMark.vue'\n</script>\n\n".
                "<template>\n  <aside class=\"rail\"><div class=\"row\"><BrandMark /></div></aside>\n</template>\n",
            'resources/js/Components/BrandMark.vue' => "<script setup lang=\"ts\">\nimport MarkIcon from './MarkIcon.vue'\n</script>\n\n".
                "<template>\n  <MarkIcon />\n  <span class=\"brand-name\">x</span>\n</template>\n",
            'resources/js/Components/MarkIcon.vue' => "<template>\n  <svg class=\"mark\"><rect class=\"mark-top\" /><rect class=\"mark-low\" /></svg>\n</template>\n\n".
                "<style scoped>\n.mark-top {\n  fill: var(--mark-accent);\n}\n\n.mark-low {\n  rx: var(--radius);\n}\n</style>\n",
        ];

        $ohne = $this->readsOnSurfaces($this->fixtureCss(''), $quellen);

        $this->assertCount(1, $ohne['findings'], implode("\n", $ohne['findings']));
        $this->assertStringContainsString('--mark-accent', $ohne['findings'][0]);
        $this->assertStringContainsString('MarkIcon.vue', $ohne['findings'][0]);
        $this->assertSame(
            ['resources/js/Components/BrandMark.vue', 'resources/js/Components/MarkIcon.vue'],
            $ohne['visited']['.rail'] ?? [],
        );

        $mit = $this->readsOnSurfaces($this->fixtureCss('--mark-accent: #ff7fec;'), $quellen);

        $this->assertSame([], $mit['findings']);
    }

    /**
     * Ein `<Link>` ist im Browser ein `<a>`, und die Regeln für `a` treffen
     * ihn. Als Komponente gelesen, träfe ihn keine.
     */
    public function test_a_link_counts_as_the_anchor_it_renders(): void
    {
        $quellen = [
            'resources/js/Layouts/PanelLayout.vue' => "<script setup lang=\"ts\">\nimport { Link } from '@inertiajs/vue3'\n</script>\n\n".
                "<template>\n  <aside class=\"rail\"><Link href=\"/\" class=\"nav-item\">x</Link></aside>\n</template>\n",
        ];

        $befunde = $this->readsOnSurfaces($this->fixtureCss('', 'a { color: var(--accent); }'), $quellen)['findings'];

        $this->assertCount(1, $befunde, implode("\n", $befunde));
        $this->assertStringContainsString('--accent', $befunde[0]);

        $this->assertSame([], $this->readsOnSurfaces(
            $this->fixtureCss('--accent: #ffb7a5;', 'a { color: var(--accent); }'),
            $quellen,
        )['findings']);
    }

    /**
     * Eine Komponente, die er nicht findet, ist ein Befund und keine Lücke:
     * Dort hätte er nicht gemessen.
     */
    public function test_a_component_it_cannot_read_is_a_finding(): void
    {
        $quellen = [
            'resources/js/Layouts/PanelLayout.vue' => "<script setup lang=\"ts\">\nimport Gone from '../Components/Gone.vue'\n</script>\n\n".
                "<template>\n  <aside class=\"rail\"><Gone /><Mystery /></aside>\n</template>\n",
        ];

        $befunde = $this->readsOnSurfaces($this->fixtureCss(''), $quellen)['findings'];

        $this->assertCount(2, $befunde, implode("\n", $befunde));
        $this->assertStringContainsString('resources/js/Components/Gone.vue', $befunde[0].$befunde[1]);
        $this->assertStringContainsString('<x-mystery>', $befunde[0].$befunde[1]);
    }

    /**
     * Rechnet eine Gruppe von Marken auf jeder Fläche, die sie setzt, gegen
     * deren Grund; gibt zurück, wie viele es waren.
     *
     * @param  list<string>  $rollen
     */
    private function reckon(array $rollen, float $mindestens, string $grundsatz): int
    {
        $geprueft = 0;

        foreach ($this->markenflaechen() as $selektor => $marken) {
            $grund = $this->ground($marken);

            if ($grund === null) {
                continue;
            }

            foreach ($rollen as $rolle) {
                if (! isset($marken[$rolle])) {
                    continue;
                }

                $geprueft++;
                $verhaeltnis = $this->contrast($marken[$rolle], $marken[$grund]);

                $this->assertGreaterThanOrEqual($mindestens, $verhaeltnis, sprintf(
                    '„%s": --%s (%s) erreicht auf --%s (%s) nur %.2f:1. %s',
                    $selektor,
                    $rolle,
                    $marken[$rolle],
                    $grund,
                    $marken[$grund],
                    $verhaeltnis,
                    $grundsatz,
                ));
            }
        }

        return $geprueft;
    }

    /**
     * Die Hexwerte jeder Markenfläche — das, was sich rechnen lässt.
     *
     * @return array<string, array<string, string>>
     */
    private function markenflaechen(): array
    {
        $aus = [];

        foreach ($this->surfaces($this->stylesheet()) as $selektor => $flaeche) {
            $aus[$selektor] = array_filter(
                $flaeche['marks'],
                static fn (string $wert): bool => preg_match('/^#[0-9a-fA-F]{6}$/', $wert) === 1,
            );
        }

        return $aus;
    }

    /**
     * Die Markenflächen: Regeln in app.css, die einen Grund **und** Schrift
     * setzen und dabei nicht `:root` sind — je Selektor, was er setzt, und sein
     * Rumpf.
     *
     * **Gelesen über `CssRules`, wie die Regeln, die auf den Flächen lesen.**
     * Bis zum 1. Oktober 2026 suchte hier ein eigener Ausdruck nach Blöcken am
     * Zeilenanfang; zwei Leser desselben Stylesheets wären zwei Antworten auf
     * die Frage, was eine Fläche ist. Eine Liste wie `.rail, .topbar` steht
     * damit als zwei Flächen da, und jede wird für sich gerechnet.
     *
     * @return array<string, array{marks: array<string, string>, body: string}>
     */
    private function surfaces(string $css): array
    {
        $flaechen = [];

        foreach (CssRules::flatten($css) as $regel) {
            if (str_starts_with($regel['selector'], ':root')) {
                continue;
            }

            preg_match_all('/(?<![\w-])--([\w-]+)\s*:\s*([^;]+);/', $regel['body'], $gesetzt, PREG_SET_ORDER);

            $marken = [];

            foreach ($gesetzt as $marke) {
                $marken[$marke[1]] = trim($marke[2]);
            }

            if (array_intersect(self::GRUENDE, array_keys($marken)) === []
                || array_intersect(self::SCHRIFT, array_keys($marken)) === []) {
                continue;
            }

            $bisher = $flaechen[$regel['selector']] ?? ['marks' => [], 'body' => ''];
            $flaechen[$regel['selector']] = [
                'marks' => array_merge($bisher['marks'], $marken),
                'body' => $bisher['body'].$regel['body'],
            ];
        }

        return $flaechen;
    }

    /**
     * Die Marken, die das dunkle Thema anders setzt als das helle — nur die
     * können auf einer dunklen Fläche den Wert eines hellen Grundes tragen.
     *
     * @return array<string, true>
     */
    private function themeMarks(string $css): array
    {
        $marken = [];

        foreach (CssRules::flatten($css) as $regel) {
            if ($regel['selector'] !== ":root[data-theme='dark']") {
                continue;
            }

            preg_match_all('/(?<![\w-])--([\w-]+)\s*:/', $regel['body'], $gesetzt);
            $marken += array_fill_keys($gesetzt[1], true);
        }

        return $marken;
    }

    /**
     * Jede Seite, auf der eine Markenfläche steht, als ein Baum mit den
     * Vorlagen ihrer Komponenten — und jede Regel gegen diesen Baum gefragt.
     *
     * @param  array<string, string>  $quellen  Pfad relativ zum Repo => Quelltext
     * @return array{findings: list<string>, visited: array<string, list<string>>, pairs: int}
     */
    private function readsOnSurfaces(string $css, array $quellen): array
    {
        $flaechen = $this->surfaces($css);
        $thema = $this->themeMarks($css);
        $umsetzer = new CssSelectorConverter(true);
        $ausdruecke = [];
        $befunde = [];
        $besucht = [];
        $paare = 0;

        $global = [];

        foreach (CssRules::flatten($css) as $regel) {
            $global[] = ['selector' => $regel['selector'], 'body' => $regel['body'], 'origin' => null];
        }

        ksort($quellen);

        foreach ($quellen as $pfad => $quelle) {
            $dom = TemplateDom::fromSource($quelle);
            $xpath = new DOMXPath($dom);
            $wurzeln = [];

            foreach ($flaechen as $selektor => $flaeche) {
                $teile = CssRules::subject($selektor);

                if ($teile === null || $teile['classes'] === []) {
                    continue;
                }

                $bedingung = implode(' and ', array_map(
                    static fn (string $klasse): string => sprintf("contains(concat(' ', normalize-space(@class), ' '), ' %s ')", $klasse),
                    $teile['classes'],
                ));

                foreach ($xpath->query('//*['.$bedingung.']') ?: [] as $wurzel) {
                    if ($wurzel instanceof DOMElement) {
                        $wurzeln[] = [$wurzel, $selektor];
                    }
                }
            }

            if ($wurzeln === []) {
                continue;
            }

            $this->markOrigin($dom, $pfad);
            $html = $dom->documentElement;

            if ($html instanceof DOMElement) {
                // Was `app.blade.php` am `<html>` setzt; eine Regel unter
                // `:root[data-theme='dark']` fragt danach.
                $html->setAttribute('data-theme', '');
                $html->setAttribute('data-density', '');
            }

            $dateien = [$pfad => true];

            foreach ($wurzeln as [$wurzel, $selektor]) {
                $wurzel->setAttribute('data-surface', $selektor);
                $hier = [];
                $this->insertChildren($wurzel, $pfad, $quellen, [$pfad], $befunde, $hier);
                $dateien += $hier;
                $besucht[$selektor] = array_values(array_unique([...($besucht[$selektor] ?? []), ...array_keys($hier)]));
                sort($besucht[$selektor]);
            }

            $regeln = $global;

            foreach (array_keys($dateien) as $datei) {
                foreach (CssRules::flatten(CssRules::scoped($quellen[$datei])) as $regel) {
                    // `:deep()`, `:global()` und `:slotted()` zielen mit Absicht
                    // über die eigene Vorlage hinaus; dann zählt jedes Element.
                    $gebunden = preg_match('/:(?:deep|global|slotted)\(/', $regel['selector']) !== 1;
                    $ausgepackt = (string) preg_replace('/:(?:deep|global|slotted)\(((?:[^()]|\([^()]*\))*)\)/', ' $1', $regel['selector']);
                    $regeln[] = ['selector' => trim($ausgepackt), 'body' => $regel['body'], 'origin' => $gebunden ? $datei : null];
                }
            }

            $xpath = new DOMXPath($dom);

            foreach ($regeln as $regel) {
                preg_match_all('/var\(\s*--([\w-]+)/', $regel['body'], $gelesen);

                if ($gelesen[1] === []) {
                    continue;
                }

                $frage = CssRules::question($regel['selector']);

                if ($frage === '') {
                    continue;
                }

                try {
                    $ausdruecke[$frage] ??= $umsetzer->toXPath($frage);
                } catch (Throwable $fehler) {
                    $befunde[sprintf('„%s" ist nicht übersetzbar (%s) — dort hat dieser Wächter nicht gemessen.', $regel['selector'], $fehler->getMessage())] = true;

                    continue;
                }

                foreach ($xpath->query($ausdruecke[$frage]) ?: [] as $treffer) {
                    if (! $treffer instanceof DOMElement) {
                        continue;
                    }

                    if ($regel['origin'] !== null && $treffer->getAttribute('data-origin') !== $regel['origin']) {
                        continue;
                    }

                    $oben = $xpath->query('ancestor-or-self::*[@data-surface][1]', $treffer);
                    $flaeche = $oben === false ? null : $oben->item(0);

                    if (! $flaeche instanceof DOMElement) {
                        continue;
                    }

                    $auf = $flaeche->getAttribute('data-surface');
                    $paare++;

                    foreach (array_unique($gelesen[1]) as $marke) {
                        if (isset($thema[$marke]) && ! isset($flaechen[$auf]['marks'][$marke])) {
                            $befunde[sprintf(
                                '%s: „%s" liest --%s, an <%s> aus %s',
                                $auf,
                                $regel['selector'],
                                $marke,
                                $treffer->tagName,
                                $treffer->getAttribute('data-origin'),
                            )] = true;
                        }
                    }
                }
            }
        }

        ksort($besucht);

        return ['findings' => array_keys($befunde), 'visited' => $besucht, 'pairs' => $paare];
    }

    /**
     * Setzt unter `$knoten` die Vorlagen der eingesetzten Komponenten ein, an
     * der Stelle, an der sie stehen, mitsamt deren Kindern. Eine Datei, die
     * schon auf dem Weg hierher liegt, wird nicht noch einmal eingesetzt.
     *
     * @param  array<string, string>  $quellen
     * @param  list<string>  $weg
     * @param  array<string, true>  $befunde
     * @param  array<string, true>  $besucht
     */
    private function insertChildren(DOMElement $knoten, string $pfad, array $quellen, array $weg, array &$befunde, array &$besucht): void
    {
        $importe = $this->imports($quellen[$pfad], $pfad);
        $komponenten = [];

        foreach ([$knoten, ...iterator_to_array($knoten->getElementsByTagName('*'))] as $element) {
            if ($element instanceof DOMElement && str_starts_with($element->tagName, 'x-')) {
                $komponenten[] = $element;
            }
        }

        foreach ($komponenten as $komponente) {
            $name = $komponente->tagName;

            if (array_key_exists($name, self::FREMD)) {
                if (self::FREMD[$name] !== null) {
                    $this->rename($komponente, self::FREMD[$name]);
                }

                continue;
            }

            $ziel = $importe[$name] ?? null;

            if ($ziel === null) {
                $befunde[sprintf('%s: <%s> ist keine Komponente, die dieser Wächter kennt — darunter hat er nicht gemessen.', $pfad, $name)] = true;

                continue;
            }

            if (! isset($quellen[$ziel])) {
                $befunde[sprintf('%s: %s ist nicht auffindbar — darunter hat dieser Wächter nicht gemessen.', $pfad, $ziel)] = true;

                continue;
            }

            if (in_array($ziel, $weg, true)) {
                continue;
            }

            $besucht[$ziel] = true;
            $kind = TemplateDom::fromSource($quellen[$ziel]);
            $this->markOrigin($kind, $ziel);
            $koerper = $kind->getElementsByTagName('body')->item(0);
            $dokument = $komponente->ownerDocument;

            if ($koerper === null || $dokument === null) {
                continue;
            }

            foreach (iterator_to_array($koerper->childNodes) as $wurzel) {
                if (! $wurzel instanceof DOMElement) {
                    continue;
                }

                $this->insertChildren($wurzel, $ziel, $quellen, [...$weg, $ziel], $befunde, $besucht);
                $komponente->appendChild($dokument->importNode($wurzel, true));
            }
        }
    }

    /** Jedes Element eines Baums trägt die Datei, aus deren Vorlage es kommt. */
    private function markOrigin(DOMDocument $dom, string $pfad): void
    {
        $koerper = $dom->getElementsByTagName('body')->item(0);

        if ($koerper === null) {
            return;
        }

        foreach ($koerper->getElementsByTagName('*') as $element) {
            $element->setAttribute('data-origin', $pfad);
        }
    }

    /** Ersetzt ein Element durch eines mit anderem Namen und demselben Inhalt. */
    private function rename(DOMElement $element, string $name): void
    {
        $dokument = $element->ownerDocument;
        $eltern = $element->parentNode;

        if ($dokument === null || $eltern === null) {
            return;
        }

        $neu = $dokument->createElement($name);

        foreach (iterator_to_array($element->attributes ?? []) as $attribut) {
            $neu->setAttribute($attribut->nodeName, (string) $attribut->nodeValue);
        }

        while ($element->firstChild !== null) {
            $neu->appendChild($element->firstChild);
        }

        $eltern->replaceChild($neu, $element);
    }

    /**
     * Die eingebundenen `.vue` einer Datei: Name im Baum => Pfad relativ zum
     * Repo.
     *
     * @return array<string, string>
     */
    private function imports(string $quelle, string $pfad): array
    {
        preg_match_all('/^\s*import\s+(\w+)\s+from\s+[\'"]([^\'"]+\.vue)[\'"]/m', $quelle, $importe, PREG_SET_ORDER);

        $aus = [];

        foreach ($importe as $import) {
            $aus[TemplateDom::component($import[1])] = $this->normalise(dirname($pfad).'/'.$import[2]);
        }

        return $aus;
    }

    /** Ein Pfad ohne `.` und `..` — ohne die Platte zu fragen, damit es auch für nachgestellte Dateien geht. */
    private function normalise(string $pfad): string
    {
        $teile = [];

        foreach (explode('/', $pfad) as $teil) {
            if ($teil === '' || $teil === '.') {
                continue;
            }

            if ($teil === '..') {
                array_pop($teile);

                continue;
            }

            $teile[] = $teil;
        }

        return implode('/', $teile);
    }

    /**
     * Ein kleines Stylesheet mit der Leiste und zwei Themen, für die
     * nachgestellten Fälle.
     */
    private function fixtureCss(string $leisteDazu, string $regelnDazu = ''): string
    {
        return <<<CSS
            :root,
            :root[data-theme='light'] {
              --text: #3a3f49;
              --accent: #3730a3;
              --mark-accent: #3730a3;
              --radius: 5px;
            }

            :root[data-theme='dark'] {
              --text: #d9d2e6;
              --accent: #ffb7a5;
              --mark-accent: #ff7fec;
            }

            .rail {
              --nav-bg: #1a0b2e;
              --text: #d9d2e6;
              {$leisteDazu}
              color: var(--text);
            }

            {$regelnDazu}
            CSS;
    }

    /** @return array<string, string> */
    private function vueSources(): array
    {
        $quellen = [];
        $baum = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root().'/resources/js'));

        foreach ($baum as $datei) {
            if ($datei instanceof SplFileInfo && $datei->isFile() && $datei->getExtension() === 'vue') {
                $quellen[ltrim(substr($datei->getPathname(), strlen($this->root())), '/')] = (string) file_get_contents($datei->getPathname());
            }
        }

        return $quellen;
    }

    /** @param array<string, string> $marken */
    private function ground(array $marken): ?string
    {
        foreach (self::GRUENDE as $name) {
            if (isset($marken[$name])) {
                return $name;
            }
        }

        return null;
    }

    private function stylesheet(): string
    {
        return (string) file_get_contents($this->root().'/resources/css/app.css');
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Die Rechnung steht seit B6 in {@see Contrast} und nicht mehr hier.
     *
     * **Sie stand in drei Wächtern gleich.** Das ging, solange nur Wächter sie
     * brauchten; seit der Betreiber eine Farbe vorgeben darf, rechnet auch die
     * Anwendung — und eine vierte Kopie wäre der Fehler, gegen den dieses Repo
     * seine Wächter baut.
     */
    private function contrast(string $a, string $b): float
    {
        return Contrast::between($a, $b);
    }
}
