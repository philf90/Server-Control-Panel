<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperationSubject;
use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;
use Tests\Support\WithoutPhpComments;

/**
 * Von einer Vorgangsseite führt ein Weg zurück.
 *
 * ## Der Befund, gegen den es diesen Wächter gibt
 *
 * **Einundzwanzig Weiterleitungen aus sieben Controllern** enden auf
 * `operations.show`, und der Brotkrümel dort trug bis zum 31. August 2026 genau
 * eine Verknüpfung: `Vorgänge`, also die Liste **aller** Vorgänge. Wer eine
 * Domain anlegte, fand von dort nicht zur Domain; wer Pakete einspielte, nicht
 * zurück zu den Updates.
 *
 * Gemeldet hat es der Betreiber, und zwar beim **Erklären** — die Frage lautete,
 * wie man denselben Knopf ein zweites Mal drückt, und die Antwort war „mit dem
 * Zurück-Knopf des Browsers".
 *
 * > **Ein Weg, den man nur erklären kann, indem man den Browser zu Hilfe nimmt,
 * > ist keiner, den die Anwendung anbietet.**
 *
 * ## Zwei Wege, zwei Fragen
 *
 * `origin` sagt „wo war ich", `subject` sagt „worum ging es". Ein Vorgang hat
 * oft beides und einer der Automatik keines von beiden — deshalb sind es zwei
 * Felder und nicht eines.
 *
 * ## Was er nicht hält
 *
 * Dass man überhaupt weggetragen wird. Das ist `docs/92` und für P9 vorgemerkt;
 * ein Test kann es nicht halten, weil es keine Eigenschaft des Quelltextes ist,
 * sondern eine Entscheidung darüber, was ein Vorgang in der Oberfläche sein
 * soll.
 */
final class OperationOriginTest extends TestCase
{
    use WithoutMarkupComments;
    use WithoutPhpComments;

    private function repo(): string
    {
        return dirname(__DIR__, 2);
    }

    private function quelle(string $pfad): string
    {
        $inhalt = file_get_contents($this->repo().'/'.$pfad);

        self::assertIsString($inhalt, $pfad.' ist nicht lesbar.');

        return $inhalt;
    }

    /**
     * Die Herkunft wird an **einer** Stelle genommen.
     *
     * Einundzwanzig Aufrufstellen, die sie einzeln mitgäben, wären
     * einundzwanzig Gelegenheiten, sie zu vergessen — und die vergessene fiele
     * niemandem auf, weil eine fehlende Herkunft aussieht wie ein Vorgang der
     * Automatik.
     */
    public function test_the_origin_is_taken_in_one_place(): void
    {
        $quelle = $this->withoutComments($this->quelle('app/Support/Operations/Operations.php'));

        $this->assertStringContainsString(
            "'origin' => \$this->origin(),",
            $quelle,
            'Der Vorgang bekommt seine Herkunft nicht beim Absetzen.',
        );

        $this->assertStringContainsString(
            'previousUrl()',
            $quelle,
            'Die Herkunft kommt nicht aus der Sitzung.',
        );
    }

    /**
     * Und sie kommt **nicht** aus `url()->previous()`.
     *
     * Der Helfer fällt der Reihe nach auf den `Referer` und dann auf die Wurzel
     * der Anwendung zurück. Ein Vorgang der Zertifikatsautomatik trüge damit `/`
     * als Herkunft, und die Seite böte einen Weg zurück dorthin, wo niemand war.
     *
     * > **Ein Rückfall, der immer etwas liefert, macht aus „unbekannt" eine
     * > falsche Auskunft.**
     */
    public function test_the_origin_has_no_fallback(): void
    {
        $quelle = $this->withoutComments($this->quelle('app/Support/Operations/Operations.php'));

        $this->assertStringNotContainsString(
            'url()->previous()',
            $quelle,
            'Der Rückfall auf die Wurzel macht aus „von keiner Seite" ein „von der Übersicht".',
        );
    }

    /**
     * Ohne Sitzung gibt es keine Herkunft.
     *
     * Die Konsole, die Warteschlange und jeder Lauf der Automatik setzen ohne
     * Sitzung ab. Dort ist `null` die Wahrheit — ein Wert, den man erfände,
     * sähe aus wie eine Auskunft.
     */
    public function test_without_a_session_there_is_no_origin(): void
    {
        $quelle = $this->withoutComments($this->quelle('app/Support/Operations/Operations.php'));

        $this->assertStringContainsString(
            'hasSession()',
            $quelle,
            'Ein Lauf der Automatik bekäme eine Herkunft, die es nicht gibt.',
        );
    }

