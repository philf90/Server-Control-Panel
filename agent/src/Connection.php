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

            /*
             * **Der Beleg der Sandbox wird hier angehängt und nicht in der
             * Operation.** `docs/51 §4` verlangt in Punkt 13 und 14, dass jeder
             * Datei-Vorgang meldet, unter welcher `uid` und mit welchen Gruppen
             * er lief; die Begründung für diesen Ort steht in
             * {@see Context::recordRanAs()}.
             *
             * Kurz: Zwei der dreizehn Datei-Operationen bauen aus dem Ergebnis
             * der Sandbox ein frisches Feld-Array. Ein Beleg, den sie
             * weiterreichen müssten, wäre bei ihnen verschwunden — und bei der
             * nächsten Operation, die ihr Ergebnis umbaut, wieder.
             *
             * > **Ein Beleg, den die Zwischenstelle weiterreichen muss, ist bei
             * > der ersten Zwischenstelle weg, die ihn nicht kennt.**
             */
            $ranAs = $context->ranAs();

            if ($ranAs !== null) {
                $data[Context::RAN_AS] = $ranAs;
            }

            $this->send(['type' => 'result', 'ok' => true, 'id' => $id, 'data' => $data]);

            /*
             * **Und derselbe Beleg noch einmal ins Protokoll — das ist keine
             * Doppelung, sondern der Unterschied zwischen einer Auskunft und
             * einer Aufzeichnung.**
             *
             * Die Antwort oben geht an den Aufrufer, und was der damit macht,
             * entscheidet er. Für die Vorgänge über die Warteschlange ist das
             * `operations.result`; für `files.*` ist es **nichts**:
             * `Files\Files` ruft den Agenten unmittelbar auf, ohne Vorgang und
             * ohne Zeile in der Datenbank — richtig so, denn eine
             * Verzeichnisliste wartet nicht auf einen Arbeiter.
             *
             * Genau daran ist `docs/61 §0a` beim ersten Anlauf gescheitert: Der
             * Beleg entstand, wurde weitergereicht — und war beim Ablesen auf
             * `cloudsrv24` nirgends. Dieselbe Lehre wie eine Ebene tiefer, wo
             * die Sandbox ihn erhoben und verworfen hatte:
             *
             * > **Eine Auskunft, die entsteht und die niemand weitergibt, ist so
             * > gut wie keine.**
             *
             * Im Protokoll steht sie je Anfrage, für jede Operation, dauerhaft
             * und lesbar ohne Datenbank. `uid` und Gruppen sind keine
             * Geheimnisse; was hier nicht hingehört, filtert
             * {@see self::redactArgs()} an der Anfrage.
             */
            $this->journal->write('result', array_filter([
                'ok' => true,
                'op' => $request['op'],
                Context::RAN_AS => $ranAs,
            ], static fn (mixed $wert): bool => $wert !== null));
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
