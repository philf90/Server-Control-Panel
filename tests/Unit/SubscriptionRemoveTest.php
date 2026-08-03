<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Journal;
use SrvPanel\Agent\Ops\SubscriptionRemove;
use SrvPanel\Agent\Runner;

/**
 * Die Schranken vor dem `rm -rf`.
 *
 * **Warum das gegen ein echtes Dateisystem läuft und nicht gegen einen
 * Doppelgänger.** Die Frage, die dieser Test beantworten muss, lautet: Folgt
 * das Löschen einem Symlink? Ein Doppelgänger, der `is_link` nachstellt,
 * beantwortet sie nicht — er beantwortet, ob ich mir den Ablauf richtig
 * vorstelle. Deshalb legt jeder Fall unten einen Baum in einem
 * Wegwerf-Verzeichnis an, hängt ein Ziel daneben, das nicht verschwinden darf,
 * und sieht danach nach, ob es noch da ist.
 *
 * Die Operation selbst ruft dabei nichts auf, was root braucht: Geprüft wird
 * `removeTree`, der Teil, der den Baum abträgt. Die Programme (`userdel`,
 * `setquota`) sind nicht Gegenstand dieser Datei.
 */
final class SubscriptionRemoveTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir().'/srvpanel-remove-'.bin2hex(random_bytes(6));
        mkdir($this->sandbox, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->scrub($this->sandbox);
    }

    /** Aufräumen ohne die zu prüfende Methode — sonst prüfte sich der Test selbst. */
    private function scrub(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }

        if (is_link($path) || ! is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->scrub($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }

    /** `removeTree` ist privat — geprüft wird die Wirkung, nicht die Sichtbarkeit. */
    private function removeTree(string $path): void
    {
        $method = new \ReflectionMethod(SubscriptionRemove::class, 'removeTree');
        $method->invoke(new SubscriptionRemove, $path);
    }

    public function test_a_symlink_inside_the_tree_is_removed_and_not_followed(): void
    {
        // Der Fall, um den es geht: Der Kunde besitzt httpdocs und legt darin
        // einen Verweis auf ein Verzeichnis ausserhalb an. Würde das Löschen
        // ihm folgen, nähme es beim Abräumen eines Abonnements fremde Dateien
        // mit — als root.
        $tree = $this->sandbox.'/abo';
        $foreign = $this->sandbox.'/fremd';

        mkdir($tree.'/httpdocs', 0700, true);
        mkdir($foreign, 0700, true);
        file_put_contents($foreign.'/wichtig.txt', 'darf nicht verschwinden');

        symlink($foreign, $tree.'/httpdocs/ausbruch');

        $this->removeTree($tree);

        // Die wichtigste Zusicherung zuerst: Schlägt der Fall fehl, soll die
        // Meldung von den fremden Dateien sprechen und nicht davon, dass ein
        // Verzeichnis nicht aufgeräumt wurde.
        $this->assertFileExists($foreign.'/wichtig.txt', 'Das Löschen ist dem Verweis gefolgt und hat fremde Dateien mitgenommen.');
        $this->assertDirectoryExists($foreign, 'Das Ziel des Verweises steht noch.');
        $this->assertDirectoryDoesNotExist($tree, 'Der Baum des Abonnements ist weg.');
    }

    public function test_a_symlink_to_a_file_is_removed_and_not_followed(): void
    {
        $tree = $this->sandbox.'/abo';
        $target = $this->sandbox.'/fremd.txt';

        mkdir($tree, 0700, true);
        file_put_contents($target, 'bleibt');
        symlink($target, $tree.'/verweis');

        $this->removeTree($tree);

        $this->assertDirectoryDoesNotExist($tree);
        $this->assertFileExists($target);
        $this->assertSame('bleibt', file_get_contents($target));
    }

    public function test_the_whole_tree_is_gone(): void
    {
        // Das Abnahmekriterium von P2 verlangt, dass nichts zurückbleibt —
        // auch nicht in der Tiefe und auch nichts Verstecktes.
        $tree = $this->sandbox.'/abo';

        mkdir($tree.'/httpdocs/tief/tiefer', 0700, true);
        mkdir($tree.'/.ssh', 0700, true);
        file_put_contents($tree.'/httpdocs/index.php', '<?php');
        file_put_contents($tree.'/httpdocs/tief/tiefer/datei', 'x');
        file_put_contents($tree.'/.ssh/authorized_keys', 'ssh-ed25519 AAAA');

        $this->removeTree($tree);

        $this->assertDirectoryDoesNotExist($tree);
    }

    /** @return array<string, array{0: string}> */
    public static function refusedRoots(): array
    {
        return [
            'die Wurzel aller Abonnements' => ['/var/www/vhosts'],
            'die Wurzel mit Schrägstrich' => ['/var/www/vhosts/'],
        ];
    }

    #[DataProvider('refusedRoots')]
    public function test_the_root_of_all_subscriptions_is_refused(string $path): void
    {
        $method = new \ReflectionMethod(SubscriptionRemove::class, 'removeRoot');

        $this->expectException(AgentException::class);

        $method->invoke(new SubscriptionRemove, $path);
    }

    public function test_removing_something_that_is_gone_succeeds(): void
    {
        // Wiederholbarkeit. Ein Abonnement, das es nicht mehr gibt, ist der
        // gewünschte Zustand — schlüge der zweite Versuch fehl, hinge jeder
        // abgebrochene Löschvorgang für immer.
        $method = new \ReflectionMethod(SubscriptionRemove::class, 'removeRoot');

        $this->assertFalse($method->invoke(new SubscriptionRemove, $this->sandbox.'/gibtesnicht'));
    }

    public function test_the_operation_declares_itself_as_changing_the_system(): void
    {
        $this->assertSame('subscription.remove', SubscriptionRemove::name());
        $this->assertTrue(SubscriptionRemove::mutating());
    }

    public function test_it_refuses_a_name_that_is_a_path(): void
    {
        // Die Namensprüfung ist dieselbe wie beim Anlegen — hier steht die
        // Gegenprobe, dass sie beim Löschen auch wirklich benutzt wird und
        // nicht bloss beim Anlegen.
        $this->expectException(AgentException::class);

        (new SubscriptionRemove)->execute(['name' => '../../etc', 'user' => 'p1001'], $this->context());
    }

    public function test_it_refuses_a_foreign_system_user(): void
    {
        $this->expectException(AgentException::class);

        (new SubscriptionRemove)->execute(['name' => 'beispiel.de', 'user' => 'root'], $this->context());
    }

    private function context(): Context
    {
        return new Context(
            new Runner(new Journal('/dev/null')),
            new Journal('/dev/null'),
            static function (array $message): void {},
        );
    }
}