    /**
     * Der Brotkrümel zeigt sie, und zwar als Verknüpfung.
     *
     * Ein Pfad, der nur dasteht, ist kein Weg zurück — er ist ein Hinweis, den
     * man abtippen müsste.
     */
    public function test_the_breadcrumb_links_the_origin(): void
    {
        $quelle = $this->withoutMarkupComments($this->quelle('resources/js/Pages/Operations/Show.vue'));

        $this->assertMatchesRegularExpression(
            '/<Link\s+:href="props\.operation\.origin"/',
            $quelle,
            'Die Herkunft steht nicht als Verknüpfung im Brotkrümel.',
        );

        $this->assertStringContainsString(
            'v-if="props.operation.origin"',
            $quelle,
            'Ein Vorgang ohne Herkunft zeigte einen leeren Verweis.',
        );

        // **Der Verweis trägt die Frage, die Beschriftung nicht.** Gemessen bei
        // 390 px an `/updates?nur=…&herkunft=…&name=…`: Der volle Pfad nimmt
        // drei Zeilen über dem Seitentitel. Gezeigt wird deshalb der Pfad ohne
        // seine Frage — und verwiesen wird auf beides, sonst käme man auf eine
        // ungefilterte Liste zurück.
        $this->assertStringContainsString(
            "(props.operation.origin ?? '').split('?')[0]",
            $quelle,
            'Die Beschriftung trägt die ganze Frage — drei Zeilen Filterwerte sind keine Ortsangabe.',
        );

        $this->assertStringNotContainsString(
            ':href="herkunft"',
            $quelle,
            'Der Verweis zeigt auf den gekürzten Pfad — der Filter käme nicht zurück.',
        );
    }

    /**
     * Jeder Pfad, den {@see OperationSubject} nennt, ist eine angemeldete Route.
     *
     * **Der Fehler, den dieses Repo sechsmal eingeholt hat:** eine Zeichenkette,
     * die auf etwas verweist, ohne dass ein Typ, ein Test oder ein Werkzeug den
     * Bezug prüft. Hier wäre es ein Verweis, der ins Leere führt — und zwar
     * genau dann, wenn jemand eine Route umbenennt.
     *
     * **Gefragt wird `routes/web.php` als Text und nicht die Routenablage.**
     * Dieser Wächter erbt von PHPUnits `TestCase` und nicht von Laravels: Ohne
     * Anwendung hat die Fassade keine Wurzel, und mit Anwendung liefe er in
     * diesem Container gar nicht. Derselbe Griff wie in
     * `OperatorControlTest::abilityOfRoute()` — der Name steht als Text und
     * nicht als Marke, weil Pint aus einem `{@see \…}` einen `use`-Eintrag
     * macht und ein framework-freier Wächter davon framework-abhängig wird.
     *
     * **Und gefragt wird nach einem `GET`.** Ein Verweis, dessen Pfad nur als
     * `DELETE` angemeldet ist, führt nicht ins Leere, sondern auf einen 405 —
     * für den Leser derselbe Ausgang und für den Test ein anderer.
     */
    public function test_every_path_the_subject_names_is_a_route(): void
    {
        $quelle = $this->withoutComments($this->quelle('app/Enums/OperationSubject.php'));

        preg_match_all("/'(\/[a-z-]+)\/'\.\\$/", $quelle, $treffer);

        $pfade = array_values(array_unique($treffer[1]));

        $this->assertGreaterThan(
            0,
            count($pfade),
            'Die Aufzählung nennt keinen Pfad mehr — dann prüft dieser Test nichts.',
        );

        $routen = $this->withoutComments($this->quelle('routes/web.php'));

        foreach ($pfade as $pfad) {
            $this->assertMatchesRegularExpression(
                sprintf("/Route::get\('%s\/\{[a-z_]+\}'/", preg_quote($pfad, '/')),
                $routen,
                sprintf('`%s/{…}` ist keine angemeldete GET-Route — der Verweis führte ins Leere.', $pfad),
            );
        }
    }

    /**
     * Und die Seite liest den Gegenstand überhaupt.
     *
     * `subject_type` und `subject_id` gab es seit dem 4. August 2026 und bis zum
     * 31. hat sie keine Oberfläche gelesen — derselbe Fall wie `context` im
     * Protokoll (`docs/66`).
     *
     * > **Ein Feld, das geschrieben und nie gelesen wird, ist von aussen nicht
     * > von einem zu unterscheiden, das es nicht gibt.**
     */
    public function test_the_page_reads_the_subject(): void
    {
        $quelle = $this->withoutMarkupComments($this->quelle('resources/js/Pages/Operations/Show.vue'));

        $this->assertStringContainsString('props.operation.subject.name', $quelle);
        $this->assertStringContainsString('props.operation.subject.label', $quelle);

        // Eine Sicherung hat keine eigene Seite, und eine gelöschte Datenbank
        // erst recht nicht. Der Name steht dann ohne Verknüpfung da — das ist
        // eine Auskunft und keine Sackgasse.
        $this->assertStringContainsString(
            'v-if="props.operation.subject.path"',
            $quelle,
            'Ein Gegenstand ohne Seite bekäme einen Verweis auf `null`.',
        );
    }
}
