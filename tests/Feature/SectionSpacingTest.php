<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Ein Bereich steht in einem Behälter, der den Abstand trägt.
 *
 * **Der Anlass, 7. August 2026.** Auf „DNS-Zugang" berührte der letzte Hinweis
 * des Bereichs „Hinterlegt" die Überschrift „Neu hinterlegen" — 0px dazwischen,
 * im Browser gemessen. Gemeldet hat es der Betreiber, kein Lauf.
 *
 * Die Ursache ist keine fehlende Regel in app.css, sondern eine fehlende
 * Klammer im Template. In Kontor hat ein Bereich **keinen eigenen Aussenabstand**
 * — und das ist Absicht: Bereiche stehen in einem Flexfluss, der sie
 * nebeneinander stellt, solange sie nebeneinander passen, und untereinander,
 * sobald nicht. Wer den Abstand an den Bereich hängte, hätte ihn waagerecht
 * wie senkrecht, und die Spaltenlücke unterscheidet sich von der Zeilenlücke
 * (`--bereich-gap: 30px 44px`). Deshalb kommt er aus dem `gap` des Behälters:
 * `.sections` auf der Seite, `.form` um ein Formular.
 *
 * Ein Bereich ohne diesen Behälter bekommt also **gar keinen** Abstand. Er
 * sieht nicht knapp aus, sondern kaputt — und nichts sagt etwas, weil jede
 * einzelne Regel für sich stimmt.
 *
 * **Und die Falle liegt eine Ebene höher.** `DnsCredentials` bringt zwei
 * Bereiche mit und keinen Behälter; wer die Komponente einsetzt, stellt ihn.
 * Am Abonnement stand sie von Anfang an richtig, auf der Seite des Betreibers
 * nicht — dieselbe Komponente, zwei Orte, ein Ort falsch. Solche Komponenten
 * sucht dieser Test selbst zusammen, statt sie aufzuzählen: Wer morgen eine
 * zweite baut, wird ohne Zutun mitgeprüft.
 *
 * Wieder dasselbe Muster wie überall hier — etwas verweist auf etwas anderes
 * (ein Bereich auf den Abstand seines Behälters), und niemand prüft den Bezug.
 */
final class SectionSpacingTest extends TestCase
{
    /**
     * Die Klassen, deren Regel in app.css ein `gap` aus `--bereich-gap` setzt.
     *
     * Sie stehen hier als Liste und werden unten gegen app.css geprüft: Eine
     * Klasse, die den Abstand einmal getragen hat und ihn verliert, wäre sonst
     * ein Behälter, der keiner mehr ist — und dieser Test bliebe grün.
     */
    private const CONTAINERS = ['sections', 'form'];

    /**
     * Elemente ohne Inhalt. Sie kommen nie auf den Stapel.
     *
     * Ohne diese Liste bleibt jedes `<input v-model="…">` offen, und alles
     * Folgende gilt als sein Kind — der Stapel wäre nach dem ersten Formular
     * unbrauchbar.
     *
     * @var list<string>
     */
    private const VOID = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img',
        'input', 'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /**
     * Gelesen wird einmal: `carriers()` läuft als Fixpunkt über alle Dateien.
     *
     * @var list<string>|null
     */
    private ?array $files = null;

    /**
     * Jeder Bereich steht in einem Behälter, der den Abstand trägt.
     */
    public function test_every_section_stands_in_a_container_that_carries_the_gap(): void
    {
        $carriers = $this->carriers();
        $loose = [];
        $seen = 0;

        foreach ($this->vueFiles() as $file) {
            // Die Träger sind hier ausgenommen, und nur sie: Ihre Bereiche
            // *sollen* ohne Behälter dastehen — den stellt, wer sie einsetzt.
            // Geprüft wird das im zweiten Test.
            if (in_array($this->component($file), $carriers, true)) {
                continue;
            }

            $findings = $this->scan($file, ['Section']);
            $seen += $findings['checked'];
            $loose = [...$loose, ...$findings['loose']];
        }

        $this->assertGreaterThan(
            40,
            $seen,
            'Es werden kaum Bereiche geprüft — dann bewacht dieser Test nichts mehr.',
        );

        $this->assertSame([], $loose, implode("\n", [
            'Ein Bereich ohne Behälter bekommt keinen Abstand zum nächsten — nicht',
            'zu wenig, sondern gar keinen. Er gehört in ein <div class="sections">',
            'oder in ein <form class="form">:',
            ...$loose,
        ]));
    }

