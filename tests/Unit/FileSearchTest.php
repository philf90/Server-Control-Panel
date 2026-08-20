<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Die Suche des Dateimanagers — eine Sache, zwei Eingaben.
 *
 * ## Der Fund, der diesen Wächter ausgelöst hat
 *
 * Beim Messen von Wunsch 3 (`docs/64 §6`): Die Suche aus der Kopfleiste schickte
 * `query` und `path`, die Trefferseite dagegen `query`, `path` **und**
 * `content`. Wer von der Kopfleiste suchte, konnte also nie im Inhalt suchen —
 * und erfuhr erst auf der Trefferseite, dass es die Möglichkeit gibt.
 *
 * > **Zwei Eingaben für dieselbe Sache sind eine Sicht und eine Kopie — und die
 * > Kopie ist die, die weniger kann.**
 *
 * Aufgefallen ist das keinem Test, sondern einer Messung, die etwas ganz
 * anderes wissen wollte.
 *
 * ## Und die Auskunft, ohne die eine ständige Leiste gefährlich ist
 *
 * Die Suche gilt **dem Verzeichnis, in dem man steht**, und nicht dem
 * Abonnement. Solange ein Knopf sie öffnete, stand das in seiner Beschriftung.
 * Eine Leiste, die immer da ist, sieht dagegen aus, als suchte sie überall
 * (`docs/64 §6.1`) — und wer in einem tiefen Verzeichnis steht und das nicht
 * sieht, sucht am Bestand vorbei und schliesst daraus, die Datei gebe es nicht.
 *
 * Das ist schlimmer als ein Klick zu viel, und es ist **eine Zeile**, die beim
 * nächsten Umbau verschwinden kann.
 */
final class FileSearchTest extends TestCase
{
    /** Die Seite mit der Leiste. */
    private const INDEX = 'resources/js/Pages/Files/Index.vue';

    /** Die Trefferseite mit ihrem eigenen Feld. */
    private const RESULTS = 'resources/js/Pages/Files/Search.vue';

    /**
     * Beide Eingaben schicken dieselben Werte.
     *
     * Verglichen werden die Schlüssel des Aufrufs und nicht sein Wortlaut: Ob
     * dort `begriff` oder `query` steht, ist gleich — was ankommt, zählt.
     */
    public function test_both_inputs_send_the_same_values(): void
    {
        $ausLeiste = $this->searchKeys(self::INDEX);
        $ausTreffer = $this->searchKeys(self::RESULTS);

        // Eine Null ist nur dann eine Messung, wenn daneben etwas anderes steht.
        $this->assertGreaterThanOrEqual(3, count($ausLeiste), sprintf(
            'In %s werden kaum Werte für die Suche gefunden — dann prüft dieser Wächter nichts.',
            self::INDEX,
        ));

        $this->assertSame($ausTreffer, $ausLeiste, sprintf(
            "Die Leiste in %s und das Feld in %s schicken Verschiedenes.\n\n".
            'Dann kann die eine etwas, was die andere nicht kann — und der Kunde erfährt es erst '.
            'auf der Seite, auf der er nicht mehr sucht.',
            self::INDEX,
            self::RESULTS,
        ));
    }

    /**
     * Die Leiste nennt das Verzeichnis, in dem sie sucht.
     *
     * **Nicht im Platzhalter.** Der ist genau so lange da, wie man ihn nicht
     * braucht: Sobald jemand tippt, ist er fort — und mit ihm die einzige
     * Stelle, an der der Geltungsbereich stand.
     */
    public function test_the_bar_names_the_directory_it_searches(): void
    {
        $leiste = $this->form($this->read(self::INDEX));

        $this->assertNotSame('', $leiste, 'Die Suchleiste ist nicht mehr zu finden — dann prüft dieser Wächter nichts.');

        $this->assertStringContainsString(
            'props.path',
            $leiste,
            'Die Suchleiste nennt das Verzeichnis nicht, in dem sie sucht. Eine Leiste, die immer '.
            'da ist, sieht damit aus, als suchte sie überall (docs/64 §6.1).',
        );

        $this->assertStringNotContainsString(
            'placeholder',
            $leiste,
            'Die Auskunft steht in einem Platzhalter. Der ist fort, sobald jemand tippt — also '.
            'genau dann, wenn die Suche gleich läuft.',
        );
    }

