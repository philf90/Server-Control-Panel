<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutPhpComments;

/**
 * Die falsche Datei für die Zone des Servers kommt nirgends vor.
 *
 * ## Warum es diese Klasse gibt, obwohl niemand die Datei liest
 *
 * Der Fund aus `docs/81 §2.3r` M11 ist nicht, dass jemand `/etc/timezone`
 * liest — es liest niemand. Er ist, dass es **naheliegt**: Die Datei ist da,
 * sie ist einzeilig, sie braucht kein systemd, und sie beantwortet scheinbar
 * dieselbe Frage.
 *
 * Sie beantwortet sie nicht. Gemessen: Sind die beiden Dateien verschieden
 * gesetzt, nennt `timedatectl show -p Timezone` die des **Symlinks** und nicht
 * die dieser hier. Und das ist kein Randfall: `dpkg-reconfigure tzdata`
 * schreibt beide, ein Mensch mit einem Editor oft nur eine.
 *
 * > **Zwei Dateien, die dieselbe Frage beantworten, und nur eine ist die, der
 * > das System folgt.**
 *
 * > **Eine Regel gegen eine Quelle, die niemand benutzt, hält den Tag auf, an
 * > dem jemand sie naheliegend findet.**
 *
 * ## Was hier steht und was in `ServerZoneSourceTest`
 *
 * Dort steht die Regel über die **richtige** Datei: Nur `ServerZone` liest sie,
 * und die Zone des Servers hat damit genau eine Antwort. Hier steht die Regel
 * über die falsche — und daneben die Naht von `system.time`: ein Aufrufer, ein
 * Unterbefehl, ein Pfad auf der Positivliste.
 *
 * **Und dass `system.time` die Zone gar nicht beantwortet, ist ein Befund vom
 * 6. September 2026.** Der Plan sah sie in seiner Antwort vor;
 * `ServerZoneSourceTest` hat den zweiten Leser gefangen, bevor er in das Repo
 * kam.
 *
 * Framework-frei: Alles sind Fragen an den Quelltext.
 */
