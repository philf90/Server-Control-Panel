<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

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
    public static function unitName(mixed $value): string
    {
        $name = self::string($value, 'unit');

        if (! preg_match('/^[a-zA-Z0-9@._\-]{1,128}$/D', $name)) {
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
     * @param  list<string>  $allowedRoots
     */
    public static function pathInside(mixed $value, array $allowedRoots): string
    {
        $path = self::string($value, 'path');

        if (! str_starts_with($path, '/')) {
            throw AgentException::badRequest('Pfad muss absolut sein.', ['path' => $path]);
        }

        $real = realpath($path);
        if ($real === false) {
            throw new AgentException(AgentException::NOT_FOUND, 'Pfad existiert nicht.', ['path' => $path]);
        }

        foreach ($allowedRoots as $root) {
            $rootReal = realpath($root);
            if ($rootReal === false) {
                continue;
            }
            if ($real === $rootReal || str_starts_with($real, rtrim($rootReal, '/').'/')) {
                return $real;
            }
        }

        throw AgentException::denied('Pfad liegt außerhalb der erlaubten Verzeichnisse.');
    }

    /** @param list<string> $allowed */
    public static function enum(mixed $value, array $allowed, string $field): string
    {
        $s = self::string($value, $field);

        if (! in_array($s, $allowed, true)) {
            throw AgentException::badRequest(
                sprintf('Unzulässiger Wert für %s.', $field),
                [$field => $s, 'allowed' => $allowed],
            );
        }

        return $s;
    }

    /**
     * Eine ganze Zahl.
     *
     * Eine Ziffernfolge als Zeichenkette wird angenommen: Sie kommt so aus
     * einem Formular, das über JSON geht, und sie abzulehnen hiesse, jedem
     * Aufrufer eine Umwandlung aufzuerlegen, die hier einmal steht. Was nicht
     * angenommen wird, ist alles andere — kein `true`, kein `"12abc"`, keine
     * Gleitkommazahl, deren Nachkommastellen stillschweigend verschwänden.
     */
    public static function int(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?[0-9]{1,10}$/D', $value) === 1) {
            return (int) $value;
        }

        throw AgentException::badRequest(sprintf('%s muss eine ganze Zahl sein.', $field));
    }

    public static function string(mixed $value, string $field): string
    {
        if (! is_string($value)) {
            throw AgentException::badRequest(sprintf('%s muss eine Zeichenkette sein.', $field));
        }

        if ($value === '' || strlen($value) > 4096) {
            throw AgentException::badRequest(sprintf('%s ist leer oder zu lang.', $field));
        }

        // Ein Nullbyte trennt in C-Bibliotheken die Zeichenkette. Was danach
        // steht, sähe PHP noch und der Kernel nicht mehr.
        if (str_contains($value, "\0")) {
            throw AgentException::badRequest(sprintf('%s enthält ein Nullbyte.', $field));
        }

        return $value;
    }
}