    /**
     * Und eine Komponente, die Bereiche mitbringt, wird eingeklammert.
     *
     * Das ist die Richtung, die im Abnahmelauf falsch war. Sie lässt sich nicht
     * an der Komponente prüfen — dort ist gerade nichts zu sehen —, sondern nur
     * an jeder Stelle, die sie einsetzt.
     */
    public function test_every_component_that_brings_sections_is_wrapped_where_it_is_used(): void
    {
        $carriers = $this->carriers();

        if ($carriers === []) {
            // Kein Träger ist ein zulässiger Zustand und kein Grund zum
            // Zubeissen: Dann bringt jede Komponente ihren Behälter selbst mit.
            $this->assertSame([], $carriers);

            return;
        }

        $loose = [];
        $used = 0;

        foreach ($this->vueFiles() as $file) {
            if (in_array($this->component($file), $carriers, true)) {
                continue;
            }

            $findings = $this->scan($file, $carriers);
            $used += $findings['checked'];
            $loose = [...$loose, ...$findings['loose']];
        }

        $this->assertGreaterThan(
            0,
            $used,
            'Ein Träger, den niemand einsetzt, ist toter Code — oder dieser Test findet ihn nicht mehr.',
        );

        $this->assertSame([], $loose, implode("\n", [
            'Diese Komponente bringt Bereiche mit, aber keinen Behälter — den',
            'stellt, wer sie einsetzt. Ohne ihn stehen ihre Bereiche ohne Abstand:',
            ...$loose,
        ]));
    }

    /**
     * Und die Behälter tragen den Abstand wirklich.
     *
     * Ohne diese Prüfung wäre `CONTAINERS` genau die Sorte Zeichenkette, gegen
     * die dieses Projekt seine Wächter stellt: eine Liste von Klassennamen, die
     * behauptet, dass dort ein `gap` steht.
     */
    public function test_a_container_is_a_container_because_app_css_says_so(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');

        foreach (self::CONTAINERS as $container) {
            $this->assertMatchesRegularExpression(
                '/\.'.$container.'\s*\{[^}]*\bgap:\s*var\(--bereich-gap\)/s',
                $css,
                'Die Regel .'.$container.' setzt kein gap aus --bereich-gap — dann ist sie kein Behälter mehr.',
            );
        }
    }

    /**
     * Die Komponenten, die Bereiche ohne Behälter mitbringen.
     *
     * **Als Fixpunkt und nicht in einem Durchgang.** Eine Komponente, die eine
     * Trägerkomponente ohne Behälter einsetzt, ist selbst eine — sonst gäbe es
     * eine Ebene, hinter der sich ein loser Bereich verstecken lässt.
     *
     * Seiten stehen hier nie: Eine Seite hat niemanden über sich, der ihr einen
     * Behälter stellen könnte.
     *
     * @return list<string>
     */
    private function carriers(): array
    {
        $carriers = [];

        do {
            $before = count($carriers);

            foreach ($this->vueFiles() as $file) {
                $name = $this->component($file);

                if (! str_contains($file, '/Components/') || in_array($name, $carriers, true)) {
                    continue;
                }

                if ($this->scan($file, ['Section', ...$carriers])['loose'] !== []) {
                    $carriers[] = $name;
                }
            }
        } while (count($carriers) > $before);

        sort($carriers);

        return $carriers;
    }

