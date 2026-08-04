<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

use Socket;
use Throwable;

/**
 * Eine Verbindung, ein Auftrag.
 *
 * Die Anfrage ist genau eine JSON-Zeile, die Antwort sind NDJSON-Zeilen:
 * beliebig viele `progress` und `log`, zuletzt genau ein `result`. Danach
 * schließt der Agent. Ein Protokoll, das mehrere Aufträge über eine Verbindung
 * zuließe, müsste Zustand führen — und Zustand in einem Prozess als root ist
 * eine Verabredung, die man später bereut.
 */
final class Connection
{
    private const REQUEST_MAX = 1048576; // 1 MiB

    public function __construct(
        private readonly Socket $socket,
        private readonly Registry $registry,
        private readonly Journal $journal,
        private readonly int $appUid,
    ) {}

    public function serve(): void
    {
        $id = null;

        try {
            ['peer' => $peer, 'daten' => $first] = Peer::receive($this->socket);

            if (! $peer->mayCall($this->appUid)) {
                $this->journal->write('rejected', ['peer' => $peer->toArray()]);
                throw AgentException::denied('Dieser Benutzer darf den Agenten nicht aufrufen.');
            }

            $line = $this->readRest($first);
            $request = $this->parse($line);
            $id = $request['id'];

            $this->journal->requestStarted($id, [
                'peer' => $peer->toArray(),
                'actor' => $request['actor'],
            ]);

            $op = $this->registry->get($request['op']);

            $this->journal->write('request', [
                'op' => $request['op'],
                'mutating' => $op::mutating(),
                'args' => (object) $this->redactArgs($request['args']),
            ]);

            $context = new Context(
                new Runner($this->journal),
                $this->journal,
                fn (array $line) => $this->send($line),
                fn (): bool => Peer::gone($this->socket),
            );

            $data = $op->execute($request['args'], $context);

            $this->send(['type' => 'result', 'ok' => true, 'id' => $id, 'data' => $data]);
            $this->journal->write('result', ['ok' => true, 'op' => $request['op']]);
        } catch (AgentException $error) {
            $this->send(['type' => 'result', 'ok' => false, 'id' => $id, 'error' => $error->toArray()]);
            $this->journal->write('result', ['ok' => false, 'error' => $error->toArray()]);
        } catch (Throwable $error) {
            // Was hier ankommt, ist ein Fehler im Agenten selbst. Nach außen
            // geht nur, dass etwas schiefging — die Einzelheiten stehen im
            // Protokoll, nicht in der Antwort. Ein Stacktrace über die
            // Schnittstelle wäre eine Landkarte des Dateisystems.
            $this->send([
                'type' => 'result',
                'ok' => false,
                'id' => $id,
                'error' => ['code' => AgentException::INTERNAL, 'message' => 'Interner Fehler im Agenten.', 'details' => []],
            ]);
            $this->journal->write('crash', [
                'class' => $error::class,
                'message' => $error->getMessage(),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
            ]);
        } finally {
            $this->journal->requestEnded();
            @socket_close($this->socket);
        }
    }

    private function readRest(string $sofar): string
    {
        while (! str_contains($sofar, "\n")) {
            if (strlen($sofar) > self::REQUEST_MAX) {
                throw AgentException::badRequest('Anfrage überschreitet 1 MiB.');
            }

            $chunk = @socket_read($this->socket, 65536, PHP_BINARY_READ);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $sofar .= $chunk;
        }

        return trim($sofar);
    }

    /** @return array{id:string,op:string,args:array<string,mixed>,actor:array<string,mixed>|null} */
    private function parse(string $line): array
    {
        $raw = json_decode($line, true);

        if (! is_array($raw)) {
            throw AgentException::badRequest('Anfrage ist kein gültiges JSON.');
        }

        $version = $raw['v'] ?? null;
        if ($version !== Version::PROTOCOL) {
            throw AgentException::badRequest(
                sprintf('Protokollversion %s wird nicht bedient.', var_export($version, true)),
                ['expected' => Version::PROTOCOL],
            );
        }

        $op = $raw['op'] ?? null;
        if (! is_string($op) || ! preg_match('/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)+$/D', $op)) {
            throw AgentException::badRequest('Feld op fehlt oder ist unzulässig.');
        }

        $args = $raw['args'] ?? [];
        if (! is_array($args)) {
            throw AgentException::badRequest('Feld args muss ein Objekt sein.');
        }

        $id = $raw['id'] ?? null;
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_\-]{1,64}$/D', $id)) {
            throw AgentException::badRequest('Feld id fehlt oder ist unzulässig.');
        }

        $actor = $raw['actor'] ?? null;

        return [
            'id' => $id,
            'op' => $op,
            'args' => $args,
            'actor' => is_array($actor) ? $actor : null,
        ];
    }

    /**
     * Argumente fürs Protokoll kürzen.
     *
     * Ein Zertifikatsschlüssel oder ein Datenbankpasswort steht in den
     * Argumenten mancher Operationen. Im Protokoll hat beides nichts zu
     * suchen, und „später filtern" ist kein Plan — deshalb hier, an der einen
     * Stelle, durch die alles geht.
     *
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    private function redactArgs(array $args): array
    {
        $secretish = ['passwort', 'password', 'schluessel', 'key', 'secret', 'token', 'pem'];
        $clean = [];

        foreach ($args as $name => $value) {
            $key = strtolower((string) $name);

            foreach ($secretish as $needle) {
                if (str_contains($key, $needle)) {
                    $clean[$name] = '···';

                    continue 2;
                }
            }

            $clean[$name] = is_scalar($value) || $value === null ? $value : '…';
        }

        return $clean;
    }

    /** @param array<string,mixed> $line */
    private function send(array $line): void
    {
        $json = json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $json = '{"type":"result","ok":false,"error":{"code":"internal","message":"Antwort nicht kodierbar.","details":{}}}';
        }

        @socket_write($this->socket, $json."\n");
    }
}