    /**
     * Der Knopf und die Leiste zeigen aufeinander.
     *
     * **Die Fehlerklasse, die dieses Projekt am häufigsten getroffen hat:** eine
     * Zeichenkette, die auf etwas verweist, ohne dass der Bezug geprüft wird.
     * Ein `aria-controls` auf eine Kennung, die es nicht gibt, fällt niemandem
     * auf — ausser dem, der die Seite vorgelesen bekommt.
     */
    public function test_the_toggle_points_at_the_bar(): void
    {
        $quelle = $this->read(self::INDEX);

        $this->assertSame(
            1,
            preg_match('/aria-controls="([\w-]+)"/', $quelle, $treffer),
            'Der Knopf, der die Leiste öffnet, sagt nicht, was er öffnet.',
        );

        $this->assertMatchesRegularExpression(
            '/<form id="'.preg_quote($treffer[1], '/').'"/',
            $quelle,
            sprintf(
                'Der Knopf zeigt mit `aria-controls` auf „%s", und ein Formular mit dieser Kennung '.
                'gibt es nicht.',
                $treffer[1],
            ),
        );

        $this->assertStringContainsString(
            ':aria-expanded="searchOpen"',
            $quelle,
            'Der Knopf sagt nicht, ob die Leiste gerade offen ist. Er schaltet in beide '.
            'Richtungen — wer ihn nicht sieht, erfährt sonst nicht, welche gerade gilt.',
        );
    }

    /**
     * Die Schwelle steht in `app.css` und nicht in der Seite.
     *
     * Eine Abfrage der Fensterbreite in `Index.vue` wäre dieselbe Zahl ein
     * zweites Mal — und die zweite ist die, die beim nächsten Umbau abweicht.
     * Dass `.search` überhaupt eine Schwelle hat, steht hier mit.
     */
    public function test_the_threshold_lives_in_the_stylesheet(): void
    {
        /*
         * **Ohne Kommentare.** Der erste Anlauf las die ganze Datei — und war
         * rot, weil in `Index.vue` die Begründung steht, warum dort *kein*
         * `matchMedia` steht. Dasselbe Missverständnis wie bei `RootElementTest`
         * am selben Tag, nur andersherum: Dort machte ein Kommentar den Wächter
         * grün, hier rot.
         *
         * > **Ein Wächter, der eine Datei liest, liest auch, was jemand über
         * > sie geschrieben hat.**
         */
        $this->assertStringNotContainsString(
            'matchMedia',
            $this->code($this->read(self::INDEX)),
            'Die Seite fragt die Fensterbreite selbst ab. Der Haltepunkt der Suchleiste steht in '.
            'app.css; zwei Fassungen davon sind eine zu viel.',
        );

        $css = $this->read('resources/css/app.css');

        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 720px\) \{[^@]*?\.search:not\(\.open\)/s',
            $css,
            'Die Suchleiste hat keine Schwelle mehr in app.css — dann steht sie überall, auch bei '.
            '390 px, wo sie gemessen 141 px kostet und keine Leiste ist.',
        );
    }

    /**
     * Eine Quelle ohne ihre Kommentare.
     *
     * Ersetzt wird durch Leerzeichen gleicher Länge und nicht gestrichen —
     * damit bleiben Zeilennummern und Abstände heil, falls hier je ein Ausdruck
     * über eine Stelle urteilt.
     */
    private function code(string $quelle): string
    {
        return (string) preg_replace_callback(
            '/<!--.*?-->|\/\*.*?\*\/|\/\/[^\n]*/s',
            static fn (array $t): string => str_repeat(' ', strlen($t[0])),
            $quelle,
        );
    }

    /**
     * Die Werte, die eine Suche an den Server schickt.
     *
     * @return list<string>
     */
    private function searchKeys(string $pfad): array
    {
        $quelle = $this->read($pfad);

        if (preg_match('/files\/search`,\s*\{(.+?)\n\s*\}\)/s', $quelle, $block) !== 1) {
            return [];
        }

        preg_match_all('/^\s+(\w+):/m', $block[1], $treffer);

        $keys = $treffer[1];
        sort($keys);

        return $keys;
    }

    /** Das Formular der Suchleiste, von `<form` bis `</form>`. */
    private function form(string $quelle): string
    {
        if (preg_match('/<form[^>]*class="[^"]*\bsearch\b[^"]*"(.+?)<\/form>/s', $quelle, $treffer) !== 1) {
            return '';
        }

        return $treffer[1];
    }

    private function read(string $pfad): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$pfad);
    }
}
