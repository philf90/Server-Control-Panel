<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Files;

/**
 * Ein Eintrag im Dateibaum, so wie die Oberfläche ihn bekommt.
 *
 * **Er steht an einer Stelle, weil acht Operationen ihn erzeugen.** Auflisten,
 * Lesen, Schreiben, Anlegen, Verschieben und Kopieren melden alle „so sieht es
 * jetzt aus"; acht abgeschriebene Fassungen wären acht Gelegenheiten, dass eine
 * davon die Zeitzone anders rechnet oder den Verweis vergisst.
 *
 * **Was diese Klasse absichtlich nicht tut: aufhübschen.** Grössen bleiben
 * Bytes, Zeiten bleiben Unix-Zeitstempel in UTC. Die Anzeigezeitzone ist Sache
 * von `App\Support\Time\Clock` (`docs/40`), und ein Agent, der sie mitrechnete,
 * wäre die zweite Stelle, an der sie steht — die zweite ist die, die veraltet.
 *
 * **Und Verweise werden gemeldet, nicht aufgelöst.** Ein Symlink im
 * Verzeichnis des Kunden ist eine Tatsache und kein Fehler; im Chroot kann er
 * ohnehin nur auf etwas zeigen, das dem Kunden gehört. Die Oberfläche soll ihn
 * als das zeigen, was er ist — `lstat` und nicht `stat`.
 */
final class Entry
{
    /**
     * Ein Eintrag, gelesen ohne dem Verweis zu folgen.
     *
     * `$path` ist der Pfad im Chroot; diese Methode läuft ausschliesslich
     * innerhalb der {@see Workspace::run()}.
     *
     * @return array<string,mixed>|null `null`, wenn es den Eintrag nicht gibt.
     */
    public static function of(string $path): ?array
    {
        $stat = @lstat($path);

        if ($stat === false) {
            return null;
        }

        $mode = $stat['mode'];
        $link = ($mode & 0o170000) === 0o120000;
        $dir = ! $link && ($mode & 0o170000) === 0o040000;

        return [
            'name' => basename($path),
            'path' => $path,
            'type' => $link ? 'link' : ($dir ? 'directory' : 'file'),

            // Bei einem Verweis die Grösse des Verweises und nicht die seines
            // Ziels — sonst zeigt die Liste eine Zahl, die zu einer anderen
            // Datei gehört.
            'size' => $link || $dir ? 0 : $stat['size'],
            'mode' => $mode & 0o7777,
            'modified_at' => $stat['mtime'],
            'uid' => $stat['uid'],
            'gid' => $stat['gid'],

            // Das Ziel eines Verweises, unverändert. Es kann ins Leere zeigen —
            // im Chroot tut es das sogar meistens, wenn der Kunde ihn von
            // aussen angelegt hat —, und genau das soll die Oberfläche sagen
            // können.
            'target' => $link ? (@readlink($path) ?: null) : null,

            // **Aus der Sicht dessen, der es gleich versuchen wird.** Die
            // Sandbox läuft als der Kunde, also beantworten `is_readable` und
            // `is_writable` hier genau die Frage, die die Oberfläche stellt —
            // und nicht die, die root beantworten würde.
            'readable' => @is_readable($path),
            'writable' => @is_writable($path),
        ];
    }
}
