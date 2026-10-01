<?php

declare(strict_types=1);

namespace Tests\Unit;

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
 * Eine gescopte Regel trifft ein Element ihrer eigenen Vorlage.
 *
 * **Der Befund, für den es diesen Wächter gibt** (`docs/139 §8`). Am
 * 21. September 2026 zog der Name neben dem Zeichen aus `PanelLayout` in
 * `BrandMark.vue` (B6). Seine Gestalt stand weiter als `.row b` im gescopten
 * Block von `PanelLayout` und traf nichts mehr: Vue übersetzt die Regel zu
 * `.row b[data-v-…]`, und das Attribut tragen nur die Elemente der eigenen
 * Vorlage und die **eine** Wurzel einer eingesetzten Komponente. `BrandMark`
 * hat zwei. Der Name erbte zehn Tage lang die Schrift der Seite, im hellen
 * Thema 1,76:1 auf der Leiste, und zwei Bilderrunden lang hat es niemand
 * gesehen.
 *
 * > **Eine gescopte Regel, deren Ziel in eine Kindkomponente umzieht, ist tot —
 * > und sieht im Quelltext aus wie Gestaltung.**
 *
 * `ClassReachTest` fragt die Gegenrichtung, ob jede Klasse einer Vorlage eine
 * Regel hat, und konnte es nicht sehen: `.row` stand weiter in der Vorlage,
 * und nach einem `b` fragt er nicht.
 *
 * **Wie gefragt wird.** Die Vorlage wird ein DOM (`TemplateDom`), der Selektor
 * über `symfony/css-selector` ein XPath-Ausdruck; das Paket kommt mit Laravel,
 * über dessen Mail. Was erst die Laufzeit entscheidet — Zustände,
 * Pseudoelemente, `:has()`, `:not()`, der Wert eines Attributs und die
 * Stellung in einer Liste —, fällt vorher weg (`CssRules::question()`).
 * Gefragt ist, ob die Regel ein Element treffen **kann**. `:deep()`,
 * `:global()` und `:slotted()` zielen mit Absicht über die eigene Vorlage
 * hinaus und bleiben ungeprüft.
 *
 * **Ein Selektor, den der Wächter nicht übersetzen kann, ist ein Befund** und
 * keine Ausnahme: Er hat an dieser Stelle nicht gemessen.
 *
 * **Was er nicht kann.** Eine Regel, die die Wurzel einer eingesetzten
 * Komponente über deren eigene Klasse trifft, hält er für tot, obwohl Vue sie
 * anwendet; heute gibt es keine. Und ob ein `:deep()` sein Ziel noch trifft,
 * sagt er nicht.
 */
final class ScopedReachTest extends TestCase
{
    public function test_every_scoped_rule_reaches_an_element_of_its_template(): void
    {
        $geprueft = 0;
        $tot = [];

        foreach ($this->vueFiles() as $pfad) {
            $quelle = (string) file_get_contents($pfad);
            $css = CssRules::scoped($quelle);

            if ($css === '') {
                continue;
            }

            [$anzahl, $ohneZiel] = $this->reach(TemplateDom::template($quelle), $css);
            $geprueft += $anzahl;

            foreach ($ohneZiel as $selektor) {
                $tot[] = sprintf('%s: %s', $this->relative($pfad), $selektor);
            }
        }

        $this->assertGreaterThan(
            50,
            $geprueft,
            'Es werden kaum gescopte Regeln gefunden — dann prüft dieser Test nichts.',
        );

        $this->assertSame([], $tot, sprintf(
            "Diese gescopten Regeln treffen kein Element ihrer Vorlage:\n  %s\n\n".
            'Eine gescopte Regel gilt für die Elemente der eigenen Vorlage und für die eine Wurzel '.
            'einer eingesetzten Komponente. Steht ihr Ziel jetzt in einer Kindkomponente, gehört die '.
            'Regel dorthin oder nach resources/css/app.css; greift sie mit Absicht hinein, schreibt '.
            'sie :deep(). Sonst ist sie ein Rest und gehört entfernt (docs/139 §8).',
            implode("\n  ", $tot),
        ));
    }

    /**
     * Der Befund vom 1. Oktober 2026, nachgestellt: Der Name steht in einer
     * Kindkomponente, die Regel im Elternteil.
     */
    public function test_a_rule_whose_target_moved_into_a_child_is_dead(): void
    {
        [$anzahl, $tot] = $this->reach(
            '<template><div class="row"><BrandMark :size="24" /><span class="version">x</span></div></template>',
            '.row b { color: red } .row .version { flex: none }',
        );

        $this->assertSame(2, $anzahl);
        $this->assertSame(['.row b'], $tot);
    }

    public function test_a_rule_inside_a_media_query_is_read(): void
    {
        [$anzahl, $tot] = $this->reach(
            '<template><p class="a">x</p></template>',
            '@media (max-width: 720px) { .a { color: red } .b { color: blue } }',
        );

        $this->assertSame(2, $anzahl);
        $this->assertSame(['.b'], $tot);
    }

    /** Eine Klasse, die nur gebunden ist, kann das Element tragen — die Regel lebt. */
    public function test_a_class_that_is_only_bound_counts(): void
    {
        [$anzahl, $tot] = $this->reach(
            '<template><aside class="rail" :class="{ open: menuOpen }">'.
            '<span :class="[\'dot\', on ? \'live\' : \'\']">x</span></aside></template>',
            '.rail.open { left: 0 } .rail .dot.live { color: red }',
        );

        $this->assertSame(2, $anzahl);
        $this->assertSame([], $tot);
    }

