<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Journal;
use SrvPanel\Agent\Maintenance;
use SrvPanel\Agent\Ops\WebMaintenanceSet;
use SrvPanel\Agent\Runner;

/**
 * Der Schalter des Wartungsmodus — an der **Wirkung** und nicht am Quelltext
 * (A12, `docs/101 §4`).
 *
 * Gemessen wird gegen einen Wegwerf-Ablageort; dass die Vorgabe derselbe Pfad
 * ist, den die Wache im Server-Block nennt, hält
 * {@see self::test_the_default_is_the_path_the_guard_names()}. Ein Wächter, der
 * gegen einen eigenen Pfad misst, prüfte seine eigene Erfindung.
 */
final class MaintenanceSwitchTest extends TestCase
{
    private string $flag = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->flag = sys_get_temp_dir().'/srvpanel-wartung-'.bin2hex(random_bytes(6)).'/wartung';
    }

    protected function tearDown(): void
    {
        if (is_file($this->flag)) {
            unlink($this->flag);
        }

        if (is_dir(dirname($this->flag))) {
            rmdir(dirname($this->flag));
        }

        parent::tearDown();
    }

    private function context(): Context
    {
        $journal = new Journal('/dev/null');

        return new Context(new Runner($journal), $journal, static function (array $line): void {});
    }

    /**
     * @return array<string, mixed>
     */
    private function schalten(mixed $enabled): array
    {
        return (new WebMaintenanceSet($this->flag))->execute(['enabled' => $enabled], $this->context());
    }

    public function test_it_switches_on_and_off(): void
    {
        $this->assertFileDoesNotExist($this->flag, 'Der Prüfkörper liegt schon — dann misst der erste Fall nichts.');

        $an = $this->schalten(true);

        $this->assertTrue($an['enabled']);
        $this->assertFileExists($this->flag);

        $aus = $this->schalten(false);

        $this->assertFalse($aus['enabled']);
        $this->assertFileDoesNotExist($this->flag);
    }

    /**
     * Ausschalten ist wiederholbar.
     *
     * `unlink()` gibt für eine Datei, die nicht da ist, `false` zurück — wer
     * das für einen Fehlschlag nimmt, macht aus dem zweiten Ausschalten einen
     * Abbruch. Und der zweite Aufruf ist der wahrscheinliche: Er passiert immer
     * dann, wenn jemand zweimal klickt oder zwei Betreiber gleichzeitig
     * aufräumen.
     */
    public function test_switching_off_twice_is_not_a_failure(): void
    {
        $this->schalten(true);
        $this->schalten(false);

        $nochmal = $this->schalten(false);

        $this->assertFalse($nochmal['enabled']);
        $this->assertFileDoesNotExist($this->flag);
    }

    /** Und einschalten genauso: Die Datei liegt danach einmal da und nicht zweimal. */
    public function test_switching_on_twice_is_not_a_failure(): void
    {
        $this->schalten(true);
        $inhalt = file_get_contents($this->flag);

        $nochmal = $this->schalten(true);

        $this->assertTrue($nochmal['enabled']);
        $this->assertSame($inhalt, file_get_contents($this->flag));
    }

    /**
     * Ein Fehlschlag beim Schalten wird nicht als Erfolg gemeldet.
     *
     * Der Prüfkörper: Das Verzeichnis wird schreibgeschützt, die Datei damit
     * unlöschbar. Ohne Prüfung käme `enabled: false` zurück, während die Datei
     * liegt — und das Panel schriebe „ausgeschaltet" in seine Einstellung.
     *
     * > **Ein Vorgang, der nur meldet, dass er abgesetzt wurde, sagt über den
     * > Ausgang dessen, was er abgesetzt hat, nichts.**
     *
     * ## Was dieser Fall **nicht** misst, und der Name sagte es zuerst falsch
     *
     * Er hiess „das Ergebnis wird nachgelesen". Das tut er nicht: Die Ausnahme
     * kommt hier aus `turnOff()`, weil `unlink()` fehlschlägt — die Nachlese
     * danach wird gar nicht erreicht. Sie fängt den Fall, dass ein Schreiben
     * *gelingt* und die Datei trotzdem fehlt, und den kann ich nicht
     * herstellen.
     *
     * > **Ein Test, der nach dem benannt ist, was er absichern soll, statt nach
     * > dem, was er misst, ist eine Zusage über eine Zeile, die er nie
     * > ausführt.**
     *
     * Die Nachlese bleibt trotzdem stehen; sie kostet einen `is_file()` und
     * deckt die Lücke, die dieser Wächter offen lässt.
     */
    public function test_a_failed_switch_is_not_reported_as_success(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Als root greift der Schreibschutz nicht — der Prüfkörper stellt seinen Zustand nicht her.');
        }

        $this->schalten(true);

        $verzeichnis = dirname($this->flag);
        chmod($verzeichnis, 0o500);

        try {
            $this->schalten(false);
            $this->fail('Das Ausschalten hat Erfolg gemeldet, obwohl die Datei liegt.');
        } catch (AgentException) {
            $this->addToAssertionCount(1);
        } finally {
            chmod($verzeichnis, 0o700);
        }

        $this->assertFileExists($this->flag, 'Der Prüfkörper hat den Zustand nicht hergestellt — dann misst dieser Fall nichts.');
    }

    /**
     * Geschaltet wird mit einem Wahrheitswert und nicht mit irgendetwas.
     *
     * `'0'`, `''` und `null` sind in PHP alle falsch-artig; ohne die Prüfung
     * schaltete ein leeres Feld den Modus aus, ohne dass jemand das gemeint
     * hätte.
     *
     * ## Warum die **Art** der Ausnahme geprüft wird und nicht nur ihr Auftreten
     *
     * Der erste Wurf fing jede `AgentException` — und blieb grün, als die
     * Typprüfung entfernt war. Der Grund: Der Vergleich in der Nachlese ist
     * strikt (`$ist !== $enabled`), also passt `true` nie zu `'1'`, und es
     * fliegt eine — nur eben `exec_failed` statt `bad_request`.
     *
     * Das ist ein Unterschied, den der Aufrufer sieht: `bad_request` heisst
     * „so nicht", `exec_failed` heisst „hat nicht geklappt". Wer das erste als
     * zweites meldet, schickt den Leser dorthin, wo nichts zu reparieren ist.
     *
     * > **Ein Wächter, der nur zählt, dass etwas geworfen wurde, ist grün,
     * > sobald irgendetwas fliegt — auch das Falsche.**
     */
    public function test_it_refuses_anything_that_is_not_a_boolean(): void
    {
        foreach (['1', 'true', '', 0, 1, null, []] as $boese) {
            try {
                $this->schalten($boese);
                $this->fail('Angenommen: '.var_export($boese, true));
            } catch (AgentException $e) {
                $this->assertSame(
                    AgentException::BAD_REQUEST,
                    $e->errorCode,
                    var_export($boese, true).' wird abgewiesen, aber als Fehlschlag statt als falsche Eingabe.',
                );
            }
        }
    }

    /**
     * Die Vorgabe ist der Pfad, den die Wache im Server-Block nennt.
     *
     * **Zwei Listen, die dasselbe meinen, laufen auseinander.** Zöge die eine
     * um, schaltete das Panel eine Datei, auf die nginx nicht sieht — und der
     * Schalter täte nichts, ohne dass irgendetwas rot würde.
     */
    public function test_the_default_is_the_path_the_guard_names(): void
    {
        $reflection = new \ReflectionClass(WebMaintenanceSet::class);
        $parameter = $reflection->getConstructor()?->getParameters()[0] ?? null;

        $this->assertNotNull($parameter, 'Der Ablageort ist nicht mehr einsetzbar — dann misst dieser Wächter etwas anderes.');
        $this->assertSame(Maintenance::FLAG, $parameter->getDefaultValue());
        $this->assertStringContainsString(Maintenance::FLAG, Maintenance::nginxGuard(null, null));
    }

    /**
     * Der Ablageort liegt unter `/var/spool` und nicht unter `/var/lib/srvpanel`.
     *
     * **Gemessen und einmal teuer bezahlt** (`docs/78`): Das Verzeichnis des
     * Panels ist `0750 srvpanel:srvpanel`, der nginx-Worker läuft als
     * `www-data` und kommt dort nicht einmal hindurch. Eine Datei, die für alle
     * lesbar ist, ist damit nicht erreichbar — der Weg zu ihr entscheidet. Die
     * Wache prüfte dann immer „liegt nicht", und der Schalter täte nichts.
     */
    public function test_the_flag_lies_where_nginx_can_reach_it(): void
    {
        $this->assertStringStartsWith('/var/spool/', Maintenance::FLAG);
        $this->assertStringNotContainsString('/var/lib/', Maintenance::FLAG);
    }
}
