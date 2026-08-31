<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperationSubject;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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
     * Wo Vorgänge entstehen — jede Stelle, die eine Zeile anlegt.
     *
     * @return list<string>
     */
    private function schreiber(): array
    {
        $treffer = [];
        $wurzel = $this->repo().'/app';

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $datei) {
            if (! $datei->isFile() || $datei->getExtension() !== 'php') {
                continue;
            }

            $quelle = $this->withoutComments((string) file_get_contents($datei->getPathname()));

            // **Alle drei Schreibweisen, die im Repo vorkommen.** Der erste
            // Wurf kannte nur `Operation::query()->create(` und
            // `Operation::create(` — und übersah damit ausgerechnet
            // `Operations::dispatch()`, das `new Operation([…])` schreibt.
            //
            // > Ein Ausdruck, der die gewohnte Schreibweise kennt, prüft die
            // > Gewohnheit und nicht die Regel.
            if (preg_match('/(new Operation\(|Operation::(query\(\)->)?create\()/', $quelle) === 1) {
                $treffer[] = substr($datei->getPathname(), strlen($this->repo()) + 1);
            }
        }

        sort($treffer);

        return $treffer;
    }

    /**
     * Die Argumentblöcke jeder anlegenden Stelle einer Datei.
     *
     * **Gezählt wird über Klammern und nicht über Zeilen** — der erste Wurf
     * suchte `'origin' =>` in der **ganzen** Datei und meldete
     * `OperationController`, weil der die Herkunft im Payload der Seite
     * *liest*: `'origin' => $operation->origin,`.
     *
     * > **Ein Ausdruck, der eine Zuweisung sucht, findet jede Lesestelle mit,
     * > solange er den Zusammenhang nicht abgrenzt.**
     *
     * Schliesst eine Klammer nicht, meldet das der Aufrufer als Fehlschlag und
     * nicht als leeren Block: Ein Block, den dieser Leser nicht findet, ist
     * einer, in dem er nichts sieht — und das sähe aus wie „in Ordnung".
     *
     * @return list<string>
     */
    private function anlagen(string $quelle): array
    {
        $bloecke = [];
        $stelle = 0;

        while (preg_match(
            '/(new Operation\(|Operation::(query\(\)->)?create\()/',
            $quelle,
            $treffer,
            PREG_OFFSET_CAPTURE,
            $stelle,
        ) === 1) {
            $offen = (int) $treffer[0][1] + strlen((string) $treffer[0][0]) - 1;
            $tiefe = 0;
            $ende = null;

            for ($i = $offen, $n = strlen($quelle); $i < $n; $i++) {
                $zeichen = $quelle[$i];

                if ($zeichen === '(' || $zeichen === '[') {
                    $tiefe++;
                } elseif ($zeichen === ')' || $zeichen === ']') {
                    $tiefe--;

                    if ($tiefe === 0) {
                        $ende = $i;

                        break;
                    }
                }
            }

            self::assertIsInt($ende, 'Eine anlegende Stelle hat keine schliessende Klammer — dieser Leser misst dort nichts.');

            $bloecke[] = substr($quelle, $offen, $ende - $offen + 1);
            $stelle = $ende;
        }

        return $bloecke;
    }

    /**
     * Die Herkunft wird am **Modell** genommen und nicht am Aufrufer.
     *
     * ## Der Befund, gegen den dieser Fall in seiner heutigen Form steht
     *
     * **Bis zum 31. August 2026 hiess dieser Fall genauso und prüfte etwas
     * anderes:** dass `Operations::dispatch()` die Herkunft setzt. Das tat es —
     * und war eine von **sechzehn** Stellen, die Vorgänge anlegen. Gemessen auf
     * `cloudsrv24` (`docs/94 §6`): Vorgang 727 über `Operations::dispatch()`
     * trug `← /updates`, Vorgang 729 über `Dumps::dispatch()` trug nichts.
     * Beide waren von einer Seite aus ausgelöst worden.
     *
     * > **Ein Wächter, der prüft, dass *eine* Stelle es tut, hat nicht geprüft,
     * > dass es *nur eine* Stelle gibt.**
     *
     * ## Was er jetzt hält
     *
     * Beide Richtungen, und die zweite ist die, die gefehlt hat:
     *
     * 1. Das Modell setzt sie in `booted()`.
     * 2. **Keine** anlegende Stelle setzt sie selbst — sonst gäbe es eine
     *    zweite Fassung derselben Regel, und die zweite veraltet.
     *
     * Die Untergrenze zählt die Schreiber: Findet der Ausdruck nur noch eine
     * Handvoll, ist er ins Leere gelaufen und die zweite Richtung sagt nichts
     * mehr.
     */
    public function test_the_origin_is_taken_on_the_model(): void
    {
        $modell = $this->withoutComments($this->quelle('app/Models/Operation.php'));

        $this->assertMatchesRegularExpression(
            '/static::creating\(function \(Operation \$operation\): void \{\s*if \(\$operation->origin === null\) \{\s*\$operation->origin = Origin::current\(\);/',
            $modell,
            'Das Modell setzt die Herkunft nicht mehr beim Anlegen — dann bekommt sie nur noch, wer daran denkt.',
        );

        $schreiber = $this->schreiber();

        $this->assertGreaterThan(
            8,
            count($schreiber),
            'Es werden kaum anlegende Stellen gefunden — dann prüft die Gegenrichtung nichts.',
        );

        $eigenmaechtig = [];

        foreach ($schreiber as $pfad) {
            foreach ($this->anlagen($this->withoutComments($this->quelle($pfad))) as $anlage) {
                if (str_contains($anlage, "'origin' =>")) {
                    $eigenmaechtig[] = $pfad;

                    break;
                }
            }
        }

        $this->assertSame(
            [],
            $eigenmaechtig,
            "Diese Stellen setzen die Herkunft selbst, obwohl das Modell es tut:\n  "
            .implode("\n  ", $eigenmaechtig)
            ."\n\nZwei Fassungen derselben Regel laufen auseinander, und die zweite ist die, die veraltet.",
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
        $quelle = $this->withoutComments($this->quelle('app/Support/Operations/Origin.php'));

        $this->assertStringContainsString(
            'previousUrl()',
            $quelle,
            'Die Herkunft kommt nicht aus der Sitzung.',
        );

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
     * sähe aus wie eine Auskunft. Und `session()` würfe dort.
     */
    public function test_without_a_session_there_is_no_origin(): void
    {
        $this->assertStringContainsString(
            'hasSession()',
            $this->withoutComments($this->quelle('app/Support/Operations/Origin.php')),
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
