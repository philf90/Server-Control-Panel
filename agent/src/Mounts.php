<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Welches Gerät trägt einen Pfad.
 *
 * **Warum das eine eigene Datei ist.** Die Frage stellt sich an zwei Stellen —
 * beim Setzen einer Quota und beim Auslesen des Verbrauchs — und sie muss an
 * beiden dieselbe Antwort geben. Stünde sie zweimal da, wäre der Tag absehbar,
 * an dem ein Betreiber einen eigenen Mount für `/var/www/vhosts` anlegt und
 * das Panel die Quota auf dem einen Gerät setzt und auf dem anderen nachsieht:
 * Der Verbrauch stünde dann dauerhaft auf null, und nichts daran sähe nach
 * einem Fehler aus.
 *
 * Gelesen aus `/proc/mounts` und nicht über `df`: keine Prozessgründung, keine
 * Ausgabe zum Zerlegen, und die Liste steht in derselben Form auf jedem
 * Linux-System.
 */
final class Mounts
{
    /**
     * Das Gerät, auf dem ein Pfad liegt — oder `null`.
     *
     * Der **längste** passende Einhängepunkt gewinnt. Ohne diese Regel fände
     * ein Pfad unter `/var/www/vhosts` immer `/`, weil `/` auf alles passt.
     */
    public static function deviceFor(string $path): ?string
    {
        $mounts = @file('/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($mounts === false) {
            return null;
        }

        return self::pick($mounts, $path);
    }

    /**
     * Dasselbe aus einer bereits gelesenen Liste.
     *
     * Getrennt, damit die Auswahlregel prüfbar ist, ohne /proc/mounts zu
     * fälschen — die Regel ist der Teil, der schiefgehen kann.
     *
     * @param  list<string>  $mounts  Zeilen im Format von /proc/mounts
     */
    public static function pick(array $mounts, string $path): ?string
    {
        $best = null;
        $bestLength = -1;

        foreach ($mounts as $line) {
            $parts = preg_split('/\s+/', $line) ?: [];

            // Nur echte Geräte. `tmpfs`, `proc` und `cgroup2` stehen ebenfalls
            // in der Liste und tragen keine Benutzerquota.
            if (count($parts) < 3 || ! str_starts_with($parts[0], '/')) {
                continue;
            }

            $point = stripcslashes($parts[1]);

            if ($point !== '/' && ! str_starts_with($path.'/', rtrim($point, '/').'/')) {
                continue;
            }

            if (strlen($point) > $bestLength) {
                $best = $parts[0];
                $bestLength = strlen($point);
            }
        }

        return $best;
    }
}
