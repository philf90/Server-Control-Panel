<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\SystemLogsTail;
use Tests\Support\WithoutMarkupComments;
use Tests\Support\WithoutPhpComments;

/**
 * Die Naht zwischen `system.logs.tail` und der Seite `/logs`.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Die Fusszeile hat zwei Dinge behauptet, die sie nicht wusste (`docs/86`,
 * Befund 14): „gelesen wurden die letzten 500 Zeilen" kam aus der **Konstante**
 * `MAX_LINES` und nicht aus einer Messung, und der Knopf „Mehr Zeilen" stand
 * unter `props.lines < 500` statt unter der Frage, ob es mehr zu zeigen gibt.
 *
 * > **Eine Grenze, die als Zahl mitgesendet wird, ohne dass jemand nachsieht,
 * > ob sie erreicht wurde, ist eine Behauptung über die Datei und keine über
 * > den Lauf.**
 *
 * ## Beide Richtungen
 *
 * Ein Wächter, der nur prüft, dass die Seite bekannte Felder liest, hält den
 * Fall nicht auf, in dem der Agent ein Feld schickt, das niemand anzeigt —
 * und das ist genau die Familie von `context` (`docs/66`) und
 * `Result::truncated` (`docs/914 §12`):
 *
 * > **Ein Feld, das geschrieben und nie gelesen wird, ist von aussen nicht von
 * > einem zu unterscheiden, das es nicht gibt.**
 *
 * ## Kommentare fallen weg, bevor gesucht wird
 *
 * **Vorsorglich und nicht aus Vorsicht.** Beide Dateien halten ihren
 * Vorzustand im Kommentar fest — `window` steht dort wörtlich, und roh
 * gelesen bliebe dieser Wächter für ein Feld grün, das es nicht mehr gibt.
 *
 * > **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald sie irgendwo
 * > steht — und ein Kommentar, der die entfernte Zeile zitiert, stellt sie für
 * > ihn wieder her.**
 *
 * **Was er nicht hält:** ob die Zahlen stimmen. Das hält
 * {@see LogWindowTest} an echten Dateien.
 */
final class LogFooterTest extends TestCase
{
    use WithoutMarkupComments;
    use WithoutPhpComments;

    private const OP = __DIR__.'/../../agent/src/Ops/SystemLogsTail.php';

    private const SEITE = __DIR__.'/../../resources/js/Pages/Logs/Index.vue';

    /**
     * Felder, die der Agent sendet und die keine Anzeige braucht.
     *
     * `source` und `label` beschriften die Auswahl darüber, `filter` reist
     * zurück in das Formular. Sie stehen hier namentlich und nicht als Muster
     * — eine Ausnahme, die man aufzählen muss, fällt beim Wachsen auf.
     *
     * **`origin` stand hier beinahe dazu.** Es wurde gesendet und von
     * niemandem gelesen, weil die Seite denselben Wert aus `system.logs.list`
     * nimmt. Eine Ausnahme hätte den Befund zugedeckt, den dieser Wächter bei
     * seinem ersten Lauf gemacht hat; entfernt ist das Feld.
     */
    private const OHNE_ANZEIGE = ['source', 'label', 'filter'];

    public function test_every_field_the_agent_sends_is_read_by_the_page(): void
    {
        $gesendet = $this->gesendeteFelder();
        $gelesen = $this->geleseneFelder();

        $this->assertGreaterThanOrEqual(
            8,
            count($gesendet),
            'Unter acht Feldern greift der Ausdruck ins Leere statt zu messen.',
        );

        foreach ($gesendet as $feld) {
            if (in_array($feld, self::OHNE_ANZEIGE, true)) {
                continue;
            }

            $this->assertContains(
                $feld,
                $gelesen,
                sprintf('`%s` wird gesendet und von der Seite nicht gelesen.', $feld),
            );
        }
    }

    public function test_the_page_reads_no_field_the_agent_does_not_send(): void
    {
        $gesendet = $this->gesendeteFelder();

        foreach ($this->geleseneFelder() as $feld) {
            $this->assertContains(
                $feld,
                $gesendet,
                sprintf('Die Seite liest `%s`, und der Agent sendet es nicht.', $feld),
            );
        }
    }

    /**
     * Der Knopf hängt an der Frage, ob es mehr zu zeigen gibt.
     *
     * Das Fenster ist immer {@see SystemLogsTail::MAX_LINES}
     * Zeilen gross; `lines` schneidet nur das Ergebnis. Der Knopf liest also
     * nichts nach — er schneidet weniger ab.
     */
    public function test_the_button_hangs_on_there_being_more(): void
    {
        $markup = $this->markup();

        $this->assertMatchesRegularExpression(
            '/<button[^>]*v-if="props\.result\.truncated"/',
            $markup,
            'Der Knopf „Mehr Zeilen" muss unter `props.result.truncated` stehen.',
        );

        $this->assertStringNotContainsString(
            'v-if="props.lines <',
            $markup,
            'Unter `props.lines < 500` steht der Knopf auch dann, wenn schon alles zu sehen ist.',
        );
    }

    /**
     * Die Zahl der gelesenen Zeilen kommt aus der Antwort und nicht aus einer
     * Konstante.
     */
    public function test_the_sentence_counts_what_was_read(): void
    {
        $markup = $this->markup();

        $this->assertStringContainsString('props.result.read', $markup);
        $this->assertStringNotContainsString(
            'props.result.window',
            $markup,
            '`window` war die Konstante 500 und ist fort.',
        );
    }

    /** @return list<string> */
    private function gesendeteFelder(): array
    {
        $quelle = $this->withoutComments((string) file_get_contents(self::OP));
        $von = strpos($quelle, 'public function execute(');
        $this->assertIsInt($von, 'execute() nicht gefunden.');

        $bis = strpos($quelle, "\n    }", $von);
        $this->assertIsInt($bis, 'Das Ende von execute() nicht gefunden.');

        preg_match_all("/'([a-z_]+)' =>/", substr($quelle, $von, $bis - $von), $treffer);

        return array_values(array_unique($treffer[1]));
    }

    /** @return list<string> */
    private function geleseneFelder(): array
    {
        preg_match_all('/props\.result\.([a-z_]+)/', $this->markup(), $treffer);

        return array_values(array_unique($treffer[1]));
    }

    private function markup(): string
    {
        return $this->withoutMarkupComments((string) file_get_contents(self::SEITE));
    }
}
