<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Die Regeln eines Stylesheets, flach und ohne Kommentare.
 *
 * `@media` und `@supports` lösen sich auf, ihre Regeln zählen wie alle anderen;
 * `@keyframes` fällt weg, denn `from` und `50%` sind keine Selektoren. Eine
 * Selektorliste wird an den Kommas der obersten Ebene geteilt, damit
 * `:not(.a, .b)` ganz bleibt.
 *
 * Gebraucht von `ScopedReachTest` und `SurfaceTokenTest`, die beide fragen,
 * welche Regel welches Element trifft.
 */
final class CssRules
{
    /**
     * @return list<array{selector: string, body: string}>
     */
    public static function flatten(string $css): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $css = (string) preg_replace('/@keyframes[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/s', '', $css);

        while (preg_match('/@(?:media|supports)[^{]*\{((?:[^{}]*\{[^{}]*\})*[^{}]*)\}/s', $css, $block, PREG_OFFSET_CAPTURE) === 1) {
            $css = substr_replace($css, $block[1][0], $block[0][1], strlen($block[0][0]));
        }

        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $regeln, PREG_SET_ORDER);

        $aus = [];

        foreach ($regeln as $regel) {
            foreach (self::split(trim($regel[1])) as $selektor) {
                $selektor = trim((string) preg_replace('/\s+/', ' ', $selektor));

                if ($selektor !== '' && ! str_starts_with($selektor, '@')) {
                    $aus[] = ['selector' => $selektor, 'body' => $regel[2]];
                }
            }
        }

        return $aus;
    }

    /** Was in den `<style scoped>`-Blöcken einer SFC steht, zusammengehängt. */
    public static function scoped(string $source): string
    {
        preg_match_all('#^<style\b([^>]*)>(.*?)^</style>#ms', $source, $bloecke, PREG_SET_ORDER);

        $css = '';

        foreach ($bloecke as $block) {
            if (preg_match('/\bscoped\b/', $block[1]) === 1) {
                $css .= $block[2]."\n";
            }
        }

        return $css;
    }

    /**
     * Ein Selektor als Frage nach einem Element, das ihn tragen **kann**.
     *
     * Was erst die Laufzeit entscheidet, fällt weg: Zustände wie `:hover` und
     * `:disabled`, Pseudoelemente, `:has()` und `:not()`, der Wert eines
     * Attributs (gefragt wird, ob es da ist) und die Stellung in einer Liste —
     * eine Vorlage zeigt ein `v-for` einmal, zur Laufzeit stehen dort viele,
     * und `:first-child` hiesse sonst „genau dieses eine". Die Antwort trifft
     * dasselbe wie im Browser oder mehr, aber nie weniger.
     *
     * Bleibt von einem Glied nichts übrig, steht dort `*`: Aus `.pager >
     * :first-child` wird `.pager > *` und nicht `.pager >`, und aus `.a >
     * :first-child .b` nicht `.a > .b`, das etwas anderes fragt.
     *
     * Gebraucht von `ScopedReachTest` und `SurfaceTokenTest`; beide übersetzen
     * die Frage mit `symfony/css-selector` in einen XPath-Ausdruck.
     */
    public static function question(string $selector): string
    {
        $weg = "\x00";
        $frage = (string) preg_replace('/::?(?:before|after|placeholder|marker|selection|backdrop|-webkit-[\w-]+|-moz-[\w-]+)\b/', $weg, $selector);
        $frage = (string) preg_replace(
            '/:(?:hover|focus-visible|focus-within|focus|active|visited|link|disabled|enabled|checked|indeterminate|'.
            'placeholder-shown|invalid|valid|required|optional|read-only|read-write|target|default|autofill|user-invalid|open|empty)\b/',
            $weg,
            $frage,
        );
        $frage = (string) preg_replace('/\[([\w-]+)\s*[~|^$*]?=\s*(?:"[^"]*"|\'[^\']*\'|[^\]]*)\]/', '[$1]', $frage);
        $frage = (string) preg_replace('/:(?:has|not)\((?:[^()]|\([^()]*\))*\)/', $weg, $frage);
        $frage = (string) preg_replace('/:(?:first|last|only)-(?:of-type|child)\b|:nth(?:-last)?-(?:of-type|child)\([^)]*\)/', $weg, $frage);
        $frage = (string) preg_replace('/(^|[\s>+~(,])\x00+/', '$1*', $frage);

        return trim(str_replace($weg, '', $frage));
    }

    /**
     * Das, worauf ein Selektor zielt: Tag und Klassen seines letzten Gliedes.
     *
     * Pseudoklassen, Pseudoelemente und Attribute fallen weg — gefragt wird,
     * welches Element die Regel treffen **kann**. `null`, wenn das letzte Glied
     * weder Tag noch Klasse nennt; über `*` oder `[open]` allein lässt sich
     * nichts sagen.
     *
     * @return array{tag: string, classes: list<string>}|null
     */
    public static function subject(string $selector): ?array
    {
        $ohne = (string) preg_replace('/::?[\w-]+(\((?:[^()]|\([^()]*\))*\))?/', '', $selector);
        $ohne = (string) preg_replace('/\[[^\]]*\]/', '', $ohne);
        $glieder = preg_split('/\s*[>+~]\s*|\s+/', trim($ohne)) ?: [];
        $letztes = (string) end($glieder);

        if (preg_match('/^([a-z][\w-]*|\*)?((?:\.[\w-]+)*)$/i', $letztes, $teile) !== 1) {
            return null;
        }

        $tag = strtolower($teile[1]);
        $klassen = array_values(array_filter(explode('.', $teile[2]), static fn (string $k): bool => $k !== ''));

        if ($tag === '*') {
            $tag = '';
        }

        if ($tag === '' && $klassen === []) {
            return null;
        }

        return ['tag' => $tag, 'classes' => $klassen];
    }

    /** @return list<string> */
    private static function split(string $liste): array
    {
        $teile = [];
        $tiefe = 0;
        $aktuell = '';

        foreach (mb_str_split($liste) as $zeichen) {
            if ($zeichen === '(') {
                $tiefe++;
            } elseif ($zeichen === ')') {
                $tiefe--;
            } elseif ($zeichen === ',' && $tiefe === 0) {
                $teile[] = $aktuell;
                $aktuell = '';

                continue;
            }

            $aktuell .= $zeichen;
        }

        $teile[] = $aktuell;

        return $teile;
    }
}
