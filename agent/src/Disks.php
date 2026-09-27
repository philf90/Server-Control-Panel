<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Die Datenträger des Servers und wie voll sie sind.
 *
 * ## Warum das eine eigene Datei ist
 *
 * Zwei Stellen fragen danach: `system.info` für die Übersicht und
 * `system.filesystems` für die Prüfung „Platte voll" (`docs/136`). Stünde die
 * Auswahl zweimal da, zeigte die Übersicht eines Tages eine Platte, über die
 * die Prüfung schweigt — oder andersherum.
 *
 * ## Je Gerät eine Zeile und nicht je Einhängepunkt
 *
 * **Bis zum 27. September 2026 unterschied `SystemInfo::filesystems()` nach dem
 * Einhängepunkt**, und das war falsch, ohne dass es jemand gesehen hat. Der
 * Agent läuft mit `PrivateTmp=yes`; systemd hängt dafür zwei private
 * Verzeichnisse der Wurzel über `/tmp` und `/var/tmp`, und in `/proc/mounts`
 * stehen sie als eigene Einhängungen desselben Geräts. Gemessen in einer Unit
 * unter echtem systemd (`docs/136 §3` M4): `/`, `/tmp` und `/var/tmp`, alle
 * `/dev/vda`, alle 91,6 % — direkt gerufen sah derselbe Leser nur `/`.
 *
 * > **Ein Nachbau, der das Werkzeug direkt ruft, misst ohne die Sandbox der
 * > Einheit, unter der es auf dem Server läuft.**
 *
 * Tragen zwei Einhängepunkte dasselbe Gerät, bleibt deshalb **der kürzeste**:
 * `/` vor `/tmp`, und bei gleicher Länge der, der in `/proc/mounts` zuerst
 * steht. Es ist derselbe Platz — zwei Zeilen dafür wären zwei Befunde über eine
 * Platte.
 *
 * ## Die Einhängepunkte stehen maskiert da
 *
 * Der Kernel schreibt Leerzeichen, Tabulator, Zeilenumbruch und den Rückstrich
 * als Oktalfolge (`\040` …). Aufgelöst wird mit `stripcslashes()` wie in
 * {@see Mounts} — bis hierher ersetzte `SystemInfo` nur `\040`, und ein
 * Einhängepunkt mit Tabulator wäre als Pfad angekommen, den es nicht gibt.
 */
final class Disks
{
    /**
     * Die Arten, auf denen Daten liegen.
     *
     * Was aus `/proc`, `/sys`, `tmpfs` und Ähnlichem kommt, ist kein
     * Datenträger, sondern eine Sicht des Kernels. Eine Warnung „98 % voll"
     * über ein `devtmpfs` wäre ein Fehlalarm, den man sich nach dem zweiten Mal
     * abgewöhnt — und dann übersieht man den echten.
     */
    public const TYPES = ['ext2', 'ext3', 'ext4', 'xfs', 'btrfs', 'zfs', 'f2fs', 'jfs', 'reiserfs', 'vfat'];

    /**
     * Die Datenträger aus den Zeilen von `/proc/mounts` — je Gerät einer.
     *
     * @param  list<string>  $lines
     * @return list<array{mount: string, device: string, type: string}>
     */
    public static function storage(array $lines): array
    {
        $kandidaten = [];

        foreach ($lines as $position => $line) {
            $spalten = preg_split('/\s+/', trim($line)) ?: [];

            if (count($spalten) < 3 || ! in_array($spalten[2], self::TYPES, true)) {
                continue;
            }

            $kandidaten[] = [
                'mount' => stripcslashes($spalten[1]),
                'device' => $spalten[0],
                'type' => $spalten[2],
                'position' => $position,
            ];
        }

        // Der kürzeste Einhängepunkt je Gerät, bei gleicher Länge der frühere.
        usort($kandidaten, static fn (array $a, array $b): int => [strlen($a['mount']), $a['position']] <=> [strlen($b['mount']), $b['position']]);

        $zeilen = [];

        foreach ($kandidaten as $kandidat) {
            $zeilen[$kandidat['device']] ??= [
                'mount' => $kandidat['mount'],
                'device' => $kandidat['device'],
                'type' => $kandidat['type'],
            ];
        }

        $zeilen = array_values($zeilen);
        usort($zeilen, static fn (array $a, array $b): int => strcmp($a['mount'], $b['mount']));

        return $zeilen;
    }

    /**
     * Belegung je Datenträger.
     *
     * **`free` ist der Platz ohne die Reserve von root**, denn
     * `disk_free_space()` liefert `f_bavail` — gemessen aufs Byte (`docs/136 §3`
     * M1). Die Reserve zählt damit als belegt, und 100 % heisst genau: Kein
     * Kunde, keine Datenbank und kein Pool kann mehr schreiben (M2). `df`
     * rechnet anders und zeigt in der Mitte bis zu die Reserve weniger; am Rand
     * sind sich beide einig.
     *
     * Ein Einhängepunkt, den `statvfs` nicht beantwortet, fehlt in der Liste —
     * eine Zeile mit geratenen Zahlen wäre schlimmer als keine.
     *
     * @param  list<string>  $lines  Zeilen im Format von `/proc/mounts`
     * @return list<array{mount: string, device: string, type: string, total: int, free: int, used: int, percent: float}>
     */
    public static function usage(array $lines): array
    {
        $zeilen = [];

        foreach (self::storage($lines) as $platte) {
            $total = @disk_total_space($platte['mount']);
            $free = @disk_free_space($platte['mount']);

            if ($total === false || $free === false || $total <= 0) {
                continue;
            }

            $used = $total - $free;

            $zeilen[] = [
                ...$platte,
                'total' => (int) $total,
                'free' => (int) $free,
                'used' => (int) $used,
                'percent' => round($used / $total * 100, 1),
            ];
        }

        return $zeilen;
    }

    /**
     * Die Inodes je Einhängepunkt aus `stat -f -c '%c %d %n' -- …`.
     *
     * PHP hat dafür keine Funktion; `stat -f` beantwortet es für alle Pfade in
     * einem Aufruf (`docs/136 §3` M3 und M6).
     *
     * **Gelesen wird die Ausgabe und nicht der Rückgabewert.** Fehlt ein Pfad,
     * endet `stat` mit rc=1 und druckt die übrigen Zeilen trotzdem (M6); ein
     * Leser, der am Rückgabewert aufgibt, verlöre alle Platten für einen
     * fehlenden Pfad.
     *
     * **Eine Platte ohne Inodezahl hat keine Zeile.** btrfs meldet `0` und
     * vergibt Inodes nach Bedarf; „0 von 0" wäre ein geteilt durch null, und
     * „0 %" eine Behauptung über etwas, das es dort nicht gibt.
     *
     * @return array<string, array{total: int, free: int, percent: float}>
     */
    public static function inodes(Result $stat): array
    {
        $inodes = [];

        foreach (explode("\n", $stat->stdout) as $zeile) {
            if (preg_match('/^(\d+) (\d+) (.+)$/D', $zeile, $treffer) !== 1) {
                continue;
            }

            $total = (int) $treffer[1];
            $free = (int) $treffer[2];

            if ($total <= 0 || $free > $total) {
                continue;
            }

            $inodes[$treffer[3]] = [
                'total' => $total,
                'free' => $free,
                'percent' => round(($total - $free) / $total * 100, 1),
            ];
        }

        return $inodes;
    }
}
