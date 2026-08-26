<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\AptLock;
use SrvPanel\Agent\Result;
use Tests\Support\WithoutPhpComments;

/**
 * Zwei apt-Läufe enden in der dpkg-Sperre — und niemand fragte danach.
 *
 * ## Der Zustand, den dieser Wächter ablöst
 *
 * `docs/81 §7` führt es als Falle 2. Gefragt hat bis zum 24. August 2026 genau
 * **eine** der vier apt-rufenden Operationen, `panel.update` — und ihre Frage
 * war die falsche: `systemctl list-units srvpanel-update-*` sieht nur die
 * eigenen abgesetzten Läufe. Ein `php.version.install` in der Warteschlange kam
 * darin nicht vor, und umgekehrt sah keine der drei anderen Operationen ein
 * laufendes Update.
 *
 * Die Warteschlange trägt dabei nur die halbe Strecke: `queue:work` ist
 * einspurig, aber `panel.update` setzt seinen Lauf über `systemd-run`
 * **ausserhalb** ab und kehrt sofort zurück. In diesem Fenster ist die
 * Kollision in beiden Richtungen offen, und was der Kunde davon sieht, ist die
 * Meldung von dpkg.
 *
 * ## Was hier gehalten wird
 *
 * 1. **Jede Operation, die apt anfasst, geht über {@see AptLock}** — gezählt
 *    im Quelltext, nicht geglaubt.
 * 2. **Der Leser versteht `/proc/locks`** — gemessen an selbstgebauten Zeilen
 *    in genau der Form, die eine echte Maschine liefert.
 * 3. **`FLOCK` zählt nicht.** Gemessen am 24. August 2026: PHPs `flock()`
 *    gelingt, während dpkgs POSIX-Sperre gehalten wird. Die beiden Familien
 *    sehen einander nicht — eine `FLOCK`-Zeile mitzuzählen ergäbe eine
 *    Ablehnung für einen Lauf, der durchgekommen wäre.
 */
