<?php

declare(strict_types=1);

namespace CloudSrv\Agent;

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
    private ?string $vorgang = null;

    /** @var array<string,mixed>|null */
    private ?array $urheber = null;

    public function __construct(private readonly string $datei) {}

    /** @param array<string,mixed>|null $urheber */
    public function vorgangBeginnt(string $id, ?array $urheber): void
    {
        $this->vorgang = $id;
        $this->urheber = $urheber;
    }

    public function vorgangEndet(): void
    {
        $this->vorgang = null;
        $this->urheber = null;
    }

    /** @param array<string,mixed> $felder */
    public function schreibe(string $art, array $felder = []): void
    {
        $zeile = array_merge([
            'ts' => gmdate('Y-m-d\TH:i:s\Z'),
            'art' => $art,
            'vorgang' => $this->vorgang,
            'urheber' => $this->urheber,
        ], $felder);

        $json = json_encode($zeile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return;
        }

        $griff = @fopen($this->datei, 'a');
        if ($griff === false) {
            // Ein Agent, der nicht protokollieren kann, soll trotzdem laufen —
            // aber sichtbar: die Meldung geht an den Journald-Kanal von systemd.
            fwrite(STDERR, "Protokoll nicht schreibbar: {$this->datei}\n");

            return;
        }

        flock($griff, LOCK_EX);
        fwrite($griff, $json."\n");
        flock($griff, LOCK_UN);
        fclose($griff);
    }

    /** @param list<string> $befehl */
    public function befehl(array $befehl, ?int $code, float $dauer): void
    {
        $this->schreibe('befehl', [
            'befehl' => $befehl,
            'code' => $code,
            'dauer_ms' => (int) round($dauer * 1000),
        ]);
    }
}
