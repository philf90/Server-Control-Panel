<?php

declare(strict_types=1);

namespace CloudSrv\Agent;

/**
 * Prüfungen für alles, was von außen kommt.
 *
 * Jeder Wert, der später in einer Kommandozeile oder einem Dateipfad landet,
 * geht zuerst hier durch. Die Regel dahinter ist eine einzige: Es wird gegen
 * eine Positivliste geprüft, nie gegen eine Liste des Verbotenen. Wer
 * „gefährliche Zeichen" herausfiltert, hat immer eines vergessen.
 */
final class Guard
{
    /** systemd-Unit: Buchstaben, Ziffern, Punkt, Bindestrich, Unterstrich, @ — sonst nichts. */
    public static function unitName(mixed $wert): string
    {
        $name = self::string($wert, 'unit');

        if (! preg_match('/^[a-zA-Z0-9@._\-]{1,128}$/', $name)) {
            throw AgentException::badRequest('Unzulässiger Unit-Name.', ['unit' => $name]);
        }

        // Ein Punkt am Anfang oder „..“ irgendwo wäre ein Pfad und keine Unit.
        if (str_starts_with($name, '.') || str_contains($name, '..')) {
            throw AgentException::badRequest('Unzulässiger Unit-Name.', ['unit' => $name]);
        }

        return $name;
    }

    /**
     * Absoluter Pfad, aufgelöst, und danach nachweislich unterhalb eines der
     * erlaubten Verzeichnisse.
     *
     * Die Auflösung passiert VOR der Prüfung: Ein Symlink, der aus dem
     * erlaubten Bereich herauszeigt, ist sonst genau der Ausbruch, den diese
     * Methode verhindern soll.
     *
     * @param  list<string>  $erlaubteWurzeln
     */
    public static function pathInside(mixed $wert, array $erlaubteWurzeln): string
    {
        $pfad = self::string($wert, 'path');

        if (! str_starts_with($pfad, '/')) {
            throw AgentException::badRequest('Pfad muss absolut sein.', ['path' => $pfad]);
        }

        $echt = realpath($pfad);
        if ($echt === false) {
            throw new AgentException(AgentException::NOT_FOUND, 'Pfad existiert nicht.', ['path' => $pfad]);
        }

        foreach ($erlaubteWurzeln as $wurzel) {
            $wurzelEcht = realpath($wurzel);
            if ($wurzelEcht === false) {
                continue;
            }
            if ($echt === $wurzelEcht || str_starts_with($echt, rtrim($wurzelEcht, '/').'/')) {
                return $echt;
            }
        }

        throw AgentException::denied('Pfad liegt außerhalb der erlaubten Verzeichnisse.');
    }

    /** @param list<string> $erlaubt */
    public static function enum(mixed $wert, array $erlaubt, string $feld): string
    {
        $s = self::string($wert, $feld);

        if (! in_array($s, $erlaubt, true)) {
            throw AgentException::badRequest(
                sprintf('Unzulässiger Wert für %s.', $feld),
                [$feld => $s, 'erlaubt' => $erlaubt],
            );
        }

        return $s;
    }

    public static function string(mixed $wert, string $feld): string
    {
        if (! is_string($wert)) {
            throw AgentException::badRequest(sprintf('%s muss eine Zeichenkette sein.', $feld));
        }

        if ($wert === '' || strlen($wert) > 4096) {
            throw AgentException::badRequest(sprintf('%s ist leer oder zu lang.', $feld));
        }

        // Ein Nullbyte trennt in C-Bibliotheken die Zeichenkette. Was danach
        // steht, sähe PHP noch und der Kernel nicht mehr.
        if (str_contains($wert, "\0")) {
            throw AgentException::badRequest(sprintf('%s enthält ein Nullbyte.', $feld));
        }

        return $wert;
    }
}
