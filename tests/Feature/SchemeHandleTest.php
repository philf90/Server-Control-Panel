<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Files\Scheme;
use SrvPanel\Agent\Ops\SubscriptionProvision;

/**
 * Die Liste bietet keinen Griff an, den `Scheme` immer abweist.
 *
 * ## Der Fund im Browser
 *
 * Gemessen am 15. August 2026 auf einem iPhone (`docs/55`, Befund 18): `.ssh/`
 * trug in der Dateiliste die Griffe **Umbenennen**, **Rechte** und
 * **Entfernen**. `Scheme::protect()` weist alle drei für die sechs
 * Verzeichnisse des Schemas **immer** ab — für jeden Kunden, in jedem Zustand.
 *
 * Die Liste entschied über `can.edit && entry.writable`, und `.ssh` gehört dem
 * Kunden mit `0700`; `writable` war also wahr. **Die zweite Schranke gab es nur
 * im Agenten**, und im Panel hatte sie kein Gegenstück — zwei Zeilen über dem
 * `v-if` steht der Satz, den das verletzt.
 *
 * > **Ein Knopf, der nie funktioniert, ist keine Auskunft — er ist eine Zusage,
 * > die das System nicht einlöst.**
 *
 * Bei „Entfernen" ist die Absage noch lehrreich; bei „Rechte" nicht: Der Kunde
 * öffnet den Editor, setzt neun Kästchen, drückt Speichern — und erfährt erst
 * dann, dass es nie ging.
 *
 * ## Beide Richtungen
 *
 * Ein Wächter, der nur „das `v-if` fragt nach `fixed`" prüft, ist grün, wenn
 * `fixed` gar nicht mehr ankommt. Geprüft wird deshalb die **Kette**: dass der
 * Controller die Marke setzt, dass er sie aus `Scheme` holt und nicht aus einer
 * eigenen Liste, und dass die Vorlage sie liest.
 *
 * > **Eine Marke, die niemand setzt, und eine, die niemand liest, sehen im
 * > Diff gleich harmlos aus.**
 */