    /**
     * `<Link>` hiesse für den HTML-Parser `<link>`, ein leeres Element, und
     * seine Kinder rückten zu Geschwistern auf. So meldete der erste Wurf
     * `.nav-item .badge` in `PanelLayout` als tot.
     */
    public function test_a_component_named_like_an_empty_element_keeps_its_children(): void
    {
        [$anzahl, $tot] = $this->reach(
            '<template><Link href="/" class="nav-item"><span class="badge">1</span></Link></template>',
            '.nav-item .badge { margin: 0 }',
        );

        $this->assertSame(1, $anzahl);
        $this->assertSame([], $tot);
    }

    /** Ein `<BrandMark />` schluckt nicht, was nach ihm kommt. */
    public function test_a_self_closing_component_keeps_its_siblings(): void
    {
        [$anzahl, $tot] = $this->reach(
            '<template><div class="row"><BrandMark /><span class="version">x</span></div><p class="next">y</p></template>',
            '.row > .version { flex: none } .row + .next { margin: 0 }',
        );

        $this->assertSame(2, $anzahl);
        $this->assertSame([], $tot);
    }

    /**
     * `<template>` ist ein Fragment und kein Kasten: Ein `v-for` darauf setzt
     * seine Kinder unmittelbar in das Element darüber.
     */
    public function test_a_template_is_a_fragment_and_not_a_box(): void
    {
        [$anzahl, $tot] = $this->reach(
            '<template><ul class="list"><template v-for="x in xs"><li class="item">x</li></template></ul></template>',
            '.list > .item { margin: 0 }',
        );

        $this->assertSame(1, $anzahl);
        $this->assertSame([], $tot);
    }

    /**
     * Ein Vergleich in einem Ausdruck öffnet kein Tag. Ohne den Handgriff wird
     * aus `n<max` ein Element `<max>`, und es verschluckt die nächste Zeile.
     */
    public function test_a_comparison_in_an_expression_opens_no_tag(): void
    {
        [$anzahl, $tot] = $this->reach(
            "<template><p class=\"count\">{{ n<max ? 'x' : 'y' }}</p><p class=\"note\">z</p></template>",
            '.count + .note { margin: 0 }',
        );

        $this->assertSame(1, $anzahl);
        $this->assertSame([], $tot);
    }

    /**
     * Zustände und gebundene Attribute zählen als möglich; `:deep()` und
     * `:global()` bleiben ungeprüft.
     */
    public function test_states_and_bound_attributes_count_as_possible(): void
    {
        [$anzahl, $tot] = $this->reach(
            '<template><button class="b" :aria-pressed="on">x</button></template>',
            '.b:hover { } .b:disabled::after { } .b[aria-pressed="true"] { } '.
            ':deep(.inner) { } :global(html.open) .b { }',
        );

        $this->assertSame(3, $anzahl);
        $this->assertSame([], $tot);
    }

    /**
     * Die Stellung in einer Liste und ein `:not()` entscheidet die Laufzeit.
     *
     * Eine Vorlage zeigt das Element eines `v-for` einmal, und eine gebundene
     * Klasse steht im Baum immer da. `.page:not(.active)` träfe so nie und
     * `:nth-child(2n)` auch nicht — beide treffen im Browser, sobald die Liste
     * zwei Einträge hat und einer nicht aktiv ist.
     */
    public function test_the_place_in_a_list_and_a_negation_count_as_possible(): void
    {
        [$anzahl, $tot] = $this->reach(
            '<template><nav class="pager"><a v-for="s in seiten" class="page" :class="{ active: s.on }">x</a></nav></template>',
            '.pager > :first-child { } .page:not(.active) { } .pager .page:nth-child(2n) { }',
        );

        $this->assertSame(3, $anzahl);
        $this->assertSame([], $tot);
    }

    /**
     * Wie viele Selektoren gefragt wurden, und welche ohne Ziel blieben.
     *
     * @return array{0: int, 1: list<string>}
     */
    private function reach(string $vorlage, string $css): array
    {
        $xpath = new DOMXPath(TemplateDom::parse($vorlage));
        $umsetzer = new CssSelectorConverter(true);
        $geprueft = 0;
        $tot = [];

        foreach (CssRules::flatten($css) as $regel) {
            $selektor = $regel['selector'];

            if (preg_match('/:deep\(|:global\(|:slotted\(|::v-deep|>>>/', $selektor) === 1) {
                continue;
            }

            $frage = CssRules::question($selektor);

            if ($frage === '') {
                continue;
            }

            $geprueft++;

            try {
                $ausdruck = $umsetzer->toXPath($frage);
            } catch (Throwable $fehler) {
                $tot[] = sprintf('%s (nicht übersetzbar: %s)', $selektor, $fehler->getMessage());

                continue;
            }

            $treffer = $xpath->query($ausdruck);

            if ($treffer === false || $treffer->length === 0) {
                $tot[] = $selektor;
            }
        }

        return [$geprueft, $tot];
    }

    /** @return list<string> */
    private function vueFiles(): array
    {
        $dateien = [];
        $baum = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root().'/resources/js'));

        foreach ($baum as $datei) {
            if ($datei instanceof SplFileInfo && $datei->isFile() && $datei->getExtension() === 'vue') {
                $dateien[] = $datei->getPathname();
            }
        }

        sort($dateien);

        return $dateien;
    }

    private function relative(string $pfad): string
    {
        return ltrim(substr($pfad, strlen($this->root())), '/');
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
