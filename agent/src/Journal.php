<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Das Protokoll des Agenten.
 *
 * Es steht neben dem Protokoll der Anwendung, nicht an dessen Stelle: Die
 * Anwendung schreibt auf, *wer* etwas veranlasst hat, der Agent schreibt auf,
 * *was tatsächlich ausgeführt wurde* — mit der vollständigen Kommandozeile.
 * Wer beide vergleicht, sieht, ob am Panel vorbei gearbeitet wurde. Ein
 * einzelnes Protokoll könnte das nicht zeigen.
 *
 * Geschrieben wird als JSON je Zeile, weil logrotate und `tail -f` damit
 * umgehen können und ein Auswertungsskript nicht raten muss.
 */
final class Journal
{
    private ?string $request = null;

    /** @var array<string,mixed>|null */
    private ?array $origin = null;

    public function __construct(private readonly string $file) {}

    /** @param array<string,mixed>|null $origin */
    public function requestStarted(string $id, ?array $origin): void
    {
        $this->request = $id;
        $this->origin = $origin;
    }

    public function requestEnded(): void
    {
        $this->request = null;
        $this->origin = null;
    }

    /** @param array<string,mixed> $fields */
    public function write(string $kind, array $fields = []): void
    {
        $line = array_merge([
            'ts' => gmdate('Y-m-d\TH:i:s\Z'),
            'kind' => $kind,
            'request' => $this->request,
            'origin' => $this->origin,
        ], $fields);

        $json = json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return;
        }

        $handle = @fopen($this->file, 'a');
        if ($handle === false) {
            // Ein Agent, der nicht protokollieren kann, soll trotzdem laufen —
            // aber sichtbar: die Meldung geht an den Journald-Kanal von systemd.
            fwrite(STDERR, "Protokoll nicht schreibbar: {$this->file}\n");

            return;
        }

        flock($handle, LOCK_EX);
        fwrite($handle, $json."\n");
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /** @param list<string> $command */
    public function command(array $command, ?int $code, float $duration): void
    {
        $this->write('command', [
            'command' => $command,
            'code' => $code,
            'duration_ms' => (int) round($duration * 1000),
        ]);
    }
}
