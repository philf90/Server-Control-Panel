<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * In Fliesstext wird ein Leerzeichen nicht dem Zeilenumbruch überlassen.
 *
 * **Warum es diesen Wächter gibt.** Im Abnahmelauf des SFTP-Zugangs stand auf
 * der Seite `zustande./etc/srvpanel/ssh` — ohne Trennung (`docs/59`, Befund 4).
 * Im Quelltext war das Leerzeichen da, und zwar als Zeilenumbruch zwischen
 * `</b>` und `<span class="ident">`.
 *
 * > **Ein Leerzeichen, das im Quelltext als Zeilenumbruch dasteht, ist für den
 * > Übersetzer keines.**
 *
 * Vues Vorgabe `whitespace: 'condense'` **entfernt** einen Textknoten zwischen
 * zwei Elementen, wenn er nur aus Weissraum besteht *und* einen Zeilenumbruch
 * enthält; ohne Umbruch bleibt ein Leerzeichen stehen. Gemessen am 17. August
 * 2026 am Übersetzer selbst (`@vue/compiler-dom`):
 *
 * ```
 * <b>…</b>\n<span>…</span>          → createElementVNode("b"), createElementVNode("span")
 * <b>…</b>{{ ' ' }}\n<span>…</span> → createElementVNode("b"), createTextVNode(…), …
 * ```
 *
 * Im ersten Fall liegt zwischen den beiden Knoten nichts. Der Leser sieht zwei
 * Wörter, die aneinanderkleben — und **kein Test hat je etwas dazu gesagt**,
 * weil die Zeichenkette im Quelltext richtig aussieht.
 *
 * ## Warum der Wächter nicht überall gilt
 *
 * Zwischen zwei Flex-Kindern ist derselbe fehlende Textknoten folgenlos: Der
 * Abstand kommt aus `gap`, nicht aus einem Leerzeichen. Von zweiundzwanzig
 * Stellen im Baum, an denen zwei Elemente durch einen Umbruch getrennt sind,
 * sind einundzwanzig genau das — Beschriftung und Feld in `.field`, zwei
 * Zweige eines `v-if`/`v-else`, gestapelte Hinweise in `.toggle > span`.
 *
 * > **Eine Regel, die einundzwanzigmal Fehlalarm gibt, wird beim ersten
 * > Aufräumen abgeschaltet.**
 *
 * Der Wächter fragt deshalb nur dort, wo der Behälter **Fliesstext** ist: die
 * Meldungsklassen dieses Panels. Und er **misst diese Voraussetzung nach**,
 * statt sie zu behaupten — {@see self::test_the_premise_of_this_guard_holds()}.
 * Zieht eine dieser Klassen eines Tages auf `display: flex` um, wird der
 * Wächter rot und nicht still.
 */
final class TemplateSpacingTest extends TestCase
{
    /**
     * Behälter, deren Inhalt als Satz gelesen wird.
     *
     * `.notice` steht **nicht** hier: Er ist selbst ein Flex-Behälter, und der
     * Satz steht in dem `span` darin, auf dem `NoticeShapeTest` besteht. Genau
     * diese Schachtelung ist die Stelle, an der der Fehler aufgetreten ist.
     */
    private const PROSE = ['hint', 'empty', 'section-note', 'error'];

    /** Elemente, die in einer Zeile mitlaufen — ihr Abstand ist ein Leerzeichen. */
    private const INLINE = ['b', 'strong', 'em', 'i', 'span', 'a', 'code', 'small', 'abbr', 'time'];

    /** Was kein Ende hat, gehört nicht auf den Stapel. */
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function css(): string
    {
        return (string) preg_replace('#/\*.*?\*/#su', '', (string) file_get_contents($this->root().'/resources/css/app.css'));
    }

