<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;

/**
 * Was sich bewegt, hält an, wenn das Betriebssystem weniger Bewegung verlangt.
 *
 * ## Warum dieser Wächter nicht das prüft, was `docs/904 §5` angekündigt hat
 *
 * Der Plan verlangte eine eigene `prefers-reduced-motion`-Ausnahme für den
 * Platzhalter. **Die gibt es hier schon, und zwar für alle** — ganz unten in
 * `app.css` steht sie seit langem als `*`-Regel. Eine zweite wäre ihre zweite
 * Fassung, und die zweite ist die, die veraltet.
 *
 * Gemessen im Container gegen echtes Chromium, 40 Bilder je Lauf: **ohne** die
 * Einstellung 30 verschiedene Werte, **mit** ihr ein Wert ab dem zweiten Bild
 * und unverändert bis zum vierzigsten. Die Regel hält an, sie flackert nicht.
 *
 * > **Eine Ausnahme für eine Einstellung, die man selbst nicht benutzt, prüft
 * > niemand beim Ansehen.**
 *
 * Deshalb hält dieser Wächter nicht „der Platzhalter hat seine Ausnahme",
 * sondern die Bedingung, unter der die eine Regel für ihn gilt: dass es sie
 * gibt, dass sie alles trifft, und dass keine Animation sich mit `!important`
 * über sie stellt.
 *
 * ## Was er nicht kann
 *
 * Er misst keine Bewegung. Ob eine Animation im Browser wirklich stillsteht,
 * sagt nur eine Messung im Browser — sie steht als Punkt 6 im Abnahmelauf.
 * Hier steht, was ohne ihn zu halten ist.
 */
final class SkeletonStyleTest extends TestCase
{
    use WithoutMarkupComments;

    /** Wo das Stylesheet steht. */
    private const STYLESHEET = 'resources/css/app.css';

    /** Wo die Komponenten und Seiten liegen. */
    private const MARKUP = ['resources/js/Pages', 'resources/js/Components', 'resources/js/Layouts'];

    /**
     * Die eine Regel, die jede Animation anhält — genau einmal.
     *
     * **Zweimal wäre schlimmer als keinmal:** Zwei Blöcke laufen auseinander,
     * und welcher gilt, entscheidet die Reihenfolge in der Datei. Wer den
     * zweiten schreibt, glaubt den ersten zu ersetzen.
     */
    public function test_one_rule_stops_every_animation(): void
    {
        $css = $this->stylesheet();

        preg_match_all('/@media\s*\(\s*prefers-reduced-motion\s*:\s*reduce\s*\)\s*\{/', $css, $treffer);

        $this->assertCount(
            1,
            $treffer[0],
            'Es gibt nicht genau einen `prefers-reduced-motion`-Block. Zwei laufen auseinander, '
            .'und welcher gilt, entscheidet die Reihenfolge in der Datei.',
        );

        /*
         * Der Rumpf bis zur schliessenden Klammer der Regel darin. Gesucht
         * wird der Selektor `*` — eine Ausnahme für einzelne Klassen wäre eine
         * Liste, und die vergisst man beim nächsten `animation:`.
         */
        $von = (int) strpos($css, $treffer[0][0]);
        $rumpf = substr($css, $von, 400);

        $this->assertMatchesRegularExpression(
            '/\*\s*,/',
            $rumpf,
            'Der Block hält nicht mehr jede Animation an, sondern eine Auswahl — und die vergisst '
            .'man beim nächsten `animation:`.',
        );

        $this->assertStringContainsString(
            'animation-duration: 0.01ms !important',
            $rumpf,
            'Der Block hält die Dauer nicht mehr an. Ohne sie läuft jede Animation weiter, '
            .'auch die des Platzhalters.',
        );
    }

