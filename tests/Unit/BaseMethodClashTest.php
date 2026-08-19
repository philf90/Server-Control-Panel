<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Feature\CronIngestTest;

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
             * **Verankert am Zeilenanfang, und das ist kein Schönheitsgriff.**
             * `SandboxCredentialsTest` behauptet etwas über den Quelltext des
             * Agenten und trägt dafür die Zeichenkette
             * `'public function run(Context $context, …'` — ein Muster ohne
             * Anker meldete sie als Deklaration. Ein Wächter, der beim ersten
             * Lauf einen Fehler erfindet, wird abgeschaltet und nicht befolgt.
             */
            preg_match_all(
                '/^\s*(?:(?:final|abstract|public|protected|private|static)\s+)*function\s+(\w+)\s*\(/m',
                (string) file_get_contents($pfad),
                $treffer,
            );

            $gesehen += count($treffer[1]);

            foreach ($treffer[1] as $name) {
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
     * Jede Datei, für die die Regel überhaupt gilt.
     *
     * **Und das sind nicht alle unter `tests/`.** Der erste Wurf hat schlicht
     * `Unit`, `Feature` und `Support` eingesammelt und daraufhin drei Attrappen
     * gemeldet, die einen eigenen Konstruktor haben —
     * `ScriptedDnsCredentials`, `ScriptedExchange`, `ScriptedLookup`.
     * `TestCase::__construct()` ist tatsächlich `final`, aber diese drei erben
     * gar nicht davon: Sie sind eigenständige Doppel für Schnittstellen der
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
