<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\FindingCheck;
use App\Support\Diagnose\Catalog;
use App\Support\Diagnose\Check;
use App\Support\Diagnose\Checks\Agent;
use App\Support\Diagnose\FindingLog;
use App\Support\Diagnose\Run;
use App\Support\Diagnose\RunLog;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SrvPanel\Agent\Ops\SystemDiagnose;

/**
 * Der Nachtlauf (A10 Schritt 6, `docs/98 §4`).
 *
 * ## Drei Regeln, und jede hat einen Fehler hinter sich
 *
 * **Ein Zeitstempel für alle.** Käme er aus jeder Prüfung, stünden auf der
 * Seite so viele Werte für „zuletzt gemessen", wie es Prüfungen gibt.
 *
 * **Eine gescheiterte Prüfung hält den Lauf nicht auf, und sie löscht nichts.**
 * Ihre alten Befunde sind nicht widerlegt, sondern ungeprüft — ein `replace()`
 * mit einer leeren Liste hiesse „geprüft, nichts gefunden", und das ist der
 * Fehler aus `docs/44`.
 *
 * **Jeder Schlüssel hat genau einen Schreiber.** `FindingLog::replace()`
 * ersetzt alle Zeilen einer Prüfung; zwei Schreiber löschten einander die
 * Befunde weg, und welcher zuletzt liefe, entschiede die Reihenfolge.
 *
 * Framework-frei bis auf `Carbon`.
 */
final class DiagnoseRunTest extends TestCase
{
    /**
     * Ein Buch, das sich merkt, was es wann eingetragen bekam.
     *
     * `Settings` ist `final` — deshalb gibt es {@see RunLog} überhaupt:
     * „Eine Klasse, die sich nicht ersetzen lässt, hat keinen Test."
     */
    private function runLog(): RunLog
    {
        return new class implements RunLog
        {
            public ?Carbon $eingetragen = null;

            public int $laeufe = 0;

            public function record(Carbon $ranAt): void
            {
                $this->eingetragen = $ranAt;
                $this->laeufe++;
            }

            public function lastRunAt(): ?string
            {
                return $this->eingetragen?->toDateTimeString();
            }
        };
    }

    /** @param list<FindingCheck> $writes */
    private function check(array $writes, ?callable $work = null): Check
    {
        return new class($writes, $work) implements Check
        {
            public int $laeufe = 0;

            public ?Carbon $gesehen = null;

            /** @param list<FindingCheck> $writes */
            public function __construct(private readonly array $writes, private $work) {}

            public function writes(): array
            {
                return $this->writes;
            }

            public function run(Carbon $measuredAt, FindingLog $log): void
            {
                $this->laeufe++;
                $this->gesehen = $measuredAt;

                if ($this->work !== null) {
                    ($this->work)();
                }
            }
        };
    }

    public function test_every_check_sees_the_same_moment(): void
    {
        $a = $this->check([FindingCheck::UnitState]);
        $b = $this->check([FindingCheck::OrphanRow]);
        $at = Carbon::parse('2026-09-02 03:00:00');

        $buch = $this->runLog();
        $ergebnis = (new Run([$a, $b], new FindingLog, $buch))->all($at);

        $this->assertSame(1, $a->laeufe);
        $this->assertSame(1, $b->laeufe);
        $this->assertTrue($at->equalTo($a->gesehen), 'Die erste Prüfung hat einen anderen Zeitpunkt gesehen.');
        $this->assertTrue($at->equalTo($b->gesehen), '„Zuletzt gemessen" wäre damit eine Frage mit zwei Antworten.');
        $this->assertSame($at, $ergebnis['measured_at']);
        $this->assertSame([], $ergebnis['failed']);

        // **Derselbe Zeitpunkt auch im Buch.** Ein `now()` beim Eintragen
        // stünde neben einer Zeile von 03:00:07 als „zuletzt gemessen
        // 03:00:09" — und die beiden wären dieselbe Messung.
        $this->assertSame($at->toDateTimeString(), $buch->lastRunAt());
    }

    public function test_a_failing_check_does_not_stop_the_others(): void
    {
        $kaputt = $this->check([FindingCheck::UnitState], static function (): void {
            throw new RuntimeException('so nicht');
        });
        $danach = $this->check([FindingCheck::OrphanRow]);

        $buch = $this->runLog();
        $ergebnis = (new Run([$kaputt, $danach], new FindingLog, $buch))->all(Carbon::parse('2026-09-02 03:00:00'));

        $this->assertSame(1, $danach->laeufe, 'Eine Ausnahme in der ersten Prüfung hat die zweite mitgenommen.');
        $this->assertCount(1, $ergebnis['failed']);
        $this->assertSame('so nicht', array_values($ergebnis['failed'])[0]);
        $this->assertCount(1, $ergebnis['ran']);

        // **Gelaufen ist der Lauf.** Ihn nur im Erfolgsfall festzuhalten hiesse,
        // dass die Seite nach einer gescheiterten Prüfung behauptet, seit Tagen
        // habe niemand gemessen.
        $this->assertSame(1, $buch->laeufe, 'Ein Lauf mit einer gescheiterten Prüfung wird nicht festgehalten.');
    }

