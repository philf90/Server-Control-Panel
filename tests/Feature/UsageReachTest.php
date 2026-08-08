<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\MeasureUsage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SrvPanel\Agent\Config;
use SrvPanel\Agent\Registry;

/**
 * Was gemessen werden kann, wird auch gemessen.
 *
 * **Der Anlass ist ein Zeitgeber, der leiser ausfällt als er scheitert.** Bis
 * P5 gab es eine Messung; seit P5 sind es zwei — der belegte Speicher über die
 * Dateisystem-Quota und der Platz der Datenbanken über `information_schema`.
 * Beide hängen am selben `srvpanel-usage.timer`, weil zwei Zeitgeber im
 * Viertelstundentakt zwei Dinge wären, die jemand überwachen muss (docs/36 §9).
 *
 * Genau daraus entsteht die Lücke, gegen die dieser Test steht: Eine dritte
 * Messung — Postfach, Cronlauf, was P6 und P8 bringen — wird als Operation
 * registriert, bekommt ihren Dienst im Panel, und **niemand ruft sie auf**. Der
 * Zeitgeber läuft weiter grün, die Oberfläche zeigt „noch nicht gemessen", und
 * das sieht aus wie ein Server, auf dem nichts liegt.
 *
 * Das ist wortwörtlich das Muster aus CLAUDE.md: *eine Zeichenkette, die auf
 * etwas verweist, ohne dass ein Typ, ein Test oder ein Werkzeug den Bezug
 * prüft.* Zwei fertig gebaute Agent-Operationen, die von nichts aufgerufen
 * wurden, hat P3 schon gefunden — dieser Test ist die Fassung davon für die
 * Messungen.
 *
 * **Geprüft wird am Namen der Operation und nicht an einer Liste.** Eine Liste
 * müsste jemand pflegen, und wer sie pflegt, denkt auch an den Aufruf. Die
 * Frage lautet deshalb: Welche registrierten Operationen heissen `*.usage`, und
 * nennt der Quelltext des Kommandos sie alle?
 */
final class UsageReachTest extends TestCase
{
    /*
     * **Hier stand eine leere Ausnahmeliste, und sie musste weg.**
     * `array_key_exists($name, self::ELSEWHERE)` gegen eine leere Konstante ist
     * für PHPStan ein `function.impossibleType` — der Zweig kann nicht laufen.
     * Das ist derselbe Fund, den CLAUDE.md schon für ein
     * `in_array($x, self::LEER, true)` festhält, und er ist berechtigt: Ein
     * Haken, an dem nichts hängt, ist kein Haken, sondern eine Zusage.
     *
     * Was bleibt, ist die Anweisung statt des Mechanismus: Gibt es eines Tages
     * eine Messung, die nicht am Zeitgeber hängt, bekommt sie **hier** eine
     * Liste mit Namen und Grund — und wer sie anlegt, schreibt dazu, wer die
     * Messung sonst auslöst. Wer statt dessen die Behauptung unten entschärft,
     * hat den Wächter abgeschaltet.
     */

    /** @return list<string> */
    private function measurements(): array
    {
        $names = [];

        foreach ((new Registry(new Config))->names() as $name) {
            if (str_ends_with($name, '.usage')) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    public function test_the_timer_calls_every_measurement(): void
    {
        $measurements = $this->measurements();

        /*
         * Die Untergrenze zählt, wo die Regel stehen *darf*, und nicht, wo sie
         * gerade steht — sonst meldet dieser Wächter Rot, sobald jemand eine
         * Messung zusammenlegt. Zwei ist die Zahl, ab der die Frage überhaupt
         * eine ist.
         */
        $this->assertGreaterThanOrEqual(
            2,
            count($measurements),
            'Es werden kaum Messungen gefunden — dann prüft dieser Test nichts.',
        );

        $source = (string) file_get_contents((new ReflectionClass(MeasureUsage::class))->getFileName());

        $missing = [];

        foreach ($measurements as $name) {
            /*
             * Gesucht wird der Name der Operation im Quelltext des Kommandos —
             * er steht dort nicht wörtlich, sondern im Dienst dahinter. Deshalb
             * wird über den Dienst gefragt: `subscription.usage` steht in
             * `App\Support\Subscriptions\Usage`, `db.usage` in
             * `App\Support\Databases\Usage`, und das Kommando nennt beide
             * Klassen.
             */
            if (! $this->reachedBy($source, $name)) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing, sprintf(
            "Diese Messungen ruft niemand auf:\n  %s\n\n".
            'Der Zeitgeber srvpanel-usage.timer startet App\Console\Commands\MeasureUsage — eine '.
            'Operation, die dort nicht ankommt, läuft nie. Die Oberfläche zeigt dann dauerhaft '.
            '„noch nicht gemessen", und das sieht aus wie ein Server, auf dem nichts liegt. '.
            'Entweder gehört der Aufruf ins Kommando — oder die Messung bekommt hier eine '.
            'Ausnahmeliste mit Namen und Grund.',
            implode("\n  ", $missing),
        ));
    }

    /**
     * Erreicht das Kommando diese Operation?
     *
     * Über die Klasse, die sie aufruft: Jeder Messdienst nennt seinen
     * Operationsnamen in einem `call('…')`, und das Kommando nennt den Dienst.
     * Zwei Sprünge statt einer Zeichenkette — dafür hält die Prüfung, wenn
     * jemand den Dienst umbenennt.
     */
    private function reachedBy(string $command, string $operation): bool
    {
        foreach ($this->servicesCalling($operation) as $service) {
            if (str_contains($command, $service)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Die Klassen unter `app/Support`, die diese Operation aufrufen.
     *
     * @return list<string>
     */
    private function servicesCalling(string $operation): array
    {
        $root = dirname(__DIR__, 2).'/app/Support';
        $services = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! str_contains($source, "'".$operation."'")) {
                continue;
            }

            // Der voll qualifizierte Name, so wie ihn ein `use` im Kommando
            // schreiben würde.
            if (preg_match('/^namespace\s+([^;]+);/m', $source, $match) === 1) {
                $services[] = trim($match[1]).'\\'.$file->getBasename('.php');
            }
        }

        return $services;
    }
}
