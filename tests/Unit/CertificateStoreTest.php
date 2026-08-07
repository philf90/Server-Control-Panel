<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\CertificateName;
use SrvPanel\Agent\Acme\Store;
use SrvPanel\Agent\AgentException;

/**
 * Was `Store::remove()` löschen darf — und was es niemals anfassen wird.
 *
 * **Dieser Code läuft als root.** Er entfernt Verzeichnisse unter
 * `/etc/srvpanel/tls/certs`, und der Name dafür kommt aus der Anwendung. Die
 * Eindämmung folgt schon aus {@see CertificateName}, das
 * jeden Namen durch dieselbe Prüfung schickt wie einen Domainnamen — aber eine
 * Zusicherung, die nur woanders steht, ist die Sorte Beleg, die dieses Projekt
 * mehrfach eingeholt hat.
 *
 * **Kein rekursives Löschen.** Entfernt werden genau die Dateien, die
 * `write()` anlegt. Liegt sonst noch etwas im Verzeichnis, bleibt es stehen und
 * wird gemeldet — ein `rm -rf` auf einen Pfad, der aus einem Namen entsteht,
 * wäre in einem Prozess mit Systemrechten genau die Freiheit, die dieses
 * Projekt nirgends gewährt.
 */
final class CertificateStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/srvpanel-certs-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0o750, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));

        parent::tearDown();
    }

    private function store(): Store
    {
        return new Store($this->root);
    }

    public function test_it_removes_chain_key_and_directory(): void
    {
        $store = $this->store();
        $store->write('beispiel.de', "KETTE\n", "SCHLUESSEL\n");

        $this->assertFileExists($this->root.'/beispiel.de/privkey.pem');

        $result = $store->remove('beispiel.de');

        $this->assertTrue($result['removed']);
        $this->assertDirectoryDoesNotExist($this->root.'/beispiel.de');
    }

    /** Ein Zertifikat, das es nicht mehr gibt, ist der gewünschte Zustand. */
    public function test_removing_twice_is_not_an_error(): void
    {
        $store = $this->store();
        $store->write('beispiel.de', "K\n", "S\n");
        $store->remove('beispiel.de');

        $result = $store->remove('beispiel.de');

        $this->assertFalse($result['removed']);
        $this->assertSame([], $result['left_behind']);
    }

    /** Ein Platzhalter liegt unter seinem Schlüssel und geht denselben Weg. */
    public function test_a_wildcard_is_removed_under_its_key(): void
    {
        $store = $this->store();
        $store->write('*.beispiel.de', "K\n", "S\n");

        $this->assertDirectoryExists($this->root.'/_wildcard.beispiel.de');
        $this->assertTrue($store->remove('*.beispiel.de')['removed']);
    }

    /**
     * **Was nicht dazugehört, bleibt liegen — und wird genannt.**
     *
     * Ein Verzeichnis, in dem jemand etwas abgelegt hat, verschwindet nicht
     * stillschweigend. Der Betreiber soll sehen, was ihn dort erwartet.
     */
    public function test_a_foreign_file_keeps_the_directory(): void
    {
        $store = $this->store();
        $store->write('fremd.de', "K\n", "S\n");
        file_put_contents($this->root.'/fremd.de/notiz.txt', 'x');

        $result = $store->remove('fremd.de');

        $this->assertFalse($result['removed']);
        $this->assertDirectoryExists($this->root.'/fremd.de');
        $this->assertSame(['notiz.txt'], $result['left_behind']);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function ausbruchsversuche(): array
    {
        return [['../etc'], ['a/../../b'], ['/etc/shadow'], ['..'], [''], ['.']];
    }

    /** Kein Name führt aus dem Zertifikatsverzeichnis heraus. */
    #[DataProvider('ausbruchsversuche')]
    public function test_no_name_escapes_the_store(string $name): void
    {
        $this->expectException(AgentException::class);

        $this->store()->remove($name);
    }

    /**
     * **Eine Verknüpfung wird nicht verfolgt.**
     *
     * `is_dir()` täte es, und dann zeigte das Löschen woandershin als das
     * Verzeichnis, das gemeint war — bei einem Prozess mit Systemrechten der
     * Unterschied zwischen einem aufgeräumten Ablageort und einem Datenverlust.
     */
    public function test_a_symlink_is_refused_and_its_target_survives(): void
    {
        $target = $this->root.'-ziel';
        mkdir($target, 0o750, true);
        file_put_contents($target.'/wichtig', 'bitte nicht löschen');
        symlink($target, $this->root.'/verknuepft.de');

        $ueberlebt = false;

        try {
            $this->store()->remove('verknuepft.de');
            $this->fail('Eine Verknüpfung als Ablageort muss abgewiesen werden.');
        } catch (AgentException) {
            // erwartet
        } finally {
            $ueberlebt = is_file($target.'/wichtig');
            exec('rm -rf '.escapeshellarg($target));
        }

        $this->assertTrue($ueberlebt, 'Das Ziel der Verknüpfung darf nicht angefasst werden.');
    }
}
