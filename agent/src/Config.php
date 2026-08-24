<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Die Einstellungen des Agenten.
 *
 * Sie kommen aus einer Datei, die nur root schreiben darf, und nicht aus der
 * Umgebung: Umgebungsvariablen erbt jeder Kindprozess, und wer sie setzen
 * kann, hätte hier mitzureden.
 */
final class Config
{
    /**
     * Wohin der Agent protokolliert, wenn die Konfiguration nichts anderes
     * sagt.
     *
     * **Als Konstante, weil {@see Logs} darauf zeigt.** Eine Vorgabe in einer
     * Parameterliste lässt sich von aussen nicht lesen, ohne ein Objekt zu
     * bauen — und ein zweites Mal hingeschriebener Pfad ist genau der Verweis,
     * den in diesem Repo nichts prüft.
     */
    public const DEFAULT_LOG_FILE = '/var/log/srvpanel/agent.log';

    /** @param list<string> $configRoots */
    public function __construct(
        public readonly string $socket = '/run/srvpanel/agent.sock',
        public readonly string $group = 'srvpanel',
        public readonly string $user = 'srvpanel',
        public readonly string $logFile = self::DEFAULT_LOG_FILE,
        public readonly array $configRoots = ['/etc/nginx', '/etc/php', '/etc/ssh', '/var/lib/srvpanel'],
        public readonly int $maxChildren = 8,
        public readonly bool $allowUnprivileged = false,
    ) {}

    public static function fromFile(string $file): self
    {
        $defaults = new self;

        if (! is_readable($file)) {
            return $defaults;
        }

        $raw = json_decode((string) file_get_contents($file), true);

        if (! is_array($raw)) {
            fwrite(STDERR, "Konfiguration {$file} ist kein gültiges JSON — Vorgaben gelten.\n");

            return $defaults;
        }

        return new self(
            socket: is_string($raw['socket'] ?? null) ? $raw['socket'] : $defaults->socket,
            group: is_string($raw['group'] ?? null) ? $raw['group'] : $defaults->group,
            user: is_string($raw['user'] ?? null) ? $raw['user'] : $defaults->user,
            logFile: is_string($raw['log'] ?? null) ? $raw['log'] : $defaults->logFile,
            configRoots: self::roots($raw['config_roots'] ?? null) ?? $defaults->configRoots,
            maxChildren: is_int($raw['max_children'] ?? null) ? $raw['max_children'] : $defaults->maxChildren,
        );
    }

    /** @return list<string>|null */
    private static function roots(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $clean = [];

        foreach ($raw as $entry) {
            if (is_string($entry) && str_starts_with($entry, '/')) {
                $clean[] = $entry;
            }
        }

        return $clean === [] ? null : $clean;
    }

    /** @param array<string,mixed> $overrides */
    public function with(array $overrides): self
    {
        return new self(
            socket: $overrides['socket'] ?? $this->socket,
            group: $overrides['group'] ?? $this->group,
            user: $overrides['user'] ?? $this->user,
            logFile: $overrides['protocol'] ?? $this->logFile,
            configRoots: $overrides['configRoots'] ?? $this->configRoots,
            maxChildren: $overrides['maxKinder'] ?? $this->maxChildren,
            allowUnprivileged: $overrides['allowUnprivileged'] ?? $this->allowUnprivileged,
        );
    }
}