final class TimezoneFileTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * `/etc/timezone` kommt in `app/` und `agent/` nirgends vor.
     *
     * Auch nicht im Kommentar — dort stünde die Zeile, die ein Wächter über
     * Zeichenketten wieder grün machte, und dieser hier wäre fälschlich rot.
     * Deshalb werden die Kommentare abgestreift, bevor gesucht wird.
     */
    public function test_the_wrong_file_appears_nowhere(): void
    {
        $gelesen = 0;

        foreach ($this->sources() as $pfad) {
            $gelesen++;

            self::assertStringNotContainsString(
                '/etc/timezone',
                $this->withoutComments((string) file_get_contents($pfad)),
                sprintf(
                    '%s liest /etc/timezone — das System folgt aber dem Symlink daneben, und den liest ServerZone (docs/81 §2.3r M11).',
                    $pfad,
                ),
            );
        }

        self::assertGreaterThan(300, $gelesen, 'Die Dateiliste ist leer oder zu kurz — dann misst dieser Fall nichts.');
    }

    /**
     * Genau eine Stelle **ruft** `timedatectl`, und die Positivliste kennt es.
     *
     * **Gemessen an den Aufrufstellen und nicht an einer Liste im Test.** Eine
     * zweite Stelle wäre die zweite Fassung derselben Frage, und die zweite ist
     * die, die veraltet — derselbe Grund, aus dem `HostnameSourceTest` seit dem
     * vierten Anlauf über `Names` wacht.
     *
     * **`Runner` zählt nicht mit, und das ist keine Ausnahme im Test.** Dort
     * steht der absolute Pfad und kein Aufruf; gesucht wird deshalb der Aufruf
     * und nicht das Wort. Dass beide zusammenpassen, ist die zweite Behauptung
     * dieses Falls — eine Naht, die man nicht hält, reisst still
     * (`PhpSourceUriTest`).
     *
     * **Was er nicht halten kann:** einen Aufruf über einen Programmnamen in
     * einer Variablen. Für ein Werkzeug ist das keiner — derselbe Satz, den
     * shellcheck am 1. September für `$($mass)` gegeben hat.
     */
    public function test_exactly_one_place_calls_it_and_the_allowlist_knows_it(): void
    {
        $stellen = [];

        foreach ($this->sources() as $pfad) {
            $quelle = $this->withoutComments((string) file_get_contents($pfad));

            if (preg_match("/(?:run|stream)\\(\\s*'timedatectl'/", $quelle) === 1) {
                $stellen[] = $pfad;
            }
        }

        self::assertCount(1, $stellen, sprintf(
            'timedatectl wird an %d Stellen gerufen: %s',
            count($stellen),
            implode(', ', $stellen),
        ));

        self::assertStringEndsWith('agent/src/Ops/SystemTime.php', $stellen[0]);

        self::assertStringContainsString(
            "'timedatectl' => '/usr/bin/timedatectl'",
            $this->withoutComments((string) file_get_contents(__DIR__.'/../../agent/src/Runner.php')),
            'Der Aufruf steht da und die Positivliste kennt den Pfad nicht — der Agent gäbe eine Meldung über ein unbekanntes Programm.',
        );
    }

    /**
     * Die Zone des Servers reist nicht durch den Agenten.
     *
     * **Der Befund vom 6. September 2026 als Regel.** `timedatectl show`
     * liefert `Timezone` mit, der Plan sah es in der Antwort vor, und gebaut
     * wäre es der zweite Leser derselben Quelle gewesen: `timedatectl` folgt
     * demselben Symlink wie cron. Zwei Seiten desselben Panels hätten dann
     * verschiedene Serverzonen nennen können — die Cronseite aus `ServerZone`,
     * diese aus dem Agenten.
     *
     * `ServerZoneSourceTest` hat es gefangen, weil die Begründung im Kopf der
     * neuen Klasse den Pfad nannte. Diese Regel hält es auch dann, wenn niemand
     * den Pfad hinschreibt.
     */
    public function test_the_zone_does_not_travel_through_the_agent(): void
    {
        $leser = $this->withoutComments((string) file_get_contents(__DIR__.'/../../agent/src/TimeState.php'));

        self::assertStringNotContainsString('Timezone', $leser,
            'Der Agent liest die Zone — dann gibt es sie zweimal, und die zweite veraltet.');

        $former = $this->withoutComments((string) file_get_contents(__DIR__.'/../../app/Support/Time/ServerTime.php'));

        self::assertStringNotContainsString("'timezone'", $former,
            'Die Zone kommt aus der Antwort des Agenten statt von ihrem Aufrufer.');

        // Und die andere Richtung: Genau eine Stelle beschafft sie, und das ist
        // die, die auch den Agenten fragt. Zwei wären zwei Zeitpunkte für
        // dieselbe Zeile.
        $stellen = [];

        foreach ($this->sources() as $pfad) {
            if (str_contains($this->withoutComments((string) file_get_contents($pfad)), 'ServerZone::known()')) {
                $stellen[] = $pfad;
            }
        }

        self::assertCount(1, $stellen, sprintf(
            'ServerZone::known() wird an %d Stellen gerufen: %s',
            count($stellen),
            implode(', ', $stellen),
        ));

        self::assertStringEndsWith('app/Http/Controllers/GeneralSettingsController.php', $stellen[0]);
    }

    /**
     * Jede PHP-Datei unter `app/` und `agent/src/`.
     *
     * @return list<string>
     */
    private function sources(): array
    {
        $dateien = [];

        foreach (['app', 'agent/src'] as $wurzel) {
            $lauf = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(__DIR__.'/../../'.$wurzel, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($lauf as $datei) {
                if ($datei instanceof \SplFileInfo && $datei->getExtension() === 'php') {
                    $dateien[] = $datei->getPathname();
                }
            }
        }

        sort($dateien);

        return $dateien;
    }
}
