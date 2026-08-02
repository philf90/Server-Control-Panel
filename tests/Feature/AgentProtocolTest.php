<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;

/**
 * Der Agent gegen einen echten Socket — kein Ersatzobjekt.
 *
 * Für das Protokoll ist eine Attrappe wertlos: Was hier schiefgehen kann,
 * sind Rechte am Socket, die Zusatzdaten des Kernels, die Zeilentrennung im
 * Strom und das Verhalten beim Abbruch. Nichts davon würde eine Attrappe
 * zeigen.
 */
final class AgentProtocolTest extends TestCase
{
    private string $directory;

    private string $socket;

    private int $pid = 0;

    protected function setUp(): void
    {
        if (! function_exists('pcntl_fork') || ! defined('SCM_CREDENTIALS')) {
            $this->markTestSkipped('Der Test braucht pcntl und SCM_CREDENTIALS.');
        }

        // Kurzer Pfad: sun_path im Kernel fasst 108 Zeichen, und ein
        // Temp-Verzeichnis mit langem Namen sprengt das.
        $this->directory = '/tmp/cs-'.bin2hex(random_bytes(4));
        mkdir($this->directory, 0o755, true);
        $this->socket = $this->directory.'/a.sock';

        $user = posix_getpwuid(posix_getuid());
        $group = posix_getgrgid(posix_getgid());

        $command = [
            PHP_BINARY,
            dirname(__DIR__, 2).'/agent/bin/srvpanel-agentd',
            'serve',
            '--socket='.$this->socket,
            '--log='.$this->directory.'/a.log',
            '--unprivileged',
            '--user='.($user['name'] ?? 'root'),
            '--group='.($group['name'] ?? 'root'),
            '--config='.$this->directory.'/gibt-es-nicht.json',
        ];

        $pid = pcntl_fork();

        if ($pid === 0) {
            pcntl_exec($command[0], array_slice($command, 1));
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
            pcntl_waitpid($this->pid, $status);
        }

        @unlink($this->socket);
        @unlink($this->directory.'/a.log');
        @rmdir($this->directory);
    }

    public function test_ping_answers_with_protocol_version(): void
    {
        $data = (new Client($this->socket))->call('agent.ping');

        $this->assertSame(1, $data['protocol']);
        $this->assertNotEmpty($data['agent']);
    }

    public function test_socket_is_not_world_accessible(): void
    {
        // 0660: Wer nicht root ist und nicht in der Gruppe, kommt nicht heran.
        // Das ist die erste der beiden Schranken; die zweite prüft die
        // Identität des Aufrufers.
        $mode = fileperms($this->socket) & 0o777;

        $this->assertSame(0o660, $mode, sprintf('Socket steht auf 0%o.', $mode));
    }

    public function test_system_info_returns_values_from_proc(): void
    {
        $data = (new Client($this->socket))->call('system.info');

        $this->assertGreaterThan(0, $data['uptime_s']);
        $this->assertArrayHasKey('total', $data['memory']);
        $this->assertCount(3, $data['load']);
    }

    public function test_rejects_unknown_operation(): void
    {
        try {
            (new Client($this->socket))->call('gibt.es.nicht');
            $this->fail('Die Operation hätte abgewiesen werden müssen.');
        } catch (AgentException $error) {
            $this->assertSame(AgentException::UNKNOWN_OP, $error->errorCode);
        }
    }

    public function test_rejects_unit_name_containing_path(): void
    {
        try {
            (new Client($this->socket))->call('service.status', ['unit' => '../../etc/shadow']);
            $this->fail('Der Unit-Name hätte abgewiesen werden müssen.');
        } catch (AgentException $error) {
            $this->assertSame(AgentException::BAD_REQUEST, $error->errorCode);
        }
    }

    public function test_rejects_path_outside_allowed_roots(): void
    {
        try {
            (new Client($this->socket))->call('config.validate', ['kind' => 'nginx', 'path' => '/etc/passwd']);
            $this->fail('Der Pfad hätte abgewiesen werden müssen.');
        } catch (AgentException $error) {
            $this->assertSame(AgentException::DENIED, $error->errorCode);
        }
    }

    public function test_rejects_wrong_protocol_version(): void
    {
        $connection = socket_create(AF_UNIX, SOCK_STREAM, 0);
        socket_connect($connection, $this->socket);
        socket_write($connection, json_encode(['v' => 99, 'id' => 'test', 'op' => 'agent.ping', 'args' => []])."\n");

        $response = json_decode((string) socket_read($connection, 65536, PHP_NORMAL_READ), true);
        socket_close($connection);

        $this->assertFalse($response['ok']);
        $this->assertSame(AgentException::BAD_REQUEST, $response['error']['code']);
    }

    public function test_rejects_garbage_instead_of_json(): void
    {
        $connection = socket_create(AF_UNIX, SOCK_STREAM, 0);
        socket_connect($connection, $this->socket);
        socket_write($connection, "das ist kein json\n");

        $response = json_decode((string) socket_read($connection, 65536, PHP_NORMAL_READ), true);
        socket_close($connection);

        $this->assertFalse($response['ok']);
    }

    public function test_rejects_a_service_action_on_a_foreign_unit(): void
    {
        // Den Zustand einer beliebigen Unit zu lesen ist harmlos. Eine
        // beliebige Unit zu stoppen ist es nicht — sshd zum Beispiel.
        try {
            (new Client($this->socket))->call('service.action', ['unit' => 'ssh.service', 'action' => 'stop']);
            $this->fail('Die Unit hätte abgewiesen werden müssen.');
        } catch (AgentException $error) {
            $this->assertSame(AgentException::DENIED, $error->errorCode);
        }
    }

    public function test_rejects_an_unknown_service_action(): void
    {
        try {
            (new Client($this->socket))->call('service.action', ['unit' => 'nginx.service', 'action' => 'mask']);
            $this->fail('Die Aktion hätte abgewiesen werden müssen.');
        } catch (AgentException $error) {
            $this->assertSame(AgentException::BAD_REQUEST, $error->errorCode);
        }
    }

    public function test_secrets_are_not_written_to_the_log(): void
    {
        try {
            (new Client($this->socket))->call('config.validate', [
                'kind' => 'nginx',
                'path' => '/etc/passwd',
                'passwort' => 'streng-geheim-4711',
            ]);
        } catch (AgentException) {
            // Der Aufruf soll scheitern; geprüft wird, was dabei protokolliert wurde.
        }

        $log = (string) file_get_contents($this->directory.'/a.log');

        $this->assertStringNotContainsString('streng-geheim-4711', $log);
        $this->assertStringContainsString('···', $log);
    }
}
