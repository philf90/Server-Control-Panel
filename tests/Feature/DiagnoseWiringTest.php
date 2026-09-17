<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\Diagnose;
use App\Console\Commands\VerifyBackups;
use App\Enums\FindingCheck;
use App\Models\Finding;
use App\Support\Diagnose\Catalog;
use App\Support\Diagnose\Check;
use App\Support\Diagnose\Run;
use App\Support\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jede Prüfung des Katalogs lässt sich vom Container bauen.
 *
 * ## Der Fehler, den es dafür gebraucht hat
 *
 * Am 3. September 2026, beim ersten Lauf von `srvpanel diagnose` auf einem
 * echten Server (`cloudsrv24`, 0.7.3-rc.11), brach das Kommando ab, bevor es
 * etwas gemessen hatte:
 *
 *     Target [App\Support\Diagnose\Wire] is not instantiable while building
 *     [App\Console\Commands\Diagnose, …, App\Support\Diagnose\Checks\Certificates].
 *
 * Die Diagnose hat **drei** Nähte, weil sich die echten Klassen in keinem Test
 * ersetzen lassen — `LocalHost` fragt das Dateisystem, `TlsWire` öffnet eine
 * Verbindung, `Settings` ist `final`. Gebunden war genau **eine**: `RunLog` aus
 * Schritt 7, weil sie zuletzt entstand. `Wire` und `Host` aus Schritt 5 waren
 * es nie.
 *
 * > **Eine Naht, die niemand verdrahtet, ist keine Naht — sie ist ein Loch, das
 * > erst der erste echte Lauf findet.**
 *
 * ## Warum kein einziger Test das gesehen hat
 *
 * Weil alle die Prüfungen **selbst zusammensetzen**. `UnitVerdictTest` und die
 * übrigen rufen `judge()` ohne Container, `DiagnoseRunTest` baut `Run` aus
 * handgemachten Attrappen, und `DiagnosePageTest` liest nur die Tabelle. Keiner
 * hat je den echten Graphen aufgelöst.
 *
 * > **Ein Test, der seinen Gegenstand selbst zusammensetzt, prüft ihn — und
 * > nicht den Weg, auf dem er im Betrieb entsteht.**
 *
 * ## Was er hält
 *
 * Nicht „die Bindung steht im Provider" — das wäre eine zweite Fassung
 * derselben Liste. Gemessen wird die **Wirkung**: Der Container baut jede
 * Prüfung des Katalogs, er baut `Run`, und er baut das Kommando, das der
 * Nachtlauf ruft. Wer eine Prüfung mit einer neuen Schnittstelle ergänzt und
 * die Bindung vergisst, wird hier rot und nicht auf dem Server.
 */