    /**
     * Und keine Animation stellt sich über ihn.
     *
     * `!important` schlägt `!important` nur über die Spezifität, und die einer
     * Klasse ist höher als die von `*`. Eine einzige solche Zeile nähme die
     * Regel oben für genau ihr Element zurück — still, denn sie sieht auf
     * einem Gerät ohne die Einstellung völlig richtig aus.
     */
    public function test_no_animation_outranks_it(): void
    {
        $css = $this->stylesheet();

        /*
         * Der Block selbst darf es natürlich — er ist ja die Regel. Also wird
         * er vorher herausgeschnitten.
         */
        $ohneBlock = (string) preg_replace(
            '/@media\s*\(\s*prefers-reduced-motion[^{]*\{(?:[^{}]|\{[^{}]*\})*\}/',
            '',
            $css,
        );

        preg_match_all('/^[^\n]*animation[^\n:]*:[^\n;]*!important[^\n]*$/m', $ohneBlock, $treffer);

        $this->assertSame([], $treffer[0], sprintf(
            "Diese Zeilen stellen sich über die Regel, die jede Animation anhält:\n\n  %s\n\n"
            .'Auf einem Gerät ohne die Einstellung sieht das völlig richtig aus — '
            .'und genau deshalb bemerkt es niemand.',
            implode("\n  ", array_map('trim', $treffer[0])),
        ));
    }

    /**
     * Der Platzhalter wohnt in `app.css` und nirgends sonst.
     *
     * Dieselbe Regel wie bei Knopf, Feld und Tabelle: Eine Komponente, die
     * ihren eigenen Platzhalter gestaltet, ist derselbe Fehler wie ein Hexwert
     * in ihr — und die zweite Fassung ist die, die beim nächsten Umbau
     * stehenbleibt.
     */
    public function test_the_placeholder_is_styled_only_in_the_stylesheet(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\.skeleton\s*\{/m',
            $this->stylesheet(),
            'Es gibt keine freistehende Regel `.skeleton` mehr — dann ist der Platzhalter '
            .'entweder tot oder er wohnt woanders.',
        );

        $fehler = [];
        $gelesen = 0;

        foreach ($this->markup() as $pfad => $quelle) {
            $gelesen++;

            /*
             * Nur der `<style>`-Block und nicht die ganze Datei: `class="skeleton"`
             * im Vorlagenteil ist ja gerade richtig. Gesucht wird, wer ihn
             * **gestaltet**.
             */
            preg_match_all('/<style[^>]*>(.*?)<\/style>/s', $quelle, $bloecke);

            foreach ($bloecke[1] as $block) {
                if (preg_match('/\.skeleton\b/', $block) === 1) {
                    $fehler[] = $pfad;
                }
            }
        }

        $this->assertGreaterThan(50, $gelesen, 'Kaum noch Vorlagen gelesen — der Ausdruck greift nicht mehr.');

        $this->assertSame([], $fehler, sprintf(
            "Diese Komponenten gestalten den Platzhalter selbst:\n\n  %s",
            implode("\n  ", $fehler),
        ));
    }

    /** Das Stylesheet. */
    private function stylesheet(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.self::STYLESHEET);
    }

    /**
     * Alle `.vue` unter den Vorlagenverzeichnissen, ohne ihre Kommentare.
     *
     * @return array<string, string>
     */
    private function markup(): array
    {
        $ergebnis = [];
        $wurzel = dirname(__DIR__, 2);

        foreach (self::MARKUP as $unter) {
            /** @var iterable<\SplFileInfo> $lauf */
            $lauf = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($wurzel.'/'.$unter, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($lauf as $datei) {
                if ($datei->isFile() && $datei->getExtension() === 'vue') {
                    $pfad = substr($datei->getPathname(), strlen($wurzel) + 1);
                    $ergebnis[$pfad] = $this->withoutMarkupComments(
                        (string) file_get_contents($datei->getPathname()),
                    );
                }
            }
        }

        ksort($ergebnis);

        return $ergebnis;
    }
}
