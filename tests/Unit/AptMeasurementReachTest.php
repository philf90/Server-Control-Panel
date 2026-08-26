<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutHashComments;

/**
 * Die Messrunde vor A1 läuft auf allen vier Zielplattformen — und sie
 * entscheidet, statt nur zu drucken.
 *
 * **Warum es diesen Wächter gibt.** `docs/81 §2.3` führte die Messrunde für
 * Debian 12, Debian 13 und Ubuntu 22.04 als offen; im Plan stand für sie eine
 * Erwartung statt einer Zahl. Vorgesehen war „ein Lauf je Plattform" von Hand.
 *
 * > **Eine Messung, die einmal jemand von Hand macht, ist ein Datum. Eine, die
 * > die CI macht, ist eine Zusage.**
 *
 * Eine Zusage ist sie aber nur, solange drei Dinge stimmen, und keines davon
 * sieht man dem Lauf an:
 *
 * 1. Die Messrunde deckt **dieselben** Plattformen ab wie der Installationsjob.
 *    Zwei Listen, die dasselbe meinen, laufen auseinander — und dann misst die
 *    Runde drei Plattformen, während vier ausgeliefert werden.
 * 2. Beide Skripte werden gerufen. Das eine misst den Bestand, das andere
 *    stellt die vier Fälle her, die von allein nicht vorkommen.
 * 3. Das Fälle-Skript **entscheidet über seinen Rückgabewert**. Ein Lauf, der
 *    nur druckt, meldet einen ausgefallenen Fall genauso grün wie einen
 *    hergestellten.
 *
 * **Gelesen wird der Lauf ohne seine Kommentarzeilen, und der Grund ist
 * gemessen.** Die erste Begründung hier lautete „ein Wächter, der zählt, bekäme
 * die Namen aus dem Kommentar doppelt" — sie war falsch, denn dieser Wächter
 * zählt nichts. Nachgeprüft: Ohne den Abstreifer bleibt er an der heilen Datei
 * genauso grün.
 *
 * Tragend wird der Abstreifer bei der wahrscheinlichsten Mutation überhaupt —
 * jemand **kommentiert den Aufruf aus**. Gemessen an derselben kaputten Quelle:
 * mit Abstreifer rot mit der richtigen Meldung, ohne ihn grün, weil der Wächter
 * die Zeichenkette in dem Kommentar findet, zu dem der Aufruf gerade geworden
 * ist.
 *
 * > **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald die
 * > Zeichenkette irgendwo steht — auch in dem Kommentar, der aus ihr wurde.**
 */
final class AptMeasurementReachTest extends TestCase
{
    use WithoutHashComments;

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function ci(): string
    {
        return $this->withoutHashComments(
            (string) file_get_contents($this->root().'/.github/workflows/ci.yml'),
        );
    }

    /**
     * Den Block eines Jobs herausschneiden.
     *
     * Über die Einrückung und nicht über den Namen des nächsten Jobs: `image:`
     * steht in drei Jobs dieses Laufs, und wer den falschen Block liest,
     * vergleicht zwei Listen, die nie dieselbe sein sollten.
     */
    private function job(string $name): string
    {
        $treffer = preg_match(
            '/^  '.preg_quote($name, '/').':$(.*?)(?=^  [a-z-]+:$)/ms',
            $this->ci()."\n  ende:\n",
            $m,
        );

        $this->assertSame(1, $treffer, sprintf('Der Job %s steht nicht im Lauf.', $name));

        return $m[1];
    }

    /** @return list<string> */
    private function images(string $job): array
    {
        preg_match_all('/^\s*image:\s*(\S+)$/m', $this->job($job), $m);

        $bilder = array_values(array_unique($m[1]));
        sort($bilder);

        return $bilder;
    }

    /**
     * Die Messrunde deckt dieselben Plattformen ab wie der Installationsjob.
     *
     * Nicht gegen eine Liste im Test: Die stünde als dritte Fassung daneben und
     * veraltete zuerst. Verglichen werden die beiden Listen, die es im Lauf
     * wirklich gibt.
     */
    public function test_the_measurement_covers_every_platform_that_is_installed_on(): void
    {
        $installation = $this->images('integration');
        $messrunde = $this->images('apt-messrunde');

        $this->assertGreaterThanOrEqual(
            4,
            count($installation),
            'Der Installationsjob nennt kaum noch Plattformen — der Vergleich misst dann nichts.',
        );

        $this->assertSame(
            $installation,
            $messrunde,
            'Die apt-Messrunde deckt andere Plattformen ab als der Installationsjob.',
        );
    }

    /** Beide Skripte werden gerufen — das messende und das herstellende. */
    public function test_the_job_calls_both_scripts(): void
    {
        $job = $this->job('apt-messrunde');

        foreach (['tests/apt-messen.sh', 'tests/apt-faelle-messen.sh'] as $skript) {
            $this->assertStringContainsString(
                $skript,
                $job,
                sprintf('Die Messrunde ruft %s nicht auf.', $skript),
            );
        }
    }

    /**
     * Das Fälle-Skript entscheidet über seinen Rückgabewert.
     *
     * Ohne das wäre der Job ein Drucker: Er lüde ein Protokoll hoch, in dem
     * „FALL NICHT HERGESTELLT" steht, und meldete Grün.
     */
    public function test_the_case_script_decides_instead_of_printing(): void
    {
        $skript = (string) file_get_contents($this->root().'/tests/apt-faelle-messen.sh');

        $this->assertMatchesRegularExpression(
            '/exit\s+"\$\{OFFEN\}"/',
            $skript,
            'Das Fälle-Skript endet ohne Rückgabewert über die Zahl der offenen Fälle.',
        );
    }

    /**
     * Jeder Fall nennt die Messung, für die er den Nachbarwert liefert — und
     * die Messung gibt es.
     *
     * Der Fehler, gegen den sich das richtet, ist der häufigste dieses
     * Repositorys: eine Zeichenkette, die auf etwas verweist, ohne dass etwas
     * den Bezug prüft. Benennt jemand F3 auf M7 um, zeigt der Verweis ins Leere
     * und niemand merkt es.
     */
    public function test_every_case_names_a_measurement_that_exists(): void
    {
        $faelle = (string) file_get_contents($this->root().'/tests/apt-faelle-messen.sh');
        $messen = (string) file_get_contents($this->root().'/tests/apt-messen.sh');

        preg_match_all('/^titel "F\d+ — .*\((M\d+)\)"$/m', $faelle, $m);

        $this->assertGreaterThanOrEqual(
            4,
            count($m[1]),
            'Der Ausdruck findet die Fälle nicht mehr — dann ist über ihre Verweise nichts gesagt.',
        );

        foreach ($m[1] as $messung) {
            $this->assertStringContainsString(
                'titel "'.$messung.' —',
                $messen,
                sprintf('Ein Fall nennt %s; diese Messung gibt es in apt-messen.sh nicht.', $messung),
            );
        }
    }

    /** Ausführbar — sonst scheitert der Aufruf im Container. */
    public function test_both_scripts_are_executable(): void
    {
        foreach (['apt-messen.sh', 'apt-faelle-messen.sh'] as $skript) {
            $this->assertTrue(
                is_executable($this->root().'/tests/'.$skript),
                sprintf('tests/%s ist nicht ausführbar.', $skript),
            );
        }
    }
}