    /**
     * Sucht die gesuchten Tags im Template und misst ihre Vorfahren.
     *
     * **Ein Stapel und kein regulärer Ausdruck.** Die Frage lautet „steht
     * darüber ein Behälter", und die kann kein Muster beantworten: Zwischen dem
     * `<div class="sections">` und dem Bereich stehen Kommentare, `<template
     * v-if>`-Klammern und andere Bereiche.
     *
     * @param  list<string>  $watched
     * @return array{checked:int,loose:list<string>}
     */
    private function scan(string $file, array $watched): array
    {
        $source = (string) file_get_contents($file);

        if (preg_match('#\n<template>\n#', $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return ['checked' => 0, 'loose' => []];
        }

        $start = (int) $match[0][1] + strlen($match[0][0]);
        $end = strrpos($source, "\n</template>");
        $template = substr($source, $start, ($end === false ? strlen($source) : $end) - $start);

        /** @var list<array{name:string,gap:bool}> $stack */
        $stack = [];
        $loose = [];
        $checked = 0;
        $length = strlen($template);
        $i = 0;

        while (($i = strpos($template, '<', $i)) !== false) {
            // Ein Kommentar wird übersprungen und nicht entfernt: Wer ihn
            // herausschneidet, verschiebt jede Zeilennummer danach.
            if (substr($template, $i, 4) === '<!--') {
                $close = strpos($template, '-->', $i);
                $i = $close === false ? $length : $close + 3;

                continue;
            }

            if (($template[$i + 1] ?? '') === '/') {
                $close = strpos($template, '>', $i);

                if ($close === false) {
                    break;
                }

                $name = trim(substr($template, $i + 2, $close - $i - 2));

                for ($k = count($stack) - 1; $k >= 0; $k--) {
                    if ($stack[$k]['name'] === $name) {
                        $stack = array_slice($stack, 0, $k);

                        break;
                    }
                }

                $i = $close + 1;

                continue;
            }

            if (preg_match('/^<([A-Za-z][\w.-]*)/', substr($template, $i, 64), $head) !== 1) {
                $i++;

                continue;
            }

            $tag = $this->tag($template, $i);
            $name = $head[1];

            if (in_array($name, $watched, true)) {
                $checked++;

                $carried = false;

                foreach ($stack as $ancestor) {
                    if ($ancestor['gap']) {
                        $carried = true;

                        break;
                    }
                }

                if (! $carried) {
                    $line = substr_count(substr($source, 0, $start + $i), "\n") + 1;
                    $loose[] = '  '.$this->relative($file).':'.$line.' — <'.$name.'>';
                }
            }

            if (! $this->closed($tag) && ! in_array(strtolower($name), self::VOID, true)) {
                $stack[] = ['name' => $name, 'gap' => $this->carriesGap($tag)];
            }

            $i += strlen($tag);
        }

        return ['checked' => $checked, 'loose' => $loose];
    }

    /**
     * Ein Tag von `<` bis zum zugehörigen `>`.
     *
     * Das schliessende Zeichen wird ausserhalb von Anführungszeichen gesucht —
     * `:class="{ eng: breite > 400 }"` und `v-if="a.length > 0"` stehen im
     * Repository, und ein `strpos($t, '>')` endete dort mitten im Attribut.
     */
    private function tag(string $template, int $start): string
    {
        $length = strlen($template);
        $quote = null;

        for ($j = $start + 1; $j < $length; $j++) {
            $char = $template[$j];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char === '>') {
                return substr($template, $start, $j - $start + 1);
            }
        }

        return substr($template, $start);
    }

    /** Schliesst sich das Tag selbst — `<Foo />`? */
    private function closed(string $tag): bool
    {
        return str_ends_with(rtrim(substr($tag, 0, -1)), '/');
    }

    /**
     * Trägt dieses Tag eine Behälterklasse?
     *
     * Nur die statische Schreibweise zählt. Ein Behälter, der davon abhängt, ob
     * gerade Daten da sind, wäre ein Abstand, der manchmal fehlt — und der
     * Rückblick verhindert, dass `:class="…"` hier als Klassenliste durchgeht.
     */
    private function carriesGap(string $tag): bool
    {
        if (preg_match('/(?<![:\w-])class="([^"]*)"/', $tag, $match) !== 1) {
            return false;
        }

        $classes = preg_split('/\s+/', trim($match[1])) ?: [];

        return array_intersect($classes, self::CONTAINERS) !== [];
    }

    /** Der Komponentenname einer Datei — `Foo/Bar.vue` heisst `Bar`. */
    private function component(string $file): string
    {
        return basename($file, '.vue');
    }

    /** @return list<string> */
    private function vueFiles(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        $found = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/js', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $this->files = $found;
    }

    private function relative(string $path): string
    {
        return str_replace(dirname(__DIR__, 2).'/', '', $path);
    }
}