final class AptLockReachTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Die Ausnahmen — mit ihrem Grund, und der Grund ist gemessen.
     *
     * **Hier stand eine leere Liste, und sie war keine.** Gedacht war sie als
     * Weg für `system.packages.list`; PHPStan hat sie als das gemeldet, was sie
     * war: `array_key_exists()` gegen `array{}` ist immer falsch.
     *
     * > **Eine leere Positivliste ist kein Mechanismus, sondern eine
     * > Verzierung.**
     *
     * **Am 26. August 2026 gemessen statt vermutet**, mit einer POSIX-Sperre
     * auf `/var/lib/dpkg/lock-frontend` und einer Gegenprobe im selben Lauf:
     *
     *     apt-get -s dist-upgrade          rc 0    145 Inst-Zeilen
     *     apt-get install --reinstall      rc 100  "Could not get lock"
     *
     * Die Simulation nimmt die Sperre also nicht, ein echter Lauf schon.
     *
     * **Und die Ausnahme ist nicht nur erlaubt, sondern richtig.** `ensureFree()`
     * wirft, wenn ein Lauf läuft — eine Liste, die sich dann weigert, verweigert
     * die Auskunft genau in dem Moment, in dem ein Betreiber sie braucht.
     *
     * > **Eine lesende Frage, die an einer Sperre scheitert, beantwortet sie
     * > nicht später, sondern gar nicht.**
     *
     * @var array<string, string>
     */
    private const EXCEPTIONS = [
        'SystemPackagesList.php' => 'ruft apt ausschliesslich mit -s; gemessen: nimmt die Sperre nicht',
    ];

    /** Woran erkannt wird, dass eine Operation apt anfasst. */
    private const TOUCHES_APT = '/\'apt-get\'|apt-get\s+\w|\bApt::(refresh|of)\s*\(/';

    /** Jede apt-rufende Operation fragt vorher, ob die Sperre frei ist. */
    public function test_every_operation_that_touches_apt_goes_through_the_lock(): void
    {
        $strays = [];
        $found = 0;

        foreach ($this->operationSources() as $path => $source) {
            if (preg_match(self::TOUCHES_APT, $source) !== 1) {
                continue;
            }

            $found++;

            if (array_key_exists(basename($path), self::EXCEPTIONS)) {
                continue;
            }

            if (! str_contains($source, 'AptLock::ensureFree')) {
                $strays[] = $path;
            }
        }

        // Ein Ausdruck, der nichts findet, ist kein bestandener Test.
        $this->assertGreaterThan(2, $found, 'Es werden kaum apt-rufende Operationen gefunden — dann prüft dieser Test nichts.');

        $this->assertSame([], $strays, sprintf(
            "Diese Operationen fassen apt an, ohne vorher die Sperre zu prüfen:\n\n  %s\n\n"
            .'Zwei apt-Läufe gleichzeitig enden in der dpkg-Sperre, und deren Meldung versteht '
            .'niemand. Wer hier wirklich eine Ausnahme braucht, misst zuerst, dass der Aufruf '
            .'die Sperre nicht nimmt — und führt sie dann mit ihrem Grund ein.',
            implode("\n  ", $strays),
        ));
    }

    /**
     * Und jede Ausnahme nennt eine Operation, die es gibt und die apt anfasst.
     *
     * **Die Gegenrichtung, und sie ist der eigentliche Verfall.** Ein Eintrag
     * hier hebt eine Regel auf; verschwindet die Datei oder hört sie auf, apt
     * zu rufen, hebt er nichts mehr auf und bleibt trotzdem stehen. Der nächste
     * Leser hält ihn für eine geltende Ausnahme.
     *
     * Derselbe Fall wie beim toten Eintrag im Wrapper: Die erste Richtung ist
     * nach einer Umbenennung wieder grün, und der alte Name bleibt liegen.
     */
    public function test_every_exception_names_an_operation_that_touches_apt(): void
    {
        $quellen = $this->operationSources();

        $this->assertGreaterThan(2, count($quellen), 'Es werden kaum Operationen gefunden — dann prüft dieser Test nichts.');

        foreach (array_keys(self::EXCEPTIONS) as $name) {
            $treffer = array_filter(
                $quellen,
                static fn (string $path): bool => basename($path) === $name,
                ARRAY_FILTER_USE_KEY,
            );

            $this->assertCount(1, $treffer, sprintf(
                'Die Ausnahme nennt %s; eine Operation dieses Namens gibt es nicht.',
                $name,
            ));

            $this->assertSame(1, preg_match(self::TOUCHES_APT, (string) reset($treffer)), sprintf(
                '%s steht als Ausnahme, fasst apt aber gar nicht an — der Eintrag hebt nichts mehr auf.',
                $name,
            ));
        }
    }

    /**
     * Und die vier, die es heute sind, werden vom Ausdruck auch getroffen.
     *
     * Der Prüfkörper des Tests oben: Ändert sich die Schreibweise eines
     * apt-Aufrufs, findet die Suche nichts mehr und meldete Grün für eine
     * Regel, die sie nicht mehr liest.
     */
    public function test_the_four_known_callers_are_reached_by_the_scan(): void
    {
        $sources = $this->operationSources();

        foreach (['PanelUpdate', 'PhpVersionInstall', 'PhpVersionRemove', 'PgServerInstall'] as $operation) {
            $path = 'agent/src/Ops/'.$operation.'.php';

            $this->assertArrayHasKey($path, $sources, $path.' gibt es nicht mehr.');
            $this->assertSame(1, preg_match(self::TOUCHES_APT, $sources[$path]), sprintf(
                '%s fasst apt nicht mehr an — entweder ist der Aufruf umgezogen (dann gehört '
                .'die Liste hier berichtigt) oder der Ausdruck trifft ihn nicht mehr (dann misst '
                .'dieser Wächter nichts).',
                $operation,
            ));
        }
    }

    /**
     * **Die Wirkung**: Eine gehaltene POSIX-Sperre wird gefunden.
     *
     * Die Zeile ist Zeichen für Zeichen die gemessene — `/proc/locks` während
     * eines echten `apt-get install`.
     */
    public function test_a_held_posix_lock_is_found(): void
    {
        $holder = AptLock::holder(
            $this->lockLine('POSIX', 8580, 242),
            [242 => '/var/lib/dpkg/lock-frontend'],
        );

        $this->assertNotNull($holder);
        $this->assertSame('/var/lib/dpkg/lock-frontend', $holder['file']);
        $this->assertSame(8580, $holder['pid']);
    }

    /**
     * Und die Gegenprobe: Ohne passende Zeile meldet der Leser nichts.
     *
     * Ohne sie bestünde der Test oben auch für einen Leser, der immer einen
     * Halter meldet — und der wäre genauso falsch, nur andersherum: Jede
     * Installation würde abgelehnt.
     */
    public function test_a_free_lock_reports_nobody(): void
    {
        $this->assertNull(AptLock::holder('', [242 => '/var/lib/dpkg/lock-frontend']));

        // Eine Sperre auf einer *anderen* Datei ist keine auf dieser.
        $this->assertNull(AptLock::holder(
            $this->lockLine('POSIX', 8580, 999),
            [242 => '/var/lib/dpkg/lock-frontend'],
        ));
    }

    /**
     * `FLOCK` ist die andere Familie und zählt nicht.
     *
     * Gemessen: Ein Halter mit `fcntl` sperrt, und PHPs `flock()` gelingt
     * trotzdem. Umgekehrt blockiert ein `flock` das apt nicht — wer die Zeile
     * mitzählte, lehnte einen Lauf ab, der durchgekommen wäre.
     */
    public function test_a_flock_entry_does_not_count(): void
    {
        $this->assertNull(AptLock::holder(
            $this->lockLine('FLOCK', 483, 242),
            [242 => '/var/lib/dpkg/lock-frontend'],
        ));
    }

    /**
     * Ein Wartender zählt mit.
     *
     * `/proc/locks` führt blockierte Anwärter als Fortsetzungszeile mit `->`.
     * Wer nur den Halter liest, übersieht nichts — aber eine Datei, für die
     * jemand ansteht, ist erst recht nicht frei, und die Zeile fällt sonst
     * durch den Ausdruck.
     */
    public function test_a_waiting_process_counts_too(): void
    {
        $holder = AptLock::holder(
            "1: -> POSIX  ADVISORY  WRITE 4711 fe:00:242 0 EOF\n",
            [242 => '/var/lib/dpkg/lock-frontend'],
        );

        $this->assertNotNull($holder);
        $this->assertSame(4711, $holder['pid']);
    }

    /**
     * Alle vier Sperrdateien werden gefragt, und das ist gemessen.
     *
     * Welche gehalten wird, hängt am Unterbefehl: bei `apt-get install` sind es
     * `lock-frontend`, `lock` und `archives/lock`, bei `apt-get update`
     * dagegen `lists/lock`. Eine einzelne zu fragen hiesse, die Hälfte der
     * Läufe nicht zu sehen.
     */
    public function test_every_measured_lock_file_is_asked(): void
    {
        foreach ([
            '/var/lib/dpkg/lock-frontend',
            '/var/lib/dpkg/lock',
            '/var/lib/apt/lists/lock',
            '/var/cache/apt/archives/lock',
        ] as $file) {
            $this->assertContains($file, AptLock::FILES);
        }
    }

    /**
     * Der Name der abgesetzten Unit und die Suche danach kommen aus einer
     * Quelle.
     *
     * In `PanelUpdate` standen sie bis zum 24. August als zwei Zeichenketten
     * nebeneinander. Zwei Fassungen derselben Regel, und die zweite ist die,
     * die beim Umbenennen stehenbleibt.
     */
    public function test_the_unit_name_is_built_from_the_constant(): void
    {
        $source = $this->operationSources()['agent/src/Ops/PanelUpdate.php'] ?? '';

        $this->assertStringContainsString('AptLock::UNIT_PREFIX', $source);
        $this->assertStringNotContainsString("'srvpanel-update-'", $source,
            'Der Name steht wieder als eigene Zeichenkette da — dann kann er von der Suche abweichen.');
    }

    /**
     * Ein laufender eigener Lauf wird erkannt.
     *
     * Die Zeile ist die Form von `systemctl list-units --plain --no-legend`.
     */
    public function test_a_running_own_unit_is_recognised(): void
    {
        $this->assertSame('srvpanel-update-a1b2c3d4.service', AptLock::runningUnit(new Result(0,
            "srvpanel-update-a1b2c3d4.service loaded active running SrvPanel Update\n", '')));
    }

    /**
     * Kein Treffer bei Rückgabe 0 heisst wirklich „es läuft keiner".
     *
     * So meldet `systemctl` ein Muster ohne passende Unit — und nur bei
     * Rückgabe 0 darf das als Antwort gelten.
     */
    public function test_an_empty_successful_listing_means_nothing_runs(): void
    {
        $this->assertNull(AptLock::runningUnit(new Result(0, '', '')));
    }

    /**
     * **Ein fehlgeschlagenes `systemctl` ist keine Antwort** — und die alte
     * Fassung gab trotzdem eine.
     *
     * `PanelUpdate` las seit P0 nur `stdout` und schloss aus einer leeren
     * Ausgabe „es läuft keiner". Gemessen ohne systemd: `rc=1`, `stdout` leer,
     * die Auskunft steht auf `stderr`. Die Frage war damit nicht beantwortet,
     * und geraten wurde in die Richtung, die einen kollidierenden Lauf losgehen
     * lässt.
     *
     * Derselbe Befund wie M5, nur andersherum: Dort trägt der Rückgabewert den
     * Fehlschlag nicht, hier trägt er ihn und niemand sieht hin.
     */
    public function test_a_failed_listing_is_not_an_answer(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('liess sich nicht feststellen');

        AptLock::runningUnit(new Result(1, '',
            'System has not been booted with systemd as init system (PID 1). Can\'t operate.'));
    }

    /** Eine Zeile in der gemessenen Form von `/proc/locks`. */
    private function lockLine(string $kind, int $pid, int $inode): string
    {
        return sprintf("2: %s  ADVISORY  WRITE %d fe:00:%d 0 EOF\n", $kind, $pid, $inode);
    }

    /**
     * Der Quelltext der Operationen, ohne Kommentare, nach Pfad ab der Wurzel.
     *
     * Ohne den Schnitt durch die Kommentare meldete dieser Wächter jede Datei,
     * die den Befund im Fliesstext erklärt — und die naheliegende Reaktion wäre,
     * die Erklärung zu streichen.
     *
     * @return array<string,string>
     */
    private function operationSources(): array
    {
        $root = dirname(__DIR__, 2);
        $sources = [];

        foreach (glob($root.'/agent/src/Ops/*.php') ?: [] as $path) {
            $sources[substr($path, strlen($root) + 1)] = $this->withoutComments((string) file_get_contents($path));
        }

        return $sources;
    }
}