    /**
     * Die Klassen, die aus ihren Kindern Flex- oder Rasterkinder machen.
     *
     * Genommen wird die **letzte** einfache Klasse eines Selektors: In
     * `.toggle > span` ist `span` das gestaltete Element und `toggle` nur der
     * Weg dorthin. Für den Zweck hier — Fehlalarme unterdrücken — reicht das;
     * eine falsch mitgenommene Klasse macht den Wächter enger und nicht weiter.
     *
     * @return list<string>
     */
    private function layoutClasses(): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $this->css(), $rules, PREG_SET_ORDER);

        $found = [];

        foreach ($rules as $rule) {
            if (preg_match('/(?:^|[;\s])display:\s*(inline-)?(flex|grid)\b/', $rule[2]) !== 1) {
                continue;
            }

            foreach (explode(',', $rule[1]) as $selector) {
                preg_match_all('/\.([\p{L}][\w-]*)/u', $selector, $classes);

                if ($classes[1] !== []) {
                    $found[] = (string) end($classes[1]);
                }
            }
        }

        return array_values(array_unique($found));
    }

    /** @return list<string> */
    private function vueFiles(): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/resources/js', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Skript, Stil und Kommentare weg — aber die Zeilennummern bleiben.
     *
     * Ersetzt wird durch ebenso viele Zeilenumbrüche. Beim ersten Anlauf stand
     * der Befund fünfzig Zeilen daneben, mitten im `<script>`-Block, und sah
     * damit aus wie ein Fehlalarm.
     */
    private function template(string $source): string
    {
        $blank = static fn (array $match): string => str_repeat("\n", substr_count($match[0], "\n"));

        $source = (string) preg_replace_callback('#<(script|style)\b[^>]*>.*?</\1>#su', $blank, $source);

        return (string) preg_replace_callback('#<!--.*?-->#su', $blank, $source);
    }

    /** @return list<string> */
    private function classesOf(string $attributes): array
    {
        if (preg_match('/(?<![:\w-])class="([^"]*)"/', $attributes, $match) !== 1) {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/', trim($match[1])) ?: []));
    }

    /**
     * Jede Stelle, an der zwei Elemente in Fliesstext nur ein Umbruch trennt.
     *
     * @param  array{gaps: int, prose: int}  $counter
     * @return list<string>
     */
    private function findings(string $source, string $file, array &$counter): array
    {
        $template = $this->template($source);
        $layout = $this->layoutClasses();
        $findings = [];

        /** @var list<array{tag: string, prose: bool}> $stack */
        $stack = [];

        $offset = 0;
        $closed = null;

        preg_match_all(
            '#<(/?)([a-zA-Z][\w.-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)(/?)>#s',
            $template,
            $tags,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        foreach ($tags as $tag) {
            $whole = $tag[0][0];
            $position = $tag[0][1];
            $closing = $tag[1][0] === '/';
            $name = strtolower($tag[2][0]);
            $attributes = $tag[3][0];
            $selfClosing = $tag[4][0] === '/';

            $between = substr($template, $offset, $position - $offset);
            $offset = $position + strlen($whole);

            $parent = $stack === [] ? null : $stack[count($stack) - 1];

            // Die Lücke: Inline-Schluss, nur Weissraum mit Umbruch, Inline-Anfang.
            $gap = ! $closing
                && $closed !== null
                && in_array($name, self::INLINE, true)
                && trim($between) === ''
                && str_contains($between, "\n");

            if ($gap) {
                $counter['gaps']++;

                if ($parent !== null && $parent['prose']) {
                    $findings[] = sprintf(
                        '%s:%d  </%s> … <%s> — dazwischen steht nur ein Zeilenumbruch',
                        str_replace($this->root().'/', '', $file),
                        substr_count(substr($template, 0, $position), "\n") + 1,
                        $closed,
                        $name,
                    );
                }
            }

            $closed = null;

            if ($closing) {
                for ($i = count($stack) - 1; $i >= 0; $i--) {
                    if ($stack[$i]['tag'] === $name) {
                        $stack = array_slice($stack, 0, $i);

                        break;
                    }
                }

                if (in_array($name, self::INLINE, true)) {
                    $closed = $name;
                }

                continue;
            }

            if ($selfClosing || in_array($name, self::VOID, true)) {
                continue;
            }

            $classes = $this->classesOf($attributes);

            $prose = $parent !== null && $parent['prose'];
            $prose = $prose || array_intersect($classes, self::PROSE) !== [];
            $prose = $prose || ($parent !== null && $parent['tag'] === 'notice-wrapper' && $name === 'span');
            $prose = $prose && array_intersect($classes, $layout) === [];

            if ($prose) {
                $counter['prose']++;
            }

            /*
             * Der Satz einer Meldung steht in dem `span`, den `NoticeShapeTest`
             * verlangt — nicht im `.notice` selbst, der ein Flex-Behälter ist.
             * Der Behälter wird deshalb umbenannt weitergereicht, damit genau
             * sein nächstes `span`-Kind als Fliesstext gilt und nicht jedes.
             */
            $stack[] = [
                'tag' => in_array('notice', $classes, true) ? 'notice-wrapper' : $name,
                'prose' => $prose,
            ];
        }

        return $findings;
    }

    /**
     * Die Voraussetzung dieses Wächters — gemessen und nicht behauptet.
     *
     * Ein Wächter, dessen Prämisse still umzieht, prüft ab da eine andere
     * Anwendung. Bekäme `.hint` eines Tages `display: flex`, wären seine Kinder
     * Flexkinder, der fehlende Textknoten folgenlos — und der Wächter meldete
     * einen Fehler, den es nicht gibt.
     */
    public function test_the_premise_of_this_guard_holds(): void
    {
        $layout = $this->layoutClasses();

        $this->assertContains(
            'notice',
            $layout,
            'Eine Meldung ist kein Flex-Behälter mehr — dann steht ihr Satz nicht mehr in dem span darin, '.
            'und dieser Wächter sieht an der falschen Stelle nach.',
        );

        foreach (self::PROSE as $klasse) {
            $this->assertNotContains(
                $klasse,
                $layout,
                sprintf('.%s ist zu einem Flex- oder Rasterbehälter geworden — dann ist sein Inhalt kein Fliesstext mehr.', $klasse),
            );
        }
    }

    /** Kein Fliesstext dieses Panels verlässt sich auf einen Zeilenumbruch. */
    public function test_no_prose_relies_on_a_line_break_for_a_space(): void
    {
        $counter = ['gaps' => 0, 'prose' => 0];
        $findings = [];

        $files = $this->vueFiles();

        $this->assertGreaterThan(8, count($files), 'Es werden kaum Dateien gelesen — dann prüft dieser Wächter nichts.');

        foreach ($files as $path) {
            $findings = array_merge($findings, $this->findings((string) file_get_contents($path), $path, $counter));
        }

        /*
         * Die Untergrenzen sind die Gegenprobe im Alltag: Ein Ausdruck, der ins
         * Leere läuft, meldet sonst „alles in Ordnung". Gezählt wird, wo die
         * Regel stehen *darf* — nicht, wo sie stehen *soll*; sonst wird der
         * Wächter beim Aufräumen rot für genau die Ordnung, die er durchsetzt.
         */
        $this->assertGreaterThan(10, $counter['gaps'], 'Es werden kaum Lücken gefunden — dann liest der Zerteiler nichts.');
        $this->assertGreaterThan(30, $counter['prose'], 'Es wird kaum Fliesstext gefunden — dann sieht dieser Wächter nirgends hin.');

        $this->assertSame([], $findings, sprintf(
            "Hier klebt im Browser zusammen, was im Quelltext getrennt aussieht:\n  %s\n\n".
            "Vue entfernt einen Textknoten zwischen zwei Elementen, wenn er nur aus Weissraum mit \n".
            'Zeilenumbruch besteht. Entweder beide Elemente in eine Zeile — oder ein ausdrückliches '.
            "{{ ' ' }} dazwischen.",
            implode("\n  ", $findings),
        ));
    }

    /**
     * Und der Wächter beisst — an einem Fall, der nicht im Baum steht.
     *
     * Ein Wächter, der nur „grün" sagen kann, weil sein Zerteiler nichts
     * findet, ist keiner. Hier steht beides nebeneinander: die Fassung aus dem
     * Lauf, an der die Meldung zusammenklebte, und die reparierte daneben.
     */
    public function test_this_guard_bites(): void
    {
        $counter = ['gaps' => 0, 'prose' => 0];

        $kaputt = <<<'VUE'
            <template>
              <p class="notice critical">
                <span>
                  <b>Der Zugang kommt so nicht zustande.</b>
                  <span class="ident">{{ pfad }}</span> {{ grund }}
                </span>
              </p>
            </template>
            VUE;

        $heil = str_replace('zustande.</b>', "zustande.</b>{{ ' ' }}", $kaputt);

        $this->assertCount(1, $this->findings($kaputt, 'probe.vue', $counter));
        $this->assertSame([], $this->findings($heil, 'probe.vue', $counter));

        /*
         * Und dieselbe Form in einem Flex-Behälter bleibt still — genau diese
         * Unterscheidung ist der Grund, warum der Wächter nicht überall fragt.
         * Ohne diesen Fall hiesse „grün" auf dem Baum nur, dass der Zerteiler
         * nichts findet, und nicht, dass er richtig unterscheidet.
         */
        $flex = str_replace('<span>', '<span class="button-row">', $kaputt);

        $this->assertContains('button-row', $this->layoutClasses());
        $this->assertSame([], $this->findings($flex, 'probe.vue', $counter));
    }
}
