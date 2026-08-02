<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Journal;
use SrvPanel\Agent\Ops\ConfigValidate;
use SrvPanel\Agent\Runner;

/**
 * Der Regressionstest zu einem Fehler, den erst PHPStan gefunden hat.
 *
 * Beim Umbenennen auf englische Bezeichner bekam die lokale Argumentliste
 * denselben Namen wie der Parameter mit der Anfrage — `$args`. Danach las die
 * Operation den Zonennamen aus der Argumentliste des Prüfprogramms statt aus
 * der Anfrage und wies jede Zone als „leer" ab. Kein Test hat das gemerkt: Die
 * vorhandenen benutzten nur `kind=nginx`, und dort gibt es keinen Zonennamen.
 */
final class ConfigValidateTest extends TestCase
{
    private string $root;

    private string $file;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/srvpanel-zone-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);
        $this->file = $this->root.'/beispiel.de.zone';
        file_put_contents($this->file, "\$TTL 3600\n@ IN SOA ns1.beispiel.de. hostmaster.beispiel.de. 1 3600 600 86400 3600\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @rmdir($this->root);
    }

    private function context(): Context
    {
        $journal = new Journal('/dev/null');

        return new Context(new Runner($journal), $journal, static function (array $line): void {});
    }

    public function test_the_zone_name_is_read_from_the_request(): void
    {
        $op = new ConfigValidate([$this->root]);

        $code = null;
        $message = '';

        try {
            $op->execute(['kind' => 'zone', 'path' => $this->file, 'zone' => 'beispiel.de'], $this->context());
        } catch (AgentException $error) {
            $code = $error->errorCode;
            $message = $error->getMessage();
        }

        // Zwei Ausgänge sind in Ordnung: Der Lauf klappt (named-checkzone ist
        // da), oder das Programm fehlt. Nicht in Ordnung ist eine Beschwerde
        // über die Anfrage — der Zonenname stand darin.
        $this->assertNotSame(
            AgentException::BAD_REQUEST,
            $code,
            'Der Zonenname aus der Anfrage kam nicht an: '.$message,
        );
    }

    public function test_a_missing_zone_name_is_still_rejected(): void
    {
        $op = new ConfigValidate([$this->root]);

        try {
            $op->execute(['kind' => 'zone', 'path' => $this->file], $this->context());
            $this->fail('Ohne Zonennamen hätte die Prüfung abgewiesen werden müssen.');
        } catch (AgentException $error) {
            $this->assertSame(AgentException::BAD_REQUEST, $error->errorCode);
        }
    }

    public function test_an_unknown_kind_is_rejected(): void
    {
        $this->expectException(AgentException::class);

        (new ConfigValidate([$this->root]))->execute(
            ['kind' => 'bash', 'path' => $this->file],
            $this->context(),
        );
    }
}
