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
     * Der rohe Baumlauf wird nur dort gerufen, wo kein Kunde schreiben kann.
     *
     * **Der Anlass ist eine Messung am ausgelieferten Code.** `removeTree()`
     * lief bis P6 als root über Verzeichnisse, die dem Kunden gehören. Gegen
     * einen Prozess, der `renameat2(RENAME_EXCHANGE)` fährt, hat der Rückbau
     * dabei in **5 von 120 Durchgängen** Dateien ausserhalb des Abonnements
     * gelöscht — mit der Gegenprobe daneben, die in denselben 120 Durchgängen
     * über die Sandbox null Mal traf.
     *
     * Der Baumlauf selbst ist deshalb nicht falsch; falsch ist, ihn dort zu
     * rufen, wo jemand mitschreiben darf. Die Ausnahmen stehen hier mit ihrer
     * Begründung, so wie `EngineReachTest` es für die Datenbanksysteme tut —
     * eine Liste ohne Begründung wächst, bis sie alles enthält.
     *
     * @var array<string, string>
     */
    private const MAY_WALK_AS_ROOT = [
        // Der Rest des Schemas aus §4.5, nachdem die Sandbox den Inhalt
        // abgetragen hat: Verzeichnisse an der Wurzel, und die gehört
        // `root:root 0755`. Der Kunde kann dort nichts ersetzen.
        'agent/src/Ops/SubscriptionRemove.php' => 'die Wurzel selbst, nach purgeContents()',

        // `/var/lib/srvpanel/dumps`, root-eigen und ausserhalb jedes
        // Abonnements. Kein Kundenpfad, kein Zeitfenster.
        'agent/src/Db/Dump.php' => 'das Dump-Verzeichnis, ausserhalb der Abonnements',
    ];

    /**
     * Niemand sonst trägt einen Baum als root ab.
     */
    public function test_the_raw_tree_walk_is_called_only_where_no_customer_writes(): void
    {
        $callers = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/agent/src', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace($this->root().'/', '', $file->getPathname());

            // Filesystem selbst ist der Baumlauf — es ruft ihn in der Sandbox.
            if ($relative === 'agent/src/Filesystem.php') {
                continue;
            }

            $source = $this->withoutComments((string) file_get_contents($file->getPathname()));

            if (preg_match('/Filesystem::removeTree\s*\(/', $source) === 1) {
                $callers[] = $relative;
            }
        }

        sort($callers);
        $allowed = array_keys(self::MAY_WALK_AS_ROOT);
        sort($allowed);

        $this->assertSame($allowed, $callers, implode("\n", [
            'Filesystem::removeTree() wird an einer Stelle gerufen, die nicht begruendet ist.',
            'Als root ueber einen Baum zu laufen, in den ein Kunde schreiben darf, hat in',
            '5 von 120 gemessenen Durchgaengen Dateien ausserhalb des Abonnements geloescht.',
            'Wer eine Stelle hinzufuegt, traegt sie in MAY_WALK_AS_ROOT ein — mit dem Grund,',
            'warum dort niemand mitschreibt.',
        ]));
    }

    /**
     * Und der Rückbau räumt vorher mit der Sandbox auf.
     *
     * Der Nachbar der Liste oben: Ohne diesen Test wäre ein
     * `SubscriptionRemove`, aus dem jemand `purgeContents()` entfernt, weiter
     * eingetragen und damit erlaubt — und liefe wieder als root über
     * `httpdocs`.
     */
    public function test_the_teardown_purges_before_it_walks(): void
    {
        $source = $this->withoutComments(
            (string) file_get_contents($this->root().'/agent/src/Ops/SubscriptionRemove.php'),
        );

        $purge = strpos($source, 'Filesystem::purgeContents(');
        $walk = strpos($source, 'Filesystem::removeTree(');

        $this->assertIsInt($purge, 'Der Rückbau räumt nicht mehr über die Sandbox auf.');
        $this->assertIsInt($walk, 'Der Rückbau trägt die Wurzel nicht mehr ab.');
        $this->assertGreaterThan(
            (int) $purge,
            (int) $walk,
            'Der Baumlauf als root steht vor dem Aufräumen in der Sandbox — dann läuft er über die Kundendaten.',
        );
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
