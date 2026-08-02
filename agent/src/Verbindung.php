<?php

declare(strict_types=1);

namespace CloudSrv\Agent;

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
final class Verbindung
{
    private const ANFRAGE_MAX = 1048576; // 1 MiB

    public function __construct(
        private readonly Socket $socket,
        private readonly Registry $registry,
        private readonly Journal $journal,
        private readonly Config $config,
        private readonly int $anwendungsUid,
    ) {}

    public function bediene(): void
    {
        $id = null;

        try {
            ['peer' => $peer, 'daten' => $erste] = Peer::empfange($this->socket);

            if (! $peer->darf($this->anwendungsUid)) {
                $this->journal->schreibe('abgewiesen', ['peer' => $peer->toArray()]);
                throw AgentException::denied('Dieser Benutzer darf den Agenten nicht aufrufen.');
            }

            $zeile = $this->leseRest($erste);
            $anfrage = $this->deute($zeile);
            $id = $anfrage['id'];

            $this->journal->vorgangBeginnt($id, [
                'peer' => $peer->toArray(),
                'actor' => $anfrage['actor'],
            ]);

            $op = $this->registry->hole($anfrage['op']);

            $this->journal->schreibe('auftrag', [
                'op' => $anfrage['op'],
                'veraendernd' => $op::veraendernd(),
                'args' => (object) $this->gekuerzteArgumente($anfrage['args']),
            ]);

            $kontext = new Kontext(
                new Runner($this->journal),
                $this->journal,
                fn (array $zeile) => $this->sende($zeile),
            );

            $daten = $op->fuehreAus($anfrage['args'], $kontext);

            $this->sende(['type' => 'result', 'ok' => true, 'id' => $id, 'data' => $daten]);
            $this->journal->schreibe('ergebnis', ['ok' => true, 'op' => $anfrage['op']]);
        } catch (AgentException $fehler) {
            $this->sende(['type' => 'result', 'ok' => false, 'id' => $id, 'error' => $fehler->toArray()]);
            $this->journal->schreibe('ergebnis', ['ok' => false, 'fehler' => $fehler->toArray()]);
        } catch (Throwable $fehler) {
            // Was hier ankommt, ist ein Fehler im Agenten selbst. Nach außen
            // geht nur, dass etwas schiefging — die Einzelheiten stehen im
            // Protokoll, nicht in der Antwort. Ein Stacktrace über die
            // Schnittstelle wäre eine Landkarte des Dateisystems.
            $this->sende([
                'type' => 'result',
                'ok' => false,
                'id' => $id,
                'error' => ['code' => AgentException::INTERNAL, 'message' => 'Interner Fehler im Agenten.', 'details' => []],
            ]);
            $this->journal->schreibe('panne', [
                'klasse' => $fehler::class,
                'meldung' => $fehler->getMessage(),
                'datei' => $fehler->getFile(),
                'zeile' => $fehler->getLine(),
            ]);
        } finally {
            $this->journal->vorgangEndet();
            @socket_close($this->socket);
        }
    }

    private function leseRest(string $bisher): string
    {
        while (! str_contains($bisher, "\n")) {
            if (strlen($bisher) > self::ANFRAGE_MAX) {
                throw AgentException::badRequest('Anfrage überschreitet 1 MiB.');
            }

            $stueck = @socket_read($this->socket, 65536, PHP_BINARY_READ);

            if ($stueck === false || $stueck === '') {
                break;
            }

            $bisher .= $stueck;
        }

        return trim($bisher);
    }

    /** @return array{id:string,op:string,args:array<string,mixed>,actor:array<string,mixed>|null} */
    private function deute(string $zeile): array
    {
        $roh = json_decode($zeile, true);

        if (! is_array($roh)) {
            throw AgentException::badRequest('Anfrage ist kein gültiges JSON.');
        }

        $version = $roh['v'] ?? null;
        if ($version !== Version::PROTOKOLL) {
            throw AgentException::badRequest(
                sprintf('Protokollversion %s wird nicht bedient.', var_export($version, true)),
                ['erwartet' => Version::PROTOKOLL],
            );
        }

        $op = $roh['op'] ?? null;
        if (! is_string($op) || ! preg_match('/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)+$/', $op)) {
            throw AgentException::badRequest('Feld op fehlt oder ist unzulässig.');
        }

        $args = $roh['args'] ?? [];
        if (! is_array($args)) {
            throw AgentException::badRequest('Feld args muss ein Objekt sein.');
        }

        $id = $roh['id'] ?? null;
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $id)) {
            throw AgentException::badRequest('Feld id fehlt oder ist unzulässig.');
        }

        $actor = $roh['actor'] ?? null;

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
    private function gekuerzteArgumente(array $args): array
    {
        $geheim = ['passwort', 'password', 'schluessel', 'key', 'secret', 'token', 'pem'];
        $sauber = [];

        foreach ($args as $name => $wert) {
            $schluessel = strtolower((string) $name);

            foreach ($geheim as $muster) {
                if (str_contains($schluessel, $muster)) {
                    $sauber[$name] = '···';

                    continue 2;
                }
            }

            $sauber[$name] = is_scalar($wert) || $wert === null ? $wert : '…';
        }

        return $sauber;
    }

    /** @param array<string,mixed> $zeile */
    private function sende(array $zeile): void
    {
        $json = json_encode($zeile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $json = '{"type":"result","ok":false,"error":{"code":"internal","message":"Antwort nicht kodierbar.","details":{}}}';
        }

        @socket_write($this->socket, $json."\n");
    }
}
