<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;
use Tests\Support\WithoutPhpComments;

/**
 * Die Reihenfolge der Protokollzeilen — die Naht zwischen Seite und Controller.
 *
 * ## Warum es diesen Wächter gibt
 *
 * **Die erlaubten Werte stehen an zwei Stellen**: als Liste im Controller und
 * als `value` an den beiden `<option>`. Das ist die Fehlerfamilie, die dieses
 * Repo am häufigsten getroffen hat — eine Zeichenkette, die auf etwas
 * verweist, ohne dass ein Typ oder ein Werkzeug den Bezug prüft. Ein
 * umbenannter Wert fiele nicht auf: Die Auswahl schickte ein Wort, der
 * Controller fiele wortlos auf die Vorgabe zurück, und die Seite zeigte
 * weiterhin die alte Reihenfolge.
 *
 * > **Ein Wert, der auf einen anderen zeigt, ohne dass etwas den Bezug prüft,
 * > veraltet still.**
 *
 * ## Kein Wahrheitswert, und das ist gemessen
 *
 * `router.get` legt seine Werte in die Adresse, und dort ist alles eine
 * Zeichenkette: Aus `false` wird das Wort `"false"`, und Laravels Regel
 * `boolean` nimmt kein Wort (`docs/66`). Der Kopf von `Logs/Index.vue` sagt das
 * seit dem Bau der Seite — dieser Wächter hält, dass die Reihenfolge nicht
 * dagegen verstösst.
 *
 * ## Was er nicht halten kann
 *
 * Ob die Zeilen wirklich umgedreht ankommen. Das ist eine Frage an eine
 * gerenderte Seite; sie steht als Punkt in `docs/917`.
 */
final class LogOrderTest extends TestCase
{
    use WithoutMarkupComments;
    use WithoutPhpComments;

    private const SEITE = __DIR__.'/../../resources/js/Pages/Logs/Index.vue';

    private const CONTROLLER = __DIR__.'/../../app/Http/Controllers/LogsController.php';

    public function test_the_page_and_the_controller_allow_the_same_words(): void
    {
        preg_match('/private const ORDERS = \[([^\]]*)\];/', $this->php(), $treffer);
        $this->assertNotEmpty($treffer, 'Keine Liste ORDERS im Controller.');

        preg_match_all("/'([a-z]+)'/", $treffer[1], $imController);
        preg_match_all('/<option value="([a-z]+)">/', $this->markup(), $inDerSeite);

        $this->assertSame(
            $imController[1],
            $inDerSeite[1],
            'Die Auswahl der Seite und die Liste des Controllers nennen nicht dieselben Wörter.',
        );

        $this->assertCount(2, $imController[1], 'Zwei Reihenfolgen — mehr kennt die Seite nicht.');
    }

    /**
     * Die Reihenfolge reist als Wort und nicht als Wahrheitswert.
     *
     * Geprüft an der **Form der Steuerung**: Ein `type="checkbox"` in diesem
     * Formular wäre genau der Fehler aus `docs/66`.
     */
    public function test_the_order_is_a_word_and_not_a_checkbox(): void
    {
        $markup = $this->markup();
        $von = strpos($markup, '<div class="filter">');
        $this->assertIsInt($von);

        $bis = strpos($markup, '</div>', $von);
        $this->assertIsInt($bis);

        $this->assertStringNotContainsString(
            'type="checkbox"',
            substr($markup, $von, $bis - $von),
            'Ein Wahrheitswert käme über die Adresse als das Wort „false" an.',
        );
    }

    /**
     * Der Knopf heisst „Angezeigtes sichern", also folgt die Datei der Anzeige.
     *
     * **Sonst hiesse er das und täte etwas anderes**, sobald jemand die
     * Reihenfolge umdreht — und der Unterschied fiele erst auf, wenn man beide
     * nebeneinanderlegt.
     */
    public function test_the_download_follows_the_shown_order(): void
    {
        $php = $this->php();
        $von = strpos($php, 'public function download(');
        $this->assertIsInt($von, 'download() nicht gefunden.');

        $bis = strpos($php, "\n    }", $von);
        $this->assertIsInt($bis);

        $this->assertStringContainsString(
            'array_reverse($lines)',
            substr($php, $von, $bis - $von),
            'Das Herunterladen dreht die Zeilen nicht mit.',
        );

        $this->assertStringContainsString(
            'Angezeigtes sichern',
            $this->markup(),
            'Untergrenze: Heisst der Knopf anders, ist die Begründung dieses Falls hinfällig.',
        );
    }

    private function markup(): string
    {
        return $this->withoutMarkupComments((string) file_get_contents(self::SEITE));
    }

    private function php(): string
    {
        return $this->withoutComments((string) file_get_contents(self::CONTROLLER));
    }
}