final class SchemeHandleTest extends TestCase
{
    private function controller(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/FileController.php',
        );
    }

    private function page(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/Pages/Files/Index.vue',
        );
    }

    public function test_the_listing_marks_the_scheme_directories(): void
    {
        $quelle = $this->controller();

        $this->assertStringContainsString(
            "'fixed' => Scheme::isFixed(",
            $quelle,
            "Die Liste sagt nicht mehr, welche Einträge zum Gerüst gehören.\n\n".
            'Ohne die Marke zeigt die Seite wieder Griffe an, die der Agent jedes Mal abweist.',
        );

        /*
         * **Und die Stelle, die sie setzt, wird auch aufgerufen.**
         *
         * Der erste Bruch gegen diesen Wächter hat genau das ausgenutzt: Er
         * nahm `marked()` aus der Antwort heraus und liess die Methode stehen.
         * Der Ausdruck darüber fand sie weiter — in totem Code.
         *
         * > **Eine Zeile, die niemand ausführt, steht im Quelltext genauso da
         * > wie eine, die läuft.**
         */
        $this->assertStringContainsString(
            "'entries' => \$this->marked(",
            $quelle,
            "Die Antwort an die Seite geht an der Markierung vorbei.\n\n".
            '`marked()` steht dann als toter Code da, und jeder Wächter, der nur seinen Inhalt '.
            'liest, bleibt grün.',
        );
    }

    /**
     * Und sie fragt `Scheme` statt eine eigene Liste zu führen.
     *
     * Käme morgen `cache` zum Schema dazu, wäre eine abgetippte Aufzählung hier
     * still unvollständig — dieselbe Begründung, mit der `Scheme` selbst seine
     * Liste aus {@see SubscriptionProvision} holt.
     */
    public function test_it_asks_the_scheme_instead_of_listing_the_names(): void
    {
        $quelle = $this->controller();

        foreach (Scheme::fixed() as $pfad) {
            $name = trim($pfad, '/');

            // Beide Schreibweisen: `'httpdocs'` und `'/httpdocs'`. Der zweite
            // Bruch gegen diesen Wächter hat die Liste mit führenden
            // Schrägstrichen abgetippt und ist durchgekommen.
            $this->assertDoesNotMatchRegularExpression(
                "/'\/?".preg_quote($name, '/')."'/",
                $quelle,
                sprintf(
                    "Der Controller zählt `%s` selbst auf.\n\n".
                    'Dann gibt es zwei Listen des Schemas, und die hier ist die, die beim nächsten '.
                    'Zuwachs veraltet.',
                    $name,
                ),
            );
        }
    }

    public function test_the_page_hides_the_handles_for_them(): void
    {
        $seite = $this->page();

        $this->assertStringContainsString(
            'entry.writable && !entry.fixed',
            $seite,
            "Die Liste zeigt die Griffe wieder für die Verzeichnisse des Schemas.\n\n".
            'Umbenennen, Rechte und Entfernen weist `Scheme::protect()` dort **immer** ab.',
        );
    }

    /**
     * Und an ihrer Stelle steht, warum.
     *
     * Ein blosser Strich sagt „hier ist nichts", und das ist für ein Verzeichnis
     * des Schemas die falsche Auskunft: Sein **Inhalt** lässt sich sehr wohl
     * ändern.
     *
     * > **Eine Abweisung, die vorher kommt, muss denselben Satz sagen wie die,
     * > die nachher käme.**
     */
    public function test_it_says_why_instead_of_showing_a_dash(): void
    {
        $seite = $this->page();

        $this->assertMatchesRegularExpression(
            '/v-else-if="entry\.fixed"[^>]*>\s*gehört zum Aufbau/u',
            $seite,
            "Wo die Griffe fehlen, steht kein Grund.\n\n".
            'Dann liest sich die Zeile wie ein Eintrag, mit dem sich gar nichts anfangen lässt — und '.
            'sein Inhalt ist sehr wohl änderbar.',
        );
    }

    /**
     * Und sie lassen sich auch nicht anhaken.
     *
     * ## Der Nachtrag des Betreibers
     *
     * Die Griffe waren weg, der Haken stand noch da (`docs/55`, Befund 21). Über
     * ihn führt der Weg in die Mehrfachauswahl — und deren gefährliche Knöpfe,
     * **Entfernen** und **Verschieben**, weist `Scheme` genauso ab. Die Auskunft
     * „von 6 sind 0 entfernt" kommt dann **nach** dem Klick auf einen roten
     * Knopf.
     *
     * > **Eine Auswahl ist ein Versprechen, dass die Knöpfe darüber gelten.**
     *
     * Wo die Zeile schon keine eigene Aktion mehr anbietet, darf sie auch keine
     * über den Umweg der Auswahl anbieten.
     *
     * ## Und „Alle auswählen" meint dasselbe „alles"
     *
     * Ohne die zweite Prüfung wäre der fehlende Haken eine Zierde: Der Knopf
     * nähme die sechs trotzdem mit, und der Zählsatz stünde wieder auf „0 von 6".
     */
    public function test_they_cannot_be_ticked_either(): void
    {
        $seite = $this->page();

        $this->assertMatchesRegularExpression(
            '/<input\s+v-if="! entry\.fixed"\s+type="checkbox"/',
            $seite,
            "Die Verzeichnisse des Schemas tragen wieder einen Haken.\n\n".
            'Über ihn führt der Weg in die Mehrfachauswahl, und deren rote Knöpfe weist `Scheme` '.
            'genauso ab — nur erfährt der Kunde es erst hinterher.',
        );

        $this->assertStringContainsString(
            'props.entries.filter((entry) => ! entry.fixed)',
            $seite,
            'Alle auswählen nimmt die Verzeichnisse des Schemas wieder mit. '.
            'Dann ist der fehlende Haken daneben eine Zierde: Ein Knopf, der alles auswählt, muss '.
            'dasselbe „alles" meinen wie die Haken daneben.',
        );
    }

    /**
     * Die Spalte heisst wie überall sonst im Panel.
     *
     * ## Warum das kein Geschmack ist
     *
     * Sie hiess „Griffe", und das Wort gibt es im deutschen technischen Gebrauch
     * für eine Schaltfläche nicht — gemeldet vom Betreiber am 16. August 2026
     * (`docs/55`, Befund 22). Schwerer wiegt, dass dieses Panel längst ein Wort
     * dafür hat: `Databases/Show.vue` und `Audit/Index.vue` schreiben seit P3
     * **Aktion**.
     *
     * > **Ein zweites Wort für dieselbe Sache ist keine Geschmacksfrage — es ist
     * > eine Spalte, die woanders anders heisst.**
     *
     * Geprüft werden **beide** Stellen: Der Kopf trägt den Namen für die breite
     * Ansicht, `data-column` für die Kärtchen unter 720px. Wer nur eine ändert,
     * bekommt zwei Wörter auf einer Seite — je nachdem, wie breit sie gerade ist.
     */
    public function test_the_action_column_is_called_what_the_panel_calls_it(): void
    {
        $seite = $this->page();

        foreach (['<th>Aktion</th>', 'data-column="Aktion"'] as $stelle) {
            $this->assertStringContainsString(
                $stelle,
                $seite,
                sprintf('`%s` fehlt — die Spalte heisst nicht mehr wie im Rest des Panels.', $stelle),
            );
        }

        foreach (['<th>Griffe</th>', 'data-column="Griffe"'] as $alt) {
            $this->assertStringNotContainsString(
                $alt,
                $seite,
                sprintf(
                    "`%s` steht wieder da.\n\n".
                    '„Griffe" ist kein Wort des deutschen technischen Gebrauchs, und das Panel nennt '.
                    'dieselbe Spalte anderswo „Aktion".',
                    $alt,
                ),
            );
        }
    }

    /**
     * Und die Marke trägt genau die sechs, die `Scheme` schützt.
     *
     * **Ohne diese Gegenprobe messen die drei Fälle darüber nur Schreibweisen.**
     * Sie prüfen, dass irgendwo `fixed` steht — nicht, dass es für die richtigen
     * Einträge wahr wird.
     */
    public function test_the_mark_covers_exactly_the_protected_ones(): void
    {
        $geschuetzt = Scheme::fixed();

        $this->assertGreaterThan(
            4,
            count($geschuetzt),
            'Es werden fast keine Verzeichnisse des Schemas gefunden — dann sagt dieser Wächter '.
            'nichts über den Umfang der Marke.',
        );

        foreach ($geschuetzt as $pfad) {
            $this->assertTrue(Scheme::isFixed($pfad), sprintf('`%s` gilt nicht als Gerüst.', $pfad));
        }

        foreach (['/httpdocs/bilder', '/tmp/httpdocs', '/httpdocs/index.html'] as $inhalt) {
            $this->assertFalse(
                Scheme::isFixed($inhalt),
                sprintf(
                    '`%s` gilt als Gerüst. Dann verschwänden die Griffe auch am Inhalt, und der '.
                    'Dateimanager wäre für das, wofür es ihn gibt, unbrauchbar.',
                    $inhalt,
                ),
            );
        }
    }
}
