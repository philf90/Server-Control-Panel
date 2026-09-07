<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Cron\ServerZone;
use App\Support\Time\Clock;
use PHPUnit\Framework\TestCase;

/**
 * Nur **eine** Stelle fragt den Rechner nach seiner Zeitzone.
 *
 * **Dieselbe Bauart wie `HostnameSourceTest`, und aus demselben Anlass.**
 * `Names::fqdn()` ist viermal neu erfunden worden, bevor es dafür einen Wächter
 * gab. Die Zeitzone ist die nächste Frage dieser Art, und sie ist gefährlicher,
 * weil die falsche Antwort **hier nie auffällt**: In diesem Container sagen
 * `/etc/localtime` und `config('app.timezone')` beide UTC. Auf einem Server mit
 * `Europe/Berlin` gehen sie zwei Stunden auseinander, und cron folgt der Datei.
 *
 * > **Eine Zeitzone aus der Konfiguration der Anwendung ist eine Angabe über die
 * > Anwendung und keine über die Uhr, nach der der Server handelt.**
 *
 * Es gibt in diesem Panel drei Zeitzonen, und jede hat ihre eigene Stelle:
 * UTC beim Speichern, die Anzeigezone in {@see Clock}, die Zone der Maschine in
 * {@see ServerZone}. Wer eine vierte Antwort einbaut, baut die Verwechslung ein.
 */
final class ServerZoneSourceTest extends TestCase
{
    /** Wonach gesucht wird — der Griff an die Datei, die die Zone der Maschine nennt. */
    private const NEEDLE = '/etc/localtime';

    /**
     * Wo er stehen darf — mit dem Grund, warum das kein zweiter Leser ist.
     *
     * **Der zweite Eintrag ist am 7. September 2026 dazugekommen.** Er liest
     * die Datei nicht; er nennt sie in einer `open_basedir`-Liste, um die
     * Bedingung nachzustellen, unter der php-fpm läuft. Genau dieser
     * Unterschied — Kommandozeile ohne Schranke, Web-Request mit — hat den
     * Fehler aus `docs/107 §0c` ein Jahr lang verdeckt, und der Fall lässt sich
     * ohne den Pfad im Prüfstand nicht herstellen.
     *
     * > **Eine Ausnahme mit Grund ist eine Entscheidung; eine ohne ist eine
     * > Lücke.**
     *
     * @var array<string,string> Pfad => Grund
     */
    private const ALLOWED = [
        'app/Support/Cron/ServerZone.php' => 'Die eine Stelle, die den Rechner nach seiner Zone fragt.',
        'tests/Unit/TimezoneFileTest.php' => 'Nennt den Pfad in einer open_basedir-Liste, um den Web-Request '
            .'nachzustellen — und liest ihn dabei gerade nicht.',
    ];

    public function test_only_one_class_reads_the_machine_timezone(): void
    {
        $offenders = [];
        $found = 0;

        foreach ($this->phpFiles() as $path => $source) {
            if (! str_contains($source, self::NEEDLE)) {
                continue;
            }

            $found++;

            if (! array_key_exists($path, self::ALLOWED)) {
                $offenders[] = $path;
            }
        }

        /*
         * **Die Untergrenze zählt mit**, und zwar aus der Falle, in die dieses
         * Vorgehen dreimal selbst gelaufen ist: Ein Wächter, dessen Ausdruck ins
         * Leere läuft, meldet Grün für einen Zustand, über den er nichts weiss.
         * Verschwindet der Griff aus ServerZone, ist das kein aufgeräumter Code,
         * sondern eine verlorene Antwort.
         */
        $this->assertGreaterThan(0, $found, sprintf(
            'Niemand liest mehr %s. Entweder ist ServerZone weg, oder dieser Wächter sucht ins Leere.',
            self::NEEDLE,
        ));

        $this->assertSame([], $offenders, sprintf(
            "Diese Stellen fragen den Rechner selbst nach seiner Zeitzone:\n  %s\n\n".
            'Die Antwort gehört nach %s — sonst gibt es zwei, und die zweite veraltet.',
            implode("\n  ", $offenders),
            implode(' oder ', array_keys(self::ALLOWED)),
        ));
    }

    /** Jede Ausnahme nennt ihren Grund, und jede genannte Datei gibt es. */
    public function test_every_exemption_carries_a_reason_and_exists(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (self::ALLOWED as $path => $reason) {
            $this->assertFileExists($root.'/'.$path, sprintf(
                '%s steht als Ausnahme da und es gibt sie nicht — dann deckt sie nichts.',
                $path,
            ));
            $this->assertGreaterThan(40, strlen($reason), sprintf(
                'Die Ausnahme für %s trägt keinen Grund.',
                $path,
            ));
        }

        $this->assertCount(2, self::ALLOWED);
    }

    /**
     * Und die andere Richtung: Die Anzeigezone wird nicht für den Zeitplan benutzt.
     *
     * `Clock` beantwortet „was liest der Betreiber", nicht „wann feuert cron".
     * Ein `Clock::` in der Fälligkeitsrechnung wäre genau die Verwechslung, gegen
     * die `docs/60 §11` steht — sie zeigte eine Zeile und fände sie nicht.
     */
    public function test_the_display_timezone_does_not_drive_the_schedule(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/app/Support/Cron/Occurrence.php');

        $this->assertStringNotContainsString('Clock::', $source,
            'Occurrence rechnet in der Zeit der Maschine, nicht in der Anzeigezone.');
        $this->assertStringContainsString('ServerZone::', $source,
            'Occurrence muss die Zone der Maschine erfragen — sonst rechnet es in der von PHP.');
    }

    /**
     * Die Dateien, über die gesucht wird — mit ihrem Pfad relativ zum Repo.
     *
     * `vendor/` und `node_modules/` bleiben draussen: Was dort steht, ist nicht
     * unsere Regel.
     *
     * @return array<string,string>
     */
    private function phpFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        foreach (['app', 'agent/src', 'tests'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root.'/'.$directory, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = str_replace($root.'/', '', $file->getPathname());

                // Der Wächter selbst nennt die Zeichenkette, und das ist keine
                // zweite Antwort — es ist die Frage.
                if ($path === 'tests/Unit/ServerZoneSourceTest.php') {
                    continue;
                }

                $files[$path] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }
}
