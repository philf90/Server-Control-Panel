<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Feature\CronIngestTest;
use Tests\Support\InheritedNames;

/**
 * Kein Testfall gibt einer eigenen Methode den Namen einer `final`-Methode
 * seiner Basisklasse.
 *
 * **Das ist die fünfte Fassung desselben Fehlers in diesem Repo**, und die
 * erste, die einen Wächter bekommt. Vorher: `count()` in einem Testfall,
 * `configure()` in einem Artisan-Kommando, `for()` in einer Factory,
 * `matches()` wieder in einem Testfall — und am 19. August 2026 `run()` in
 * {@see CronIngestTest}.
 *
 * **Warum er teuer ist.** Der Fehler schlägt beim **Laden** der Klasse zu, nicht
 * beim Ausführen: `php artisan test` endet mit Rückgabewert 255, bevor ein
 * einziger Test läuft. Nicht eine Datei steht still, sondern alle. Und `php -l`
 * sieht davon nichts, weil die Datei für sich genommen gültiges PHP ist.
 *
 * > **Ein Name, der der Basisklasse gehört, bricht nicht den Test, sondern den
 * > Lauf.**
 *
 * **Warum ihn zu kennen nicht genügte.** Die Regel stand seit P5 in `CLAUDE.md`.
 * Sie hat den vierten Fall gefangen und den fünften nicht — weil man beim
 * Einziehen einer privaten Hilfsmethode nicht daran denkt, dass ein Testfall
 * auch eine abgeleitete Klasse ist.
 *
 * > **Eine Regel, an die man sich erinnern muss, ist keine Regel, sondern eine
 * > Gewohnheit.**
 */
final class BaseMethodClashTest extends TestCase
{
    /**
     * Die `final`-Methoden der Basisklasse — gefragt und nicht aufgeschrieben.
     *
     * **Eine Liste würde veralten.** PHPUnit macht mit jeder Fassung mehr
     * Methoden `final`; eine abgeschriebene Liste kennt die neuen nicht und
     * lässt genau die durch, die als Nächstes zuschlagen.
     *
     * @return list<string>
     */
    private function finals(): array
    {
        $namen = [];

        foreach ((new ReflectionClass(TestCase::class))->getMethods() as $methode) {
            if ($methode->isFinal() && ! $methode->isPrivate()) {
                $namen[] = $methode->getName();
            }
        }

        sort($namen);

        return $namen;
    }

    /**
     * **Die Gegenprobe, und sie kommt zuerst.**
     *
     * Findet die Spiegelung nichts, prüft der Fall darunter jede Datei gegen
     * eine leere Liste — und ist grün, ohne etwas gesehen zu haben. Die
     * Untergrenze ist die eine Zahl, die das verhindert; vier sind die
     * Methoden, die dieses Projekt schon getroffen haben.
     */
    public function test_the_base_class_really_has_final_methods(): void
    {
        $this->assertGreaterThanOrEqual(
            4,
            count($this->finals()),
            'In PHPUnit\Framework\TestCase sind fast keine Methoden final — die Spiegelung trifft nicht mehr.',
        );
    }

