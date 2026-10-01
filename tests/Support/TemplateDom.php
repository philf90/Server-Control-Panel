<?php

declare(strict_types=1);

namespace Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Der Vorlagenblock einer `.vue` als DOM — so, dass ein CSS-Selektor darauf
 * dasselbe trifft wie im Browser, oder mehr, aber nie weniger.
 *
 * **Warum es das gibt.** `ScopedReachTest` und `SurfaceTokenTest` fragen, ob
 * eine Regel ein Element **dieser** Vorlage trifft und was darunter steht. Das
 * beantwortet kein Ausdruck über Zeichenketten; ein Selektor wie `.row b`
 * braucht den Baum.
 *
 * **Fünf Handgriffe, vier davon an einer Messung bezahlt** (1. Oktober 2026):
 *
 * - **Komponenten heissen `x-…`.** `<Link>` wäre für den HTML-Parser das leere
 *   Element `<link>`, und seine Kinder rückten zu Geschwistern auf. So meldete
 *   der erste Wurf `.nav-item .badge` in `PanelLayout` als tot.
 * - **Selbstschliessende Tags werden ausgeschrieben, und das ist Vorsicht.**
 *   Hier stand zuerst, der Parser kenne `/>` nur an leeren Elementen und ein
 *   `<BrandMark />` schlucke sonst alles danach. Gemessen mit libxml 2.9.14,
 *   der Fassung in diesem Container, schliesst er `<x-brandmark />` und
 *   sogar `<div />` selbst; gefunden hat es ein Eingriff, der nicht biss. Ein
 *   Parser, der `/>` nach HTML5 liest, täte das nicht — gemessen ist das
 *   nicht.
 * - **Gebundene Klassen zählen als mögliche Klassen.** Aus
 *   `:class="{ open: menuOpen }"` wird `open`, aus `['a', b ? 'c' : '']` werden
 *   `a` und `c`. Der Baum trägt damit jede Klasse, die das Element haben
 *   **kann** — eine Regel, die nur in einem Zustand greift, ist nicht tot.
 * - **Gebundene Attribute stehen ohne Wert da.** Welchen Wert `:aria-current`
 *   hat, weiss erst die Laufzeit; wer fragt, fragt deshalb nach dem
 *   Vorhandensein.
 * - **`<template>` ist ein Fragment und kein Element.** Seine Kinder rücken an
 *   seine Stelle, sonst hinge ein `>` an einem Kasten, den es nicht gibt.
 *
 * **Und `{{ … }}` fällt vorher weg, gemessen:** `{{ n<max ? 1 : 2 }}` öffnet
 * für den Parser ein Element `<max>`, und das verschluckt alles, was danach
 * kommt. Kommentare fallen ebenfalls weg, und das ist Vorsicht und keine
 * Messung — libxml lässt einen Kommentar, der ein Tag zitiert, als Kommentar
 * stehen; die Schritte oben laufen aber über rohen Text, und ein Kommentar
 * trägt Zeichen, die sie für Teile eines Tags halten könnten.
 */
final class TemplateDom
{
    /** Der Name, unter dem eine Komponente im Baum steht. */
    public static function component(string $name): string
    {
        return 'x-'.strtolower($name);
    }

    public static function fromSource(string $source): DOMDocument
    {
        return self::parse(self::template($source));
    }

    /**
     * Der Vorlagenblock einer SFC: vom `<template>` am Zeilenanfang bis zum
     * letzten `</template>` am Zeilenanfang.
     *
     * Am Zeilenanfang, weil ein Kommentar im Skriptblock ein `<template v-if>`
     * zitieren darf; die Blöcke selbst stehen ganz links (`SfcBlockTest`).
     */
    public static function template(string $source): string
    {
        if (preg_match('/^<template\b[^>]*>/m', $source, $anfang, PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }

        $ende = strrpos($source, "\n</template>");

        if ($ende === false || $ende < $anfang[0][1]) {
            return '';
        }

        return substr($source, $anfang[0][1], $ende + strlen("\n</template>") - $anfang[0][1]);
    }

    public static function parse(string $template): DOMDocument
    {
        $html = (string) preg_replace('/<!--.*?-->/s', '', $template);
        $html = (string) preg_replace('/\{\{.*?\}\}/s', 'x', $html);
        $html = (string) preg_replace('#<(/?)([A-Z]\w*)#', '<$1x-$2', $html);
        $html = (string) preg_replace_callback(
            '#<([A-Za-z][\w.-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*?)/>#s',
            static fn (array $m): string => "<{$m[1]}{$m[2]}></{$m[1]}>",
            $html,
        );
        $html = (string) preg_replace_callback(
            '#<([A-Za-z][\w.-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#s',
            static fn (array $m): string => '<'.$m[1].self::attributes($m[2]).'>',
            $html,
        );

        $dom = new DOMDocument;
        $vorher = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><html><body>'.$html.'</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($vorher);

        $xpath = new DOMXPath($dom);

        foreach (iterator_to_array($xpath->query('//template') ?: []) as $fragment) {
            if (! $fragment instanceof DOMElement || $fragment->parentNode === null) {
                continue;
            }

            while ($fragment->firstChild !== null) {
                $fragment->parentNode->insertBefore($fragment->firstChild, $fragment);
            }

            $fragment->parentNode->removeChild($fragment);
        }

        return $dom;
    }

    /** Die Attribute eines Tags, mit gebundenen Klassen als möglichen Klassen. */
    private static function attributes(string $attribute): string
    {
        $klassen = [];

        if (preg_match('/\sclass="([^"]*)"/', $attribute, $statisch) === 1) {
            $klassen = preg_split('/\s+/', trim($statisch[1])) ?: [];
        }

        if (preg_match('/\s(?::|v-bind:)class="([^"]*)"/', $attribute, $gebunden) === 1) {
            // Wörter in Anführungszeichen (`['a', b ? 'c' : '']`) und Schlüssel
            // eines Objekts (`{ open: menuOpen }`).
            preg_match_all("/'([\\w-]+)'/", $gebunden[1], $woerter);
            preg_match_all('/\b([\w-]+)\s*:/', $gebunden[1], $schluessel);
            $klassen = [...$klassen, ...$woerter[1], ...$schluessel[1]];
        }

        $attribute = (string) preg_replace('/\s(?::|v-bind:)?class="[^"]*"/', '', $attribute);
        $attribute = (string) preg_replace('/\s(?::|v-bind:)([\w-]+)="[^"]*"/', ' $1=""', $attribute);
        $attribute = (string) preg_replace('/\s[@#][\w.:-]+(?:="[^"]*")?/', '', $attribute);
        $attribute = (string) preg_replace('/\sv-[\w.:-]+(?:="[^"]*")?/', '', $attribute);

        $klassen = array_values(array_unique(array_filter($klassen, static fn (string $k): bool => $k !== '')));

        return $attribute.($klassen === [] ? '' : ' class="'.implode(' ', $klassen).'"');
    }
}
