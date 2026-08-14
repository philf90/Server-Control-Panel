<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\WithoutPhpComments;

/**
 * Es gibt genau eine Stelle, die einsperrt und Rechte abgibt.
 *
 * **Der Anlass ist die Stufe, in der dieses Projekt mit seinem eigenen Muster
 * bricht.** Bis P5c nahm keine Operation einen Pfad entgegen — sie baute ihn.
 * Ein Dateimanager kann das nicht, und `docs/50` hat gemessen, dass die
 * naheliegende Ersetzung (eine bessere Pfadprüfung) das Rennen in 31 % der
 * Fälle verliert. Was hält, ist `SrvPanel\Agent\Sandbox`.
 *
 * Damit ist diese eine Klasse die Grenze des ganzen Systems — und eine Grenze,
 * die an zwei Stellen steht, ist keine. Der Wächter hält beide Richtungen fest:
 *
 * 1. `chroot`, `posix_setuid`, `posix_setgid` und `posix_initgroups` kommen
 *    **nur** in `Sandbox.php` vor.
 * 2. `Sandbox` selbst benutzt alle vier — sonst ist die erste Zusage wahr und
 *    wertlos, weil die Grenze abgeschafft wurde.
 *
 * Die zweite Richtung ist die, die dieses Projekt dreimal vergessen hat: Ein
 * Wächter, der beim Aufräumen zubeisst, wird beim Aufräumen abgeschaltet — und
 * einer, der nur das Fehlen prüft, merkt die Abschaffung nicht.
 */
final class SandboxReachTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Die Griffe, die eine Grenze setzen.
     *
     * **Die Liste ist absichtlich länger als die zwei, um die es ging.** Ein
     * Wächter, der nur `chroot` nennt, wird von jemandem umgangen, der
     * `posix_setuid` allein für ausreichend hält — und genau das ist der
     * gemessene Fehler aus `docs/50 §5`: Ohne `initgroups` behält das Kind die
     * Zusatzgruppen von root.
     *
     * @var list<string>
     */
    private const CONFINES = ['chroot', 'posix_setuid', 'posix_setgid', 'posix_initgroups'];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Nur `Sandbox` sperrt ein und gibt Rechte ab.
     */
    public function test_only_the_sandbox_confines(): void
    {
        $found = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/agent/src', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace($this->root().'/', '', $file->getPathname());

            if ($relative === 'agent/src/Sandbox.php') {
                continue;
            }

            $source = $this->withoutComments((string) file_get_contents($file->getPathname()));

            foreach (self::CONFINES as $call) {
                if (preg_match('/(?<![\w$>])'.preg_quote($call, '/').'\s*\(/', $source) === 1) {
                    $found[] = $relative.' ruft '.$call.'()';
                }
            }
        }

        $this->assertSame([], $found, implode("\n", [
            'Ausserhalb von Sandbox wird eingesperrt oder die Kennung gewechselt.',
            'Die Grenze aus docs/51 §5 steht an genau einer Stelle; eine zweite waere',
            'eine zweite Fassung derselben Regel, und die zweite veraltet.',
        ]));
    }

    /**
     * Und `Sandbox` benutzt sie alle — sonst prüft der Test oben nichts.
     *
     * Das ist der Nachbar der Null. Ohne ihn wäre eine `Sandbox`, aus der
     * jemand das `chroot` entfernt, für diesen Wächter der sauberste Zustand
     * überhaupt.
     */
    public function test_the_sandbox_uses_all_of_them(): void
    {
        $source = $this->withoutComments(
            (string) file_get_contents($this->root().'/agent/src/Sandbox.php'),
        );

        foreach (self::CONFINES as $call) {
            $this->assertMatchesRegularExpression(
                '/(?<![\w$>])'.preg_quote($call, '/').'\s*\(/',
                $source,
                sprintf('Sandbox ruft %s() nicht mehr — die Grenze ist abgeschafft, nicht umgezogen.', $call),
            );
        }
    }

    /**
     * Der Socket des Agenten wird im Kind geschlossen.
     *
     * **Diese Zeile hat `AgentIdentityTest` schon einmal bezahlt.** Als
     * `docs/38 §6` einen Kennungswechsel im `Runner` erwog, war einer der zwei
     * Gründe zum Verwerfen, dass *der geforkte Prozess den Socket des Agenten
     * erbt*. P6 forkt trotzdem — also muss der Socket weg, und zwar bevor
     * fremder Code im Kind läuft.
     */
    public function test_the_child_closes_what_it_inherited(): void
    {
        $source = $this->withoutComments(
            (string) file_get_contents($this->root().'/agent/src/Sandbox.php'),
        );

        $this->assertMatchesRegularExpression(
            '/private static function child\([^)]*\): never\s*\{\s*try\s*\{\s*self::closeInherited\(/',
            $source,
            implode("\n", [
                'closeInherited() ist nicht die erste Anweisung im Kind.',
                'Was danach steht, laeuft mit dem Socket des Agenten in der Hand.',
            ]),
        );
    }
}