    /**
     * Und keine Datei unter `tests/` deklariert einen dieser Namen.
     *
     * **Auch die Traits unter `tests/Support`.** Sie werden *in* einen Testfall
     * gezogen, und eine Methode aus einem Trait kollidiert dort genauso — nur
     * zeigt die Meldung dann auf die Datei, die den Trait benutzt.
     */
    public function test_no_test_declares_a_name_the_base_class_owns(): void
    {
        $verboten = $this->finals();
        $gefunden = [];
        $gesehen = 0;

        foreach ($this->sources() as $pfad) {
            /*
             * **Gelesen wird über {@see InheritedNames::declarations()} und
             * nicht über einen eigenen Ausdruck**, und das ist eine Behebung
             * vom 22. August 2026. Hier stand ein zweiter, verankerter
             * Ausdruck über dieselbe Frage — zwei Fassungen derselben Regel,
             * und die zweite ist die, die veraltet.
             *
             * Sie ist es auch geworden: Der Ausdruck las die **Datei** und
             * schlug jede Funktion darin der Testklasse zu. Ein Double neben
             * seinem Testfall in derselben Datei und eine anonyme Klasse in
             * einer Methode haben damit drei Fehlbefunde erzeugt — jeder mit
             * der Behauptung, `php artisan test` ende mit 255, während im
             * selben Lauf 2295 Tests durchliefen.
             *
             * > **Ein Wächter, der aus dem falschen Grund rot ist, wird beim
             * > nächsten Umbau aus dem falschen Grund grün.**
             *
             * Der Grund für die Token statt eines Ausdrucks bleibt derselbe
             * wie dort: `SandboxCredentialsTest` trägt die Zeichenkette
             * `'public function run(Context $context, …'`, und ein Muster ohne
             * Anker meldete sie als Deklaration.
             */
            $erklaert = InheritedNames::declarations((string) file_get_contents($pfad));

            $gesehen += count($erklaert);

            foreach (array_keys($erklaert) as $name) {
                if (in_array($name, $verboten, true)) {
                    $gefunden[] = basename($pfad).'::'.$name.'()';
                }
            }
        }

        /*
         * **Die Untergrenze, ohne die dieser Fall ein Loch hat.** Gäbe
         * {@see self::sources()} nichts zurück oder träfe der Ausdruck nicht
         * mehr, verglichen sich zwei leere Listen zu „keine Kollision" — und
         * der Wächter wäre grün, ohne eine einzige Zeile gelesen zu haben.
         * Genau diese Falle hat dieses Vorgehen schon dreimal getroffen.
         */
        $this->assertGreaterThanOrEqual(
            500,
            $gesehen,
            'Der Ausdruck findet fast keine Methoden — dann sagt dieser Fall nichts.',
        );

        $this->assertSame([], $gefunden, implode("\n", [
            'Diese Methoden tragen einen Namen, der der Basisklasse gehoert:',
            ...$gefunden,
            '',
            'Sie brechen beim LADEN der Klasse — php artisan test endet mit 255,',
            'bevor ein einziger Test laeuft, und php -l sieht davon nichts.',
        ]));
    }

    /**
     * Was `sources()` liest, ist wirklich ein Testfall oder ein Trait.
     *
     * **Das ist die Zusage, auf der dieser Wächter steht.** Er vergleicht jede
     * gelesene Datei gegen die `final`-Methoden von
     * `PHPUnit\Framework\TestCase` — und das ist nur dann die richtige
     * Basisklasse, wenn die Datei auch von ihr erbt. Eine Klasse, die von
     * etwas anderem erbt und `run()` erklärt, bekäme sonst einen Befund über
     * eine Basisklasse, die sie nie gesehen hat.
     *
     * **Vorher hing diese Zusage an einem Eingriff, der sie nicht mehr prüfen
     * konnte.** Er nahm die Eingrenzung heraus und erwartete, dass die drei
     * Attrappen unter `tests/Support` gemeldet werden — die haben einen
     * eigenen Konstruktor. Seit {@see InheritedNames::declarations()} nur noch
     * sammelt, was auf der Klasse landet, werden sie ohnehin nicht gelesen:
     * Sie erben von nichts. Die eine Wand hielt, also schlug die Gegenprobe
     * für die andere nicht mehr aus.
     *
     * > **Eine Gegenprobe, die nur eine von zwei Wänden wegnimmt, schlägt
     * > nicht aus, solange die andere hält — und sagt dann über keine von
     * > beiden etwas.**
     *
     * Geprüft wird deshalb die Eingrenzung selbst statt ihrer Folge.
     */
    public function test_every_source_is_really_a_test_case(): void
    {
        $fremd = [];

        foreach ($this->sources() as $pfad) {
            $quelltext = (string) file_get_contents($pfad);

            $istTestfall = preg_match('/\bclass\s+\w+\s+extends\s+\S*TestCase\b/', $quelltext) === 1;
            $istTrait = preg_match('/^\s*trait\s+\w+/m', $quelltext) === 1;

            if (! $istTestfall && ! $istTrait) {
                $fremd[] = basename($pfad);
            }
        }

        $this->assertSame([], $fremd, implode("\n", [
            'Diese gelesenen Dateien sind weder Testfall noch Trait:',
            ...$fremd,
            '',
            'Der Vergleich unten haelt jede gelesene Datei gegen die final-Methoden',
            'von PHPUnit\Framework\TestCase. Fuer eine Klasse, die von etwas anderem',
            'erbt, ist das die falsche Basisklasse — und ihr Befund eine Erfindung.',
        ]));
    }

