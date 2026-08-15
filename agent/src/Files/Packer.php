<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Files;

use SrvPanel\Agent\AgentException;
use ZipArchive;

/**
 * Einen oder mehrere Bäume zu einem Zip packen — innerhalb der Sandbox.
 *
 * **Verweise werden übersprungen und nicht verfolgt.** Ein Symlink nach
 * `/etc` liesse sich im Chroot ohnehin nicht auflösen; einer auf das eigene
 * `.ssh` dagegen schon, und dann läge der private Schlüssel des Kunden in einem
 * Archiv, das er vielleicht weitergibt. Ein Zip kann Verweise als Verweise
 * ablegen — hier werden sie ausgelassen und **gemeldet**, weil ein Archiv, das
 * stillschweigend etwas anderes enthält als der Baum, die schlechtere Antwort
 * ist.
 *
 * > Was ein Archiv nicht enthält, muss es sagen.
 *
 * ## Mehrere Quellen in einem Archiv
 *
 * Seit der Mehrfachauswahl (P6 Schritt 5h) kommen mehrere Quellen. Jede wird
 * relativ zu **ihrem eigenen** Elternverzeichnis abgelegt: Aus `/httpdocs/a.php`
 * wird `a.php`, aus `/tmp/bilder` wird `bilder/…`. Das ist die einzige Deutung,
 * die das Entpacken wieder umkehrt — ein gemeinsamer Präfix aller Quellen wäre
 * kürzer und hinge davon ab, was sonst noch ausgewählt war.
 *
 * **Zwei gleich heissende Quellen werden abgewiesen und nicht überschrieben.**
 * `/a/notizen` und `/b/notizen` ergäben beide `notizen/…`; ein Zip nimmt das
 * an, und beim Entpacken bleibt eines der beiden übrig. Ein Archiv, das
 * stillschweigend weniger enthält, als hineingelegt wurde, ist derselbe Fehler
 * wie ein Upload, der zwanzig Dateien unter einen Namen schreibt.
 */
final class Packer
{
    /** Wie viele Einträge in ein Archiv gehen. */
    public const MAX_ENTRIES = 20000;

    /**
     * @param  list<string>  $sources
     * @return array{entries: int, bytes: int, skipped: list<string>}
     */
    public static function zip(array $sources, string $target): array
    {
        $entries = 0;
        $bytes = 0;
        $skipped = [];

        /*
         * **Die Namensprüfung steht vor dem `open`, und zwar wegen der
         * Begründung — nicht wegen einer Datei.**
         *
         * Hier stand „sonst bleibt bei jedem Fehlversuch eine halbe Datei
         * liegen". **Gemessen am 15. August 2026: stimmt nicht.**
         * `ZipArchive::open()` mit `CREATE|EXCL` legt nichts an, und ein Archiv
         * ohne Eintrag schreibt libzip auch beim `close()` nicht.
         *
         * > **Ein Wert, den nur die Dokumentation kennt, ist eine Vermutung mit
         * > Fussnote.**
         *
         * Der echte Grund ist die Reihenfolge der Auskünfte: Liegt am Ziel schon
         * ein `auswahl.zip`, scheitert `open()` mit „liess sich nicht anlegen" —
         * und der Kunde bekäme diesen Satz, obwohl seine Auswahl **ausserdem**
         * zwei gleich heissende Einträge enthält. Er räumt dann das Ziel weg und
         * läuft in denselben Fehler.
         *
         * > **Von zwei Gründen gehört der genannt, den der nächste Versuch nicht
         * > von selbst behebt.**
         */
        $vergeben = [];

        foreach ($sources as $source) {
            $name = basename($source);

            if (isset($vergeben[$name])) {
                throw AgentException::badRequest(
                    sprintf('Zwei ausgewählte Einträge heissen %s — im Archiv bliebe nur einer übrig.', $name),
                    ['name' => $name],
                );
            }

            $vergeben[$name] = true;
        }

        $zip = new ZipArchive;

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw AgentException::denied('Das Archiv liess sich nicht anlegen.');
        }

        $add = static function (string $path, string $wurzel) use (&$add, $zip, &$entries, &$bytes, &$skipped): void {
            if ($entries > self::MAX_ENTRIES) {
                throw AgentException::badRequest('Der Baum hat mehr Einträge als in ein Archiv gehen.', [
                    'max' => self::MAX_ENTRIES,
                ]);
            }

            $relative = ltrim(substr($path, strlen($wurzel)), '/');

            if (is_link($path)) {
                $skipped[] = $relative;

                return;
            }

            if (is_dir($path)) {
                $zip->addEmptyDir($relative);

                foreach (@scandir($path) ?: [] as $child) {
                    if ($child !== '.' && $child !== '..') {
                        $add($path.'/'.$child, $wurzel);
                    }
                }

                return;
            }

            if (! is_readable($path)) {
                $skipped[] = $relative;

                return;
            }

            $zip->addFile($path, $relative);
            $entries++;
            $bytes += (int) @filesize($path);
        };

        foreach ($sources as $source) {
            $add($source, rtrim(dirname($source), '/'));
        }

        if (! $zip->close()) {
            @unlink($target);

            throw AgentException::execFailed(
                'Das Archiv liess sich nicht schreiben — möglicherweise ist das Kontingent erschöpft.',
            );
        }

        return ['entries' => $entries, 'bytes' => $bytes, 'skipped' => $skipped];
    }
}
