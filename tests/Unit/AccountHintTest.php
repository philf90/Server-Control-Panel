<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;
use Tests\Support\WithoutPhpComments;

/**
 * Die Kontenseite sagt, warum ein Knopf fehlt — und sagt es mit denselben
 * Worten wie die Ablehnung.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Unter der Kontenliste steht seit A9 ein Satz, der erklärt, warum der letzte
 * aktive Betreiber sich nicht ändern lässt. Sein Kommentar im Quelltext nennt
 * den Zweck: *„Der Grund steht unter der Liste und nicht erst hinter der
 * Ablehnung."*
 *
 * **Er nannte zwei von drei Wegen.** Seit `docs/901` lässt sich ein Adminkonto
 * auch löschen, und `LastOperator` hält den letzten Betreiber auf allen drei
 * Wegen auf — der Satz kannte nur herabstufen und sperren. Ausgerechnet der
 * dritte ist der, dessen Knopf in der Zeile sichtbar **fehlt**; für die eigene
 * Zeile gab es überhaupt keine Auskunft (`docs/903 §3.2`).
 *
 * > **Ein Hinweis, der erklärt, was nicht geht, ist unvollständig, sobald ein
 * > Weg dazukommt — und die Lücke fällt niemandem auf, weil der Satz ja
 * > stimmt.**
 *
 * ## Warum er die Kommentare abstreift
 *
 * Der Absatz, der diese Behebung im Quelltext erklärt, schreibt die alte Zeile
 * wörtlich hin — *„nannte zwei von drei Wegen: herabstufen und sperren"*. Ein
 * Wächter, der roh sucht, fände seine Wörter dort und bliebe grün, nachdem
 * jemand den Satz wieder gekürzt hat. Genau das ist `OutcomeTest` am
 * 1. September passiert.
 *
 * > **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald sie irgendwo
 * > steht — und ein Kommentar, der die entfernte Zeile zitiert, stellt sie für
 * > ihn wieder her.**
 *
 * ## Was er nicht kann
 *
 * Er weiss nicht, dass es **drei** Wege sind. Das ist eine Eigenschaft von
 * `App\Support\Authorization\LastOperator` und wird von
 * `LastOperatorTest` gehalten; hier stehen die drei Verben als Frage: *Nennt
 * der Satz jeden Weg, den die Regel versperrt?* Kommt ein vierter dazu, meldet
 * sich dieser Wächter nicht — dann meldet sich niemand.
 */
final class AccountHintTest extends TestCase
{
    use WithoutMarkupComments;
    use WithoutPhpComments;

    /**
     * Die drei Wege, auf denen `LastOperator` den letzten Betreiber aufhält.
     *
     * @var list<string>
     */
    private const WAYS = ['herabstufen', 'sperren', 'löschen'];

    private function page(): string
    {
        return $this->withoutMarkupComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Accounts/Index.vue'),
        );
    }

    /** Der Satz über den letzten Betreiber nennt jeden Weg. */
    public function test_the_last_operator_hint_names_every_way(): void
    {
        $hinweis = $this->hint('props.operators <= 1');

        foreach (self::WAYS as $weg) {
            $this->assertStringContainsString(
                $weg,
                $hinweis,
                sprintf(
                    "Der Hinweis unter der Kontenliste nennt `%s` nicht:\n\n  %s\n\n".
                    '`LastOperator` hält den letzten Betreiber auf diesem Weg auf, und die Seite '.
                    'erklärt es nicht. Der Leser sucht dann einen Knopf, den es aus einem Grund '.
                    'nicht gibt, den niemand nennt.',
                    $weg,
                    $hinweis,
                ),
            );
        }
    }

    /**
     * Der Satz zur eigenen Zeile ist wörtlich der der Ablehnung.
     *
     * **Zwei Fassungen desselben Satzes laufen auseinander**, und die auf der
     * Seite ist die, die niemand nachliest — sie erscheint ja, bevor etwas
     * schiefgeht.
     */
    public function test_the_self_hint_repeats_the_refusal_word_for_word(): void
    {
        $satz = $this->refusal();

        $this->assertStringContainsString(
            $satz,
            $this->flat($this->page()),
            sprintf(
                "Dieser Satz steht in `AccountController::SELF_REFUSAL` und nicht auf der Seite:\n\n".
                "  %s\n\nWer das eigene Konto löschen will, findet keinen Knopf und keine Auskunft, ".
                'warum — und liest den Satz erst, wenn er die Route von Hand ruft.',
                $satz,
            ),
        );
    }

    /**
     * Und es gibt beide Hinweise.
     *
     * **Ohne diese Untergrenze wäre der Wächter darüber wertlos**, sobald
     * jemand einen der beiden Absätze entfernt: Ein Ausdruck, der seinen Block
     * nicht findet, liefert eine leere Zeichenkette, und die enthält kein Wort.
     */
    public function test_both_hints_are_on_the_page(): void
    {
        $this->assertSame(
            2,
            substr_count($this->page(), 'class="hint"'),
            'Die Kontenseite trägt zwei Hinweise: den über den letzten Betreiber und den über '.
            'das eigene Konto. Fehlt einer, erklärt die Seite einen fehlenden Knopf nicht mehr.',
        );
    }

    /** Der Rumpf des Hinweises, dessen `v-if` diese Bedingung trägt. */
    private function hint(string $bedingung): string
    {
        $muster = sprintf(
            '/<p\s+v-if="%s"\s+class="hint"\s*>(.*?)<\/p>/s',
            preg_quote($bedingung, '/'),
        );

        $this->assertSame(
            1,
            preg_match($muster, $this->page(), $treffer),
            sprintf(
                'Auf der Kontenseite steht kein Hinweis mit der Bedingung `%s`. Dann prüft dieser '.
                'Wächter einen Absatz, den es nicht gibt, und sein Grün bedeutet nichts.',
                $bedingung,
            ),
        );

        return $this->flat($treffer[1]);
    }

    /** Der Satz, mit dem der Controller die Selbstlöschung abweist. */
    private function refusal(): string
    {
        $quelle = $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/AccountController.php'),
        );

        $this->assertSame(
            1,
            preg_match('/SELF_REFUSAL\s*=\s*(.*?);/s', $quelle, $treffer),
            'In `AccountController` gibt es keine Konstante `SELF_REFUSAL` mehr. Dann hat die '.
            'Ablehnung ihren Satz woanders, und dieser Wächter hält nichts mehr aneinander.',
        );

        // Der Satz steht als Verkettung über zwei Zeilen; zusammengesetzt wird
        // er aus seinen Teilen und nicht aus einer Kopie hier.
        preg_match_all("/'([^']*)'/", $treffer[1], $teile);

        $satz = implode('', $teile[1]);

        $this->assertNotSame('', $satz, 'Die Konstante `SELF_REFUSAL` ist leer.');

        return $satz;
    }

    /** Leerraum vereinheitlichen — die Vorlage bricht ihre Sätze um. */
    private function flat(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
