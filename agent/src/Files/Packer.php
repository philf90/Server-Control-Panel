<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Files;

use SrvPanel\Agent\AgentException;
use ZipArchive;

/**
 * Einen Baum zu einem Zip packen — innerhalb der Sandbox.
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
 */
final class Packer
{
    /** Wie viele Einträge in ein Archiv gehen. */
    public const MAX_ENTRIES = 20000;

    /**
     * @return array{entries: int, bytes: int, skipped: list<string>}
     */
    public static function zip(string $source, string $target): array
    {
        $zip = new ZipArchive;

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw AgentException::denied('Das Archiv liess sich nicht anlegen.');
        }

        $entries = 0;
        $bytes = 0;
        $skipped = [];

        $wurzel = rtrim(dirname($source), '/');

        $add = static function (string $path) use (&$add, $zip, $wurzel, &$entries, &$bytes, &$skipped): void {
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
                        $add($path.'/'.$child);
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

        $add($source);

        if (! $zip->close()) {
            @unlink($target);

            throw AgentException::execFailed(
                'Das Archiv liess sich nicht schreiben — möglicherweise ist das Kontingent erschöpft.',
            );
        }

        return ['entries' => $entries, 'bytes' => $bytes, 'skipped' => $skipped];
    }
}
