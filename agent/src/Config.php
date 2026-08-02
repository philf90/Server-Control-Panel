<?php

declare(strict_types=1);

namespace CloudSrv\Agent;

/**
 * Die Einstellungen des Agenten.
 *
 * Sie kommen aus einer Datei, die nur root schreiben darf, und nicht aus der
 * Umgebung: Umgebungsvariablen erbt jeder Kindprozess, und wer sie setzen
 * kann, hätte hier mitzureden.
 */
final class Config
{
    /** @param list<string> $pruefbareWurzeln */
    public function __construct(
        public readonly string $socket = '/run/cloudsrv/agent.sock',
        public readonly string $gruppe = 'cloudsrv',
        public readonly string $benutzer = 'cloudsrv',
        public readonly string $protokoll = '/var/log/cloudsrv/agent.log',
        public readonly array $pruefbareWurzeln = ['/etc/nginx', '/etc/php', '/etc/ssh', '/var/lib/cloudsrv'],
        public readonly int $maxKinder = 8,
        public readonly bool $erlaubeUnprivilegiert = false,
    ) {}

    public static function ausDatei(string $datei): self
    {
        $vorgabe = new self;

        if (! is_readable($datei)) {
            return $vorgabe;
        }

        $roh = json_decode((string) file_get_contents($datei), true);

        if (! is_array($roh)) {
            fwrite(STDERR, "Konfiguration {$datei} ist kein gültiges JSON — Vorgaben gelten.\n");

            return $vorgabe;
        }

        return new self(
            socket: is_string($roh['socket'] ?? null) ? $roh['socket'] : $vorgabe->socket,
            gruppe: is_string($roh['gruppe'] ?? null) ? $roh['gruppe'] : $vorgabe->gruppe,
            benutzer: is_string($roh['benutzer'] ?? null) ? $roh['benutzer'] : $vorgabe->benutzer,
            protokoll: is_string($roh['protokoll'] ?? null) ? $roh['protokoll'] : $vorgabe->protokoll,
            pruefbareWurzeln: self::wurzeln($roh['pruefbare_wurzeln'] ?? null) ?? $vorgabe->pruefbareWurzeln,
            maxKinder: is_int($roh['max_kinder'] ?? null) ? $roh['max_kinder'] : $vorgabe->maxKinder,
        );
    }

    /** @return list<string>|null */
    private static function wurzeln(mixed $roh): ?array
    {
        if (! is_array($roh)) {
            return null;
        }

        $sauber = [];

        foreach ($roh as $eintrag) {
            if (is_string($eintrag) && str_starts_with($eintrag, '/')) {
                $sauber[] = $eintrag;
            }
        }

        return $sauber === [] ? null : $sauber;
    }

    /** @param array<string,mixed> $ueberschreibungen */
    public function mit(array $ueberschreibungen): self
    {
        return new self(
            socket: $ueberschreibungen['socket'] ?? $this->socket,
            gruppe: $ueberschreibungen['gruppe'] ?? $this->gruppe,
            benutzer: $ueberschreibungen['benutzer'] ?? $this->benutzer,
            protokoll: $ueberschreibungen['protokoll'] ?? $this->protokoll,
            pruefbareWurzeln: $ueberschreibungen['pruefbareWurzeln'] ?? $this->pruefbareWurzeln,
            maxKinder: $ueberschreibungen['maxKinder'] ?? $this->maxKinder,
            erlaubeUnprivilegiert: $ueberschreibungen['erlaubeUnprivilegiert'] ?? $this->erlaubeUnprivilegiert,
        );
    }
}