    /**
     * Quelltexte, an denen der Geltungsbereich entschieden wird.
     *
     * **Sie sind der Prüfkörper dieses Wächters.** Ohne sie steht seine Regel
     * auf dem Bestand: Solange zufällig keine Datei eine zweite Klasse hat,
     * ist jede Fassung des Lesers grün — auch die, die die Datei liest statt
     * der Klasse. Genau so ist der Fehler vom 22. August 2026 entstanden.
     *
     * **Die Klasse im Prüfkörper heisst `Sample` und endet ausdrücklich nicht
     * auf `…Test`.** `GuardReachTest` sammelt jeden so endenden Namen aus dem
     * Quelltext ein und verlangt einen Wächter dazu; ein erfundener Name in
     * einem Prüfkörper sieht von dort aus wie ein Versprechen auf einen Test,
     * den es nicht gibt. Beim ersten Wurf hiess sie so — und beim zweiten
     * stand der alte Name noch in genau diesem Absatz, was der Wächter
     * ebenfalls gemeldet hat. Er unterscheidet Erklärung und Erwähnung nicht,
     * und das ist richtig so.
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function quellen(): array
    {
        return [
            'echte Kollision' => [
                '<?php final class Sample extends TestCase { private function run(): void {} }',
                ['run'],
            ],
            'zweite Klasse in derselben Datei' => [
                '<?php final class Sample extends TestCase { public function test_a(): void {} }'
                    .' final class Double implements Bar { public function run() {} }',
                ['test_a'],
            ],
            'anonyme Klasse in einer Methode' => [
                '<?php final class Sample extends TestCase { public function t(): void {'
                    .' $x = new class(1) implements Bar { public function __construct(int $a) {} }; } }',
                ['t'],
            ],
            'ein Trait zaehlt mit' => [
                '<?php trait Helper { public function run(): void {} }',
                ['run'],
            ],
            'Foo::class ist keine Erklaerung' => [
                '<?php final class Sample extends TestCase { public function t(): void { $x = Bar::class; } }'
                    .' final class Double { public function run() {} }',
                ['t'],
            ],
            'ein Interface steht fuer sich' => [
                '<?php final class Sample extends TestCase { public function t(): void {} }'
                    .' interface Bar { public function run(); }',
                ['t'],
            ],
            'eine Zeichenkette ist kein Quelltext' => [
                '<?php final class Sample extends TestCase { public function t(): void {'
                    .' $s = "public function run(Context $c)"; } }',
                ['t'],
            ],
            /*
             * **Die zweite Methode steht in derselben Klasse, und das ist der
             * Punkt.** Beim ersten Anlauf stand sie in einer zweiten — die
             * zählt ohnehin nicht mit, und der Fall war unter der kaputten
             * Fassung genauso grün wie unter der heilen. Zählt die Klammer
             * einer Einsetzung nicht mit, geht die Tiefe um eins zu tief, das
             * schliessende `}` der Methode wirft den Rahmen der Klasse weg —
             * und alles danach steht ausserhalb.
             *
             * > **Eine Null ist nur dann eine Messung, wenn daneben etwas
             * > anderes als Null steht.**
             */
            'eine Einsetzung verschiebt die Tiefe nicht' => [
                '<?php final class Sample extends TestCase { public function t(): void { $s = "a {$this->x} b"; }'
                    .' public function run(): void {} }',
                ['t', 'run'],
            ],
        ];
    }

    /**
     * Gelesen wird die Klasse und nicht die Datei.
     *
     * **Drei Fehlbefunde an einem Tag** — ein Double neben seinem Testfall,
     * eine anonyme Klasse in einer Methode —, und jeder behauptete,
     * `php artisan test` ende mit 255. Im selben Lauf liefen 2295 Tests durch.
     *
     * > **Ein Wächter, der seinen Geltungsbereich an der Datei festmacht,
     * > prüft die Datei und nicht die Klasse.**
     *
     * @param  list<string>  $erwartet
     */
    #[DataProvider('quellen')]
    public function test_only_what_lands_on_the_class_is_read(string $quelle, array $erwartet): void
    {
        $this->assertSame(
            $erwartet,
            array_keys(InheritedNames::declarations($quelle)),
            'Der Leser sammelt etwas anderes ein, als auf der Klasse landet.',
        );
    }

    /**
     * Und die Sichtbarkeit bleibt dabei erhalten.
     *
     * Sie ist die zweite Hälfte der Regel: `protected function configure()` in
     * einem Kommando, hier als `private` eingezogen — und `artisan` stand mit
     * allen Kommandos still.
     */
    public function test_the_visibility_survives_the_scope(): void
    {
        $gelesen = InheritedNames::declarations(
            '<?php final class Sample extends TestCase { private function run(): void {} protected function zwei(): void {} }',
        );

        $this->assertSame(['run' => 'private', 'zwei' => 'protected'], $gelesen);
    }

    /**
     * Jede Datei, für die die Regel überhaupt gilt.
     *
     * **Und das sind nicht alle unter `tests/`.** Der erste Wurf hat schlicht
     * `Unit`, `Feature` und `Support` eingesammelt und daraufhin drei Attrappen
     * gemeldet, die einen eigenen Konstruktor haben —
     * `ScriptedDnsCredentials`, `ScriptedExchange`, `ScriptedLookup`.
     * `TestCase::__construct()` ist tatsächlich `final`, aber diese drei erben
     * gar nicht davon: Sie sind eigenständige Double für Schnittstellen der
     * Anwendung und liegen nur zufällig im selben Verzeichnis.
     *
     * > **Ein Wächter, der seinen Geltungsbereich am Ordner festmacht, prüft
     * > den Ordner und nicht die Regel.**
     *
     * Im Bereich liegt eine Datei, wenn sie eine Klasse deklariert, die von
     * etwas auf `TestCase` erbt — oder einen **Trait**: Der wird in einen
     * Testfall hineingezogen, und eine Trait-Methode verdrängt die geerbte, was
     * bei einer `final`-Methode denselben fatalen Fehler gibt.
     *
     * @return list<string>
     */
    private function sources(): array
    {
        $wurzel = dirname(__DIR__);
        $dateien = [];

        foreach (['Unit', 'Feature', 'Support'] as $ordner) {
            foreach (glob($wurzel.'/'.$ordner.'/*.php') ?: [] as $pfad) {
                $quelltext = (string) file_get_contents($pfad);

                $istTestfall = preg_match('/\bclass\s+\w+\s+extends\s+\S*TestCase\b/', $quelltext) === 1;
                $istTrait = preg_match('/^\s*trait\s+\w+/m', $quelltext) === 1;

                if ($istTestfall || $istTrait) {
                    $dateien[] = $pfad;
                }
            }
        }

        return $dateien;
    }
}