    /**
     * Der Katalog nennt jede Prüfung, die es gibt — und nur solche.
     *
     * Beide Richtungen: Eine Prüfung, die niemand fährt, ist Code ohne Wirkung;
     * ein Eintrag ohne Klasse bricht den Lauf beim Auflösen. So entsteht ein
     * toter Eintrag wirklich — bei einer Umbenennung.
     */
    public function test_the_catalogue_names_every_check_that_exists(): void
    {
        $verzeichnis = dirname(__DIR__, 2).'/app/Support/Diagnose/Checks';
        $dateien = [];

        foreach (glob($verzeichnis.'/*.php') ?: [] as $pfad) {
            $dateien[] = 'App\\Support\\Diagnose\\Checks\\'.basename($pfad, '.php');
        }

        $katalog = Catalog::CHECKS;
        sort($dateien);
        sort($katalog);

        $this->assertSame($dateien, $katalog, 'Der Katalog und das Verzeichnis laufen auseinander.');
        $this->assertGreaterThanOrEqual(6, count($katalog), 'Zu wenige Prüfungen — der Ausdruck misst nichts.');

        foreach ($katalog as $klasse) {
            $this->assertTrue(is_subclass_of($klasse, Check::class), $klasse.' ist keine Prüfung.');
        }
    }

    /**
     * Jeder Schlüssel hat genau einen Schreiber.
     *
     * Gemessen an dem, was die Prüfungen selbst sagen — nicht an einer Liste
     * hier. `writes()` ist die Zusage, `replace()` die Wirkung.
     */
    public function test_no_key_has_two_writers(): void
    {
        $schreiber = [];

        foreach (Catalog::CHECKS as $klasse) {
            $spiegel = new \ReflectionClass($klasse);
            $writes = $spiegel->getMethod('writes');
            $quelle = (string) file_get_contents((string) $spiegel->getFileName());

            $this->assertTrue($writes->isPublic(), $klasse.' sagt nicht, was es schreibt.');

            foreach ($this->keysOf($klasse, $quelle) as $key) {
                $schreiber[$key][] = $spiegel->getShortName();
            }
        }

        foreach ($schreiber as $key => $klassen) {
            $this->assertCount(1, $klassen, sprintf(
                "%s wird von %s geschrieben.\nFindingLog::replace() ersetzt alle Zeilen einer Prüfung — die zweite löschte die Befunde der ersten.",
                $key,
                implode(' und ', $klassen),
            ));
        }

        $this->assertSame(
            count(FindingCheck::cases()),
            count($schreiber),
            'Nicht jeder Schlüssel des Katalogs wird von einer Prüfung geschrieben.',
        );
    }

    /**
     * Die Schlüssel einer Prüfung, ohne sie zu bauen.
     *
     * `Agent` leitet seine aus {@see SystemDiagnose::CHECKS} ab; die übrigen
     * nennen sie wörtlich in ihrem Rumpf. Gefragt wird der Quelltext, weil
     * dieser Wächter ohne Framework läuft und die Prüfungen einen Container
     * brauchten.
     *
     * @return list<string>
     */
    private function keysOf(string $klasse, string $quelle): array
    {
        if ($klasse === Agent::class) {
            return Agent::keys();
        }

        $keys = [];

        foreach (FindingCheck::cases() as $fall) {
            if (str_contains($quelle, 'FindingCheck::'.self::caseName($fall))) {
                $keys[] = $fall->value;
            }
        }

        return $keys;
    }

    private static function caseName(FindingCheck $check): string
    {
        $spiegel = new \ReflectionEnum(FindingCheck::class);

        foreach ($spiegel->getCases() as $fall) {
            if ($fall->getBackingValue() === $check->value) {
                return $fall->getName();
            }
        }

        return '';
    }

    /**
     * `block.integrity` holt der Sammelaufruf nicht — den schreibt ManagedBlocks.
     */
    public function test_the_agent_call_leaves_the_managed_blocks_alone(): void
    {
        $this->assertNotContains(FindingCheck::BlockIntegrity->value, Agent::keys());

        foreach (SystemDiagnose::CHECKS as $key) {
            if ($key === FindingCheck::BlockIntegrity->value) {
                continue;
            }

            $this->assertContains($key, Agent::keys(), sprintf(
                '%s wird vom Agenten beantwortet und von keiner Prüfung geholt.',
                $key,
            ));
        }
    }
}