final class DiagnoseWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_container_builds_every_check_of_the_catalogue(): void
    {
        // **Die Untergrenze zählt.** Ein leerer Katalog ergäbe eine Schleife
        // ohne Durchlauf und damit einen Wächter, der nichts gesehen hat.
        $this->assertGreaterThanOrEqual(
            6,
            count(Catalog::every()),
            'Der Katalog nennt kaum Prüfungen — dann misst dieser Wächter nichts.',
        );

        foreach (Catalog::every() as $klasse) {
            $gebaut = $this->app->make($klasse);

            $this->assertInstanceOf($klasse, $gebaut, sprintf(
                'Der Container hat %s nicht bauen können.',
                $klasse,
            ));

            $this->assertInstanceOf(Check::class, $gebaut, sprintf(
                '%s steht im Katalog und ist keine Prüfung.',
                $klasse,
            ));
        }
    }

    /**
     * Der ganze Weg, den der Nachtlauf nimmt.
     *
     * **Die Prüfungen einzeln zu bauen genügt nicht.** `Run` bekommt sie über
     * eine eigene Bindung im Provider, und das Kommando bekommt `Run`. Genau
     * diese Kette ist auf dem Server gestorben, und zwar an ihrem Ende.
     */
    public function test_the_container_builds_the_run_and_its_command(): void
    {
        $this->assertInstanceOf(Run::class, $this->app->make(Run::class));
        $this->assertInstanceOf(Diagnose::class, $this->app->make(Diagnose::class));
        $this->assertInstanceOf(VerifyBackups::class, $this->app->make(VerifyBackups::class));
    }

    /**
     * Und der **zweite** Lauf bekommt seine eigenen Prüfungen und seinen eigenen
     * Zeitstempel.
     *
     * ## Warum das hier gemessen wird und nicht im Provider nachgelesen
     *
     * `Run` ist `final`, es gibt also keinen zweiten Typ, an den sich der
     * Lauf der Sicherungen binden liesse; er hängt an einer **kontextuellen**
     * Bindung. Dass die auch bei der Injektion in `handle()` greift und nicht
     * nur im Konstruktor, ist eine Zusage des Frameworks — gemessen am
     * 16. September 2026 gegen Laravel 13, und genau deshalb steht sie hier als
     * Wächter und nicht als Kommentar.
     *
     * > **Ein Kommentar, der eine Zusage des Frameworks behauptet, ist keine
     * > Prüfung — er ist eine Zeile, die aussieht wie eine.**
     *
     * ## Gemessen an der Wirkung, und mit der Gegenprobe im selben Lauf
     *
     * Griffe die Bindung nicht, bekäme das Kommando den Vorgabe-`Run`: Der
     * schriebe die Schlüssel der Bestandsdiagnose und seinen Zeitstempel unter
     * {@see Settings::DIAGNOSE}. Beide Fälle fallen damit auf —
     * `backup.file` bliebe ungeschrieben **und** der falsche Schlüssel stünde
     * da.
     */
    public function test_the_backup_run_writes_its_own_key_and_its_own_timestamp(): void
    {
        $settings = $this->app->make(Settings::class);

        $this->assertNull($settings->diagnoseRunAt(Settings::DIAGNOSE_BACKUPS), 'Vorbedingung: noch kein Lauf.');
        $this->assertNull($settings->diagnoseRunAt(Settings::DIAGNOSE), 'Vorbedingung: noch kein Bestandslauf.');

        $this->artisan('srvpanel:backup-verify')->assertExitCode(0);

        $this->assertNotNull(
            $settings->diagnoseRunAt(Settings::DIAGNOSE_BACKUPS),
            'Der Lauf der Sicherungen hat seinen Zeitpunkt nicht festgehalten.',
        );

        // **Die Gegenprobe, und sie trägt den Fall.** Ohne die kontextuelle
        // Bindung stünde hier der Zeitpunkt der Bestandsdiagnose — für einen
        // Lauf, der keine ihrer Prüfungen gefahren hat.
        $this->assertNull(
            $settings->diagnoseRunAt(Settings::DIAGNOSE),
            'Der Lauf der Sicherungen hat den Zeitstempel der Bestandsdiagnose überschrieben.',
        );

        // Und er hat wirklich seine Prüfung gefahren: Ohne Sicherungen schreibt
        // sie keine Zeile, aber auch keine Zeile einer anderen Prüfung.
        $this->assertSame(
            0,
            Finding::query()->where('check', '!=', FindingCheck::BackupFile->value)->count(),
            'Der Lauf der Sicherungen hat Befunde einer anderen Prüfung geschrieben.',
        );
    }

    /**
     * Jede Schnittstelle, die eine Prüfung verlangt, ist gebunden.
     *
     * Die Gegenrichtung, und sie sagt etwas anderes als die beiden oben: Dort
     * wird gebaut und kann gelingen, weil eine Schnittstelle zufällig nur eine
     * Umsetzung hat und Laravel sie findet. Hier wird gefragt, ob die Bindung
     * **abgelegt** ist — und das ist die Zusage, auf die sich der nächste
     * Entwickler verlässt.
     */
    public function test_every_interface_a_check_asks_for_is_bound(): void
    {
        $ungebunden = [];

        foreach (Catalog::every() as $klasse) {
            $spiegel = new \ReflectionClass($klasse);
            $bauplan = $spiegel->getConstructor();

            if ($bauplan === null) {
                continue;
            }

            foreach ($bauplan->getParameters() as $wert) {
                $typ = $wert->getType();

                if (! $typ instanceof \ReflectionNamedType || $typ->isBuiltin()) {
                    continue;
                }

                $name = $typ->getName();

                if (! interface_exists($name)) {
                    continue;
                }

                if (! $this->app->bound($name)) {
                    $ungebunden[] = sprintf('%s (verlangt von %s)', $name, $klasse);
                }
            }
        }

        $this->assertSame([], $ungebunden, implode("\n", [
            'Diese Schnittstellen verlangt eine Prüfung, und der Container kennt sie nicht:',
            implode("\n", $ungebunden),
            'Ohne Bindung stirbt der Nachtlauf an der ersten Prüfung, die sie braucht.',
        ]));
    }
}
