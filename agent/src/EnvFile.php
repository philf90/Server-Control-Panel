<?php

declare(strict_types=1);

namespace CloudSrv\Agent;

/**
 * Die Umgebungsdatei des Panels — lesen und schreiben.
 *
 * Eine eigene Klasse, weil hier zwei Zusagen hängen, die man prüfen können
 * muss: Bestehende Werte überleben einen zweiten Lauf (sonst wechselt der
 * APP_KEY und die Datenbank ist unlesbar), und die Datei ist nie für alle
 * lesbar (sie enthält Passwort und Schlüssel).
 *
 * Der Pfad steht im Konstruktor und nicht als Konstante: Sonst ließe sich
 * keine der beiden Zusagen testen, ohne /etc zu beschreiben.
 */
final class EnvFile
{
    public function __construct(private readonly string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /** @return array<string,string> */
    public function read(): array
    {
        if (! is_readable($this->path)) {
            return [];
        }

        $values = [];

        foreach (file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $values[trim($name)] = trim(trim($value), "\"'");
        }

        return $values;
    }

    /** @param array<string,string> $values */
    public function write(array $values, string $group = 'cloudsrv'): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw AgentException::execFailed(sprintf('Verzeichnis %s ließ sich nicht anlegen.', $directory));
        }

        $text = "# Von 'cloudsrv setup' geschrieben. Enthält Schlüssel und Passwörter.\n"
            ."# Diese Datei überlebt jedes Update — das Auslieferungsverzeichnis nicht.\n\n";

        foreach ($values as $name => $value) {
            $text .= sprintf("%s=%s\n", $name, str_contains($value, ' ') ? '"'.$value.'"' : $value);
        }

        // Über eine Zwischendatei mit gesetzten Rechten und dann umbenennen:
        // Zwischen dem Anlegen und dem chmod stünde die Datei sonst kurz
        // lesbar da — mit dem Schlüssel darin.
        $temp = $this->path.'.neu';
        file_put_contents($temp, $text);
        chmod($temp, 0o640);

        if (function_exists('posix_getgrnam') && posix_getgrnam($group) !== false) {
            @chgrp($temp, $group);
        }

        rename($temp, $this->path);
    }
}
