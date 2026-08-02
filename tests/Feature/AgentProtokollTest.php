<?php

declare(strict_types=1);

namespace Tests\Feature;

use CloudSrv\Agent\AgentException;
use CloudSrv\Agent\Client;
use PHPUnit\Framework\TestCase;

/**
 * Der Agent gegen einen echten Socket — kein Ersatzobjekt.
 *
 * Für das Protokoll ist eine Attrappe wertlos: Was hier schiefgehen kann,
 * sind Rechte am Socket, die Zusatzdaten des Kernels, die Zeilentrennung im
 * Strom und das Verhalten beim Abbruch. Nichts davon würde eine Attrappe
 * zeigen.
 */
final class AgentProtokollTest extends TestCase
{
    private string $verzeichnis;

    private string $socket;

    private int $pid = 0;

    protected function setUp(): void
    {
        if (! function_exists('pcntl_fork') || ! defined('SCM_CREDENTIALS')) {
            $this->markTestSkipped('Der Test braucht pcntl und SCM_CREDENTIALS.');
        }

        // Kurzer Pfad: sun_path im Kernel fasst 108 Zeichen, und ein
        // Temp-Verzeichnis mit langem Namen sprengt das.
        $this->verzeichnis = '/tmp/cs-'.bin2hex(random_bytes(4));
        mkdir($this->verzeichnis, 0o755, true);
        $this->socket = $this->verzeichnis.'/a.sock';

        $benutzer = posix_getpwuid(posix_getuid());
        $gruppe = posix_getgrgid(posix_getgid());

        $befehl = [
            PHP_BINARY,
            dirname(__DIR__, 2).'/agent/bin/cloudsrv-agentd',
            'serve',
            '--socket='.$this->socket,
            '--log='.$this->verzeichnis.'/a.log',
            '--unprivileged',
            '--user='.($benutzer['name'] ?? 'root'),
            '--group='.($gruppe['name'] ?? 'root'),
            '--config='.$this->verzeichnis.'/gibt-es-nicht.json',
        ];

        $pid = pcntl_fork();

        if ($pid === 0) {
            pcntl_exec($befehl[0], array_slice($befehl, 1));
            exit(127);
        }

        $this->pid = $pid;

        for ($i = 0; $i < 100 && ! file_exists($this->socket); $i++) {
            usleep(50000);
        }

        if (! file_exists($this->socket)) {
            $this->fail('Der Agent ist nicht gestartet.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->pid > 0) {
            posix_kill($this->pid, SIGTERM);
            pcntl_waitpid($this->pid, $stand);
        }

        @unlink($this->socket);
        @unlink($this->verzeichnis.'/a.log');
        @rmdir($this->verzeichnis);
    }

    public function test_ping_antwortet_mit_protokollversion(): void
    {
        $daten = (new Client($this->socket))->ruf('agent.ping');

        $this->assertSame(1, $daten['protokoll']);
        $this->assertNotEmpty($daten['agent']);
    }

    public function test_socket_ist_nicht_fuer_alle_lesbar(): void
    {
        // 0660: Wer nicht root ist und nicht in der Gruppe, kommt nicht heran.
        // Das ist die erste der beiden Schranken; die zweite prüft die
        // Identität des Aufrufers.
        $rechte = fileperms($this->socket) & 0o777;

        $this->assertSame(0o660, $rechte, sprintf('Socket steht auf 0%o.', $rechte));
    }

    public function test_system_info_liefert_werte_aus_proc(): void
    {
        $daten = (new Client($this->socket))->ruf('system.info');

        $this->assertGreaterThan(0, $daten['uptime_s']);
        $this->assertArrayHasKey('gesamt', $daten['speicher']);
        $this->assertCount(3, $daten['load']);
    }

    public function test_unbekannte_operation_wird_abgewiesen(): void
    {
        try {
            (new Client($this->socket))->ruf('gibt.es.nicht');
            $this->fail('Die Operation hätte abgewiesen werden müssen.');
        } catch (AgentException $fehler) {
            $this->assertSame(AgentException::UNKNOWN_OP, $fehler->fehlercode);
        }
    }

    public function test_unit_name_mit_pfad_wird_abgewiesen(): void
    {
        try {
            (new Client($this->socket))->ruf('service.status', ['unit' => '../../etc/shadow']);
            $this->fail('Der Unit-Name hätte abgewiesen werden müssen.');
        } catch (AgentException $fehler) {
            $this->assertSame(AgentException::BAD_REQUEST, $fehler->fehlercode);
        }
    }

    public function test_pfad_ausserhalb_der_wurzeln_wird_abgewiesen(): void
    {
        try {
            (new Client($this->socket))->ruf('config.validate', ['art' => 'nginx', 'pfad' => '/etc/passwd']);
            $this->fail('Der Pfad hätte abgewiesen werden müssen.');
        } catch (AgentException $fehler) {
            $this->assertSame(AgentException::DENIED, $fehler->fehlercode);
        }
    }

    public function test_falsche_protokollversion_wird_abgewiesen(): void
    {
        $verbindung = socket_create(AF_UNIX, SOCK_STREAM, 0);
        socket_connect($verbindung, $this->socket);
        socket_write($verbindung, json_encode(['v' => 99, 'id' => 'test', 'op' => 'agent.ping', 'args' => []])."\n");

        $antwort = json_decode((string) socket_read($verbindung, 65536, PHP_NORMAL_READ), true);
        socket_close($verbindung);

        $this->assertFalse($antwort['ok']);
        $this->assertSame(AgentException::BAD_REQUEST, $antwort['error']['code']);
    }

    public function test_muell_statt_json_wird_abgewiesen(): void
    {
        $verbindung = socket_create(AF_UNIX, SOCK_STREAM, 0);
        socket_connect($verbindung, $this->socket);
        socket_write($verbindung, "das ist kein json\n");

        $antwort = json_decode((string) socket_read($verbindung, 65536, PHP_NORMAL_READ), true);
        socket_close($verbindung);

        $this->assertFalse($antwort['ok']);
    }

    public function test_geheimnisse_stehen_nicht_im_protokoll(): void
    {
        try {
            (new Client($this->socket))->ruf('config.validate', [
                'art' => 'nginx',
                'pfad' => '/etc/passwd',
                'passwort' => 'streng-geheim-4711',
            ]);
        } catch (AgentException) {
            // Der Aufruf soll scheitern; geprüft wird, was dabei protokolliert wurde.
        }

        $protokoll = (string) file_get_contents($this->verzeichnis.'/a.log');

        $this->assertStringNotContainsString('streng-geheim-4711', $protokoll);
        $this->assertStringContainsString('···', $protokoll);
    }
}
